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
        'SELECT id, sku, title, product_type, price, sale_price, stock,
                publication_status, storefront_visible, purchase_action
         FROM shop_products
         WHERE id = ?
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
