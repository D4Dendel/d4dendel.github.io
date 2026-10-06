<?php
declare(strict_types=1);

const SHOP_CURRENCY = 'USD';

/** @return array<int,int> product ID => requested quantity */
function shop_checkout_cart_quantities(mixed $rawItems): array
{
    $items = shop_json_list($rawItems, 'Cart items');
    if (!$items) json_response(['error' => 'Cart items cannot be empty.'], 422);

    $quantities = [];
    foreach ($items as $item) {
        if (!is_array($item) || array_is_list($item) || array_diff(array_keys($item), ['id', 'quantity'])) {
            json_response(['error' => 'Each Cart item must contain only an ID and quantity.'], 422);
        }
        $productId = shop_positive_integer($item['id'] ?? null, 'Product ID');
        $quantity = shop_positive_integer($item['quantity'] ?? null, 'Quantity');
        $combined = ($quantities[$productId] ?? 0) + $quantity;
        if ($combined > 4294967295) json_response(['error' => 'Cart quantity is too large.'], 422);
        $quantities[$productId] = $combined;
    }
    ksort($quantities, SORT_NUMERIC);
    return $quantities;
}

function shop_checkout_country_code(mixed $value): ?string
{
    if ($value === null || $value === '') return null;
    if (!is_string($value)) json_response(['error' => 'Country code must be text.'], 422);
    $countryCode = strtoupper(trim($value));
    if (!preg_match('/\A[A-Z]{2}\z/D', $countryCode)) {
        json_response(['error' => 'Country code must be a two-letter ISO country code.'], 422);
    }
    return $countryCode;
}

function shop_checkout_shipping_method_id(mixed $value): ?int
{
    if ($value === null || $value === '') return null;
    return shop_positive_integer($value, 'Shipping method ID');
}

/**
 * Rebuild a quote entirely from current server-side product and shipping data.
 * This function never creates an order, reserves inventory, or changes stock.
 */
function shop_checkout_quote(PDO $pdo, mixed $rawItems, mixed $rawCountryCode = null, mixed $rawShippingMethodId = null): array
{
    $quantities = shop_checkout_cart_quantities($rawItems);
    $countryCode = shop_checkout_country_code($rawCountryCode);
    $shippingMethodId = shop_checkout_shipping_method_id($rawShippingMethodId);

    $productStmt = $pdo->prepare(
        'SELECT product.id, product.sku, product.title, product.product_type,
                product.price, product.sale_price, product.stock,
                product.publication_status, product.storefront_visible,
                product.purchase_action, product.image_url,
                image.image_path, image.alt_text
         FROM shop_products AS product
         LEFT JOIN shop_product_images AS image
           ON image.id = (
               SELECT first_image.id
               FROM shop_product_images AS first_image
               WHERE first_image.product_id = product.id
               ORDER BY first_image.sort_order ASC, first_image.id ASC
               LIMIT 1
           )
         WHERE product.id = ?
         LIMIT 1'
    );

    $items = [];
    $subtotalCents = 0;
    $requiresShipping = false;
    foreach ($quantities as $productId => $quantity) {
        $productStmt->execute([$productId]);
        $product = $productStmt->fetch();
        if (!$product
            || $product['publication_status'] !== 'published'
            || (int)$product['storefront_visible'] !== 1
            || $product['purchase_action'] !== 'internal') {
            json_response(['error' => 'One Cart item is not eligible for Checkout.'], 422);
        }
        if ($quantity > (int)$product['stock']) {
            json_response(['error' => 'One Cart item is no longer available in the requested quantity.'], 422);
        }

        $regular = shop_money($product['price'], 'Stored regular price');
        $sale = $product['sale_price'] === null ? null : shop_money($product['sale_price'], 'Stored sale price');
        $unitCents = $sale !== null && $sale['cents'] > 0 && $sale['cents'] < $regular['cents']
            ? $sale['cents']
            : $regular['cents'];
        if ($unitCents > intdiv(SHOP_MAX_MONEY_CENTS - $subtotalCents, $quantity)) {
            json_response(['error' => 'Cart subtotal is too large.'], 422);
        }
        $lineCents = $unitCents * $quantity;
        $subtotalCents += $lineCents;
        $requiresShipping = $requiresShipping || $product['product_type'] === 'physical';
        $items[] = [
            'productId' => (int)$product['id'],
            'sku' => $product['sku'],
            'name' => $product['title'],
            'productType' => $product['product_type'],
            'quantity' => $quantity,
            'unitPrice' => shop_money_from_cents($unitCents),
            'lineTotal' => shop_money_from_cents($lineCents),
            'currency' => SHOP_CURRENCY,
            'image' => $product['image_path'] ?: $product['image_url'],
            'imageAlt' => $product['alt_text'] ?? '',
        ];
    }

    $quote = [
        'currency' => SHOP_CURRENCY,
        'items' => $items,
        'subtotal' => shop_money_from_cents($subtotalCents),
        'shippingRequired' => $requiresShipping,
        'shipping' => [
            'countryCode' => $requiresShipping ? $countryCode : null,
            'destinationSupported' => !$requiresShipping,
            'methods' => [],
            'selectedMethod' => null,
            'amount' => $requiresShipping ? null : '0.00',
        ],
        'tax' => '0.00',
        'discount' => '0.00',
        'total' => $requiresShipping ? null : shop_money_from_cents($subtotalCents),
    ];

    if (!$requiresShipping) {
        if ($shippingMethodId !== null) {
            json_response(['error' => 'Digital-only carts do not use a shipping method.'], 422);
        }
        return $quote;
    }

    if ($countryCode === null) {
        if ($shippingMethodId !== null) {
            json_response(['error' => 'Choose a destination before selecting a shipping method.'], 422);
        }
        return $quote;
    }

    $zoneStmt = $pdo->prepare(
        'SELECT zone.id, zone.code, zone.name
         FROM shop_shipping_zones AS zone
         LEFT JOIN shop_shipping_zone_countries AS country
           ON country.zone_id = zone.id AND country.country_code = ?
         WHERE zone.enabled = 1
           AND (country.country_code IS NOT NULL OR zone.is_rest_of_world = 1)
         ORDER BY (country.country_code IS NOT NULL) DESC, zone.sort_order ASC, zone.id ASC
         LIMIT 1'
    );
    $zoneStmt->execute([$countryCode]);
    $zone = $zoneStmt->fetch();
    if (!$zone) return $quote;

    $methodStmt = $pdo->prepare(
        'SELECT id, code, name, price, currency,
                estimated_delivery_min, estimated_delivery_max, estimated_delivery_unit
         FROM shop_shipping_methods
         WHERE zone_id = ? AND enabled = 1
         ORDER BY sort_order ASC, id ASC'
    );
    $methodStmt->execute([(int)$zone['id']]);
    $selectedMethod = null;
    foreach ($methodStmt->fetchAll() as $method) {
        if ($method['currency'] !== SHOP_CURRENCY) {
            json_response(['error' => 'Shipping configuration uses an unsupported currency.'], 500);
        }
        $price = shop_money($method['price'], 'Stored shipping price');
        $formatted = [
            'id' => (int)$method['id'],
            'code' => $method['code'],
            'name' => $method['name'],
            'price' => $price['value'],
            'currency' => SHOP_CURRENCY,
            'estimatedDelivery' => $method['estimated_delivery_min'] === null ? null : [
                'minimum' => (int)$method['estimated_delivery_min'],
                'maximum' => (int)$method['estimated_delivery_max'],
                'unit' => $method['estimated_delivery_unit'],
            ],
        ];
        $quote['shipping']['methods'][] = $formatted;
        if ($shippingMethodId === (int)$method['id']) {
            $selectedMethod = $formatted + ['priceCents' => $price['cents']];
        }
    }
    $quote['shipping']['destinationSupported'] = (bool)$quote['shipping']['methods'];

    if ($shippingMethodId === null) return $quote;
    if ($selectedMethod === null) {
        json_response(['error' => 'Shipping method is not eligible for this destination.'], 422);
    }
    if ($selectedMethod['priceCents'] > SHOP_MAX_MONEY_CENTS - $subtotalCents) {
        json_response(['error' => 'Order total is too large.'], 422);
    }

    $shippingCents = $selectedMethod['priceCents'];
    unset($selectedMethod['priceCents']);
    $quote['shipping']['selectedMethod'] = $selectedMethod;
    $quote['shipping']['amount'] = shop_money_from_cents($shippingCents);
    $quote['total'] = shop_money_from_cents($subtotalCents + $shippingCents);
    return $quote;
}

function shop_checkout_attempt_token(mixed $value): string
{
    if (!is_string($value)) json_response(['error' => 'Checkout attempt token must be text.'], 422);
    $token = trim($value);
    if (!preg_match('/\A[A-Za-z0-9-]{32,64}\z/D', $token)) {
        json_response(['error' => 'Checkout attempt token is invalid.'], 422);
    }
    return $token;
}

function shop_checkout_phone(mixed $value): ?string
{
    $phone = shop_optional_text($value, 'Phone', 40);
    if ($phone !== null && !preg_match('/\A[0-9+().\-\s]{5,40}\z/D', $phone)) {
        json_response(['error' => 'Enter a valid phone number.'], 422);
    }
    return $phone;
}

/** Create an unpaid Checkout V2 order from a freshly rebuilt quote. */
function shop_checkout_create_order(PDO $pdo, array $input): array
{
    $attemptToken = shop_checkout_attempt_token($input['attemptToken'] ?? null);
    $customerName = shop_text($input['customerName'] ?? null, 'Full name', 160, false);
    $emailValue = shop_text($input['customerEmail'] ?? null, 'Email', 190, false);
    $customerEmail = filter_var($emailValue, FILTER_VALIDATE_EMAIL);
    if ($customerEmail === false) json_response(['error' => 'Enter a valid email address.'], 422);
    $customerPhone = shop_checkout_phone($input['customerPhone'] ?? null);

    $countryCode = shop_checkout_country_code($input['countryCode'] ?? null);
    $shippingMethodId = shop_checkout_shipping_method_id($input['shippingMethodId'] ?? null);
    $addressLine1 = shop_optional_text($input['addressLine1'] ?? null, 'Address line 1', 255);
    $addressLine2 = shop_optional_text($input['addressLine2'] ?? null, 'Address line 2', 255);
    $city = shop_optional_text($input['city'] ?? null, 'City', 120);
    $region = shop_optional_text($input['region'] ?? null, 'State, province, or region', 120);
    $postalCode = shop_optional_text($input['postalCode'] ?? null, 'Postal or ZIP code', 32);

    $pdo->beginTransaction();
    try {
        $quote = shop_checkout_quote($pdo, $input['items'] ?? null, $countryCode, $shippingMethodId);
        if ($quote['shippingRequired']) {
            if ($countryCode === null || $addressLine1 === null || $city === null || $postalCode === null) {
                json_response(['error' => 'Complete the required shipping address fields.'], 422);
            }
            if (!$quote['shipping']['destinationSupported']) {
                json_response(['error' => 'Shipping is not available for this destination yet.'], 422);
            }
            if ($quote['shipping']['selectedMethod'] === null || $quote['total'] === null) {
                json_response(['error' => 'Choose an available shipping method.'], 422);
            }
        } elseif ($countryCode !== null || $shippingMethodId !== null || $addressLine1 !== null
            || $addressLine2 !== null || $city !== null || $region !== null || $postalCode !== null) {
            json_response(['error' => 'Digital-only orders do not use shipping details.'], 422);
        }

        $payloadHash = hash('sha256', json_encode([
            'customer' => [$customerName, $customerEmail, $customerPhone],
            'shipping' => [$countryCode, $addressLine1, $addressLine2, $city, $region, $postalCode, $shippingMethodId],
            'quote' => $quote,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $existingStmt = $pdo->prepare(
            'SELECT id, currency, status, payment_status, total, checkout_payload_hash
             FROM shop_orders WHERE checkout_attempt_token = ? LIMIT 1'
        );
        $existingStmt->execute([$attemptToken]);
        $existing = $existingStmt->fetch();
        if ($existing) {
            if (!hash_equals((string)$existing['checkout_payload_hash'], $payloadHash)) {
                $pdo->rollBack();
                json_response(['error' => 'This Checkout attempt was already used with different details. Refresh Checkout and try again.'], 409);
            }
            $pdo->commit();
            return [
                'id' => (int)$existing['id'],
                'currency' => $existing['currency'],
                'status' => $existing['status'],
                'paymentStatus' => $existing['payment_status'],
                'total' => $existing['total'],
                'prepared' => true,
                'duplicate' => true,
            ];
        }

        $selectedMethod = $quote['shipping']['selectedMethod'];
        $estimate = $selectedMethod['estimatedDelivery'] ?? null;
        $orderStmt = $pdo->prepare(
            'INSERT INTO shop_orders
             (customer_name, customer_email, customer_phone, customer_address, currency, shipping_required,
              shipping_address_line1, shipping_address_line2, shipping_city, shipping_region,
              shipping_postal_code, shipping_country_code, shipping_method_id, shipping_method_name,
              shipping_estimate_min, shipping_estimate_max, shipping_estimate_unit,
              subtotal, shipping_amount, tax_amount, discount_amount, total, status, order_origin,
              payment_status, checkout_attempt_token, checkout_payload_hash)
             VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, 0.00, ?, ?, ?, ?, ?, ?)'
        );
        $orderStmt->execute([
            $customerName, $customerEmail, $customerPhone, SHOP_CURRENCY,
            $quote['shippingRequired'] ? 1 : 0,
            $quote['shippingRequired'] ? $addressLine1 : null,
            $quote['shippingRequired'] ? $addressLine2 : null,
            $quote['shippingRequired'] ? $city : null,
            $quote['shippingRequired'] ? $region : null,
            $quote['shippingRequired'] ? $postalCode : null,
            $quote['shippingRequired'] ? $countryCode : null,
            $selectedMethod['id'] ?? null,
            $selectedMethod['name'] ?? null,
            $estimate['minimum'] ?? null,
            $estimate['maximum'] ?? null,
            $estimate['unit'] ?? null,
            $quote['subtotal'],
            $quote['shipping']['amount'],
            $quote['total'],
            'pending', 'checkout_v2', 'unpaid', $attemptToken, $payloadHash,
        ]);
        $orderId = (int)$pdo->lastInsertId();
        $itemStmt = $pdo->prepare(
            'INSERT INTO shop_order_items
             (order_id, product_id, product_sku, product_name, product_type, currency, quantity, price, line_total)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($quote['items'] as $item) {
            $itemStmt->execute([
                $orderId, $item['productId'], $item['sku'], $item['name'], $item['productType'],
                SHOP_CURRENCY, $item['quantity'], $item['unitPrice'], $item['lineTotal'],
            ]);
        }
        $pdo->commit();
        return [
            'id' => $orderId,
            'currency' => SHOP_CURRENCY,
            'status' => 'pending',
            'paymentStatus' => 'unpaid',
            'total' => $quote['total'],
            'prepared' => true,
            'duplicate' => false,
        ];
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (($error->errorInfo[1] ?? null) === 1062) {
            $existingStmt = $pdo->prepare(
                'SELECT id, currency, status, payment_status, total, checkout_payload_hash
                 FROM shop_orders WHERE checkout_attempt_token = ? LIMIT 1'
            );
            $existingStmt->execute([$attemptToken]);
            $existing = $existingStmt->fetch();
            if ($existing && hash_equals((string)$existing['checkout_payload_hash'], $payloadHash ?? '')) {
                return [
                    'id' => (int)$existing['id'], 'currency' => $existing['currency'],
                    'status' => $existing['status'], 'paymentStatus' => $existing['payment_status'],
                    'total' => $existing['total'], 'prepared' => true, 'duplicate' => true,
                ];
            }
        }
        error_log('Shop Checkout V2 order failed: ' . $error->getMessage());
        json_response(['error' => 'The order could not be prepared. Please try again.'], 500);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Unexpected Shop Checkout V2 failure: ' . $error->getMessage());
        json_response(['error' => 'The order could not be prepared. Please try again.'], 500);
    }
}
