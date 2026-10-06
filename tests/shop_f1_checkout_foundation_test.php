<?php
declare(strict_types=1);

require __DIR__ . '/../api/config.php';

const SHOP_F1_TEST_API = 'http://127.0.0.1/dyndel-portfolio/api/index.php';

function f1_request(string $action, string $method = 'POST', array $data = []): array
{
    $curl = curl_init(SHOP_F1_TEST_API . '?action=' . rawurlencode($action));
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => 10,
    ]);
    if ($method !== 'GET') curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
    $body = curl_exec($curl);
    if ($body === false) throw new RuntimeException('HTTP request failed: ' . curl_error($curl));
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    try {
        return [$status, json_decode($body, true, 32, JSON_THROW_ON_ERROR)];
    } catch (JsonException $error) {
        throw new RuntimeException("API returned invalid JSON ({$status}): {$body}", 0, $error);
    }
}

function f1_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function f1_cart(array $items): string
{
    return json_encode($items, JSON_THROW_ON_ERROR);
}

$pdo = db();
$token = bin2hex(random_bytes(4));
$checks = 0;
$productIds = [];
$zoneIds = [];

$before = [
    'products' => (int)$pdo->query('SELECT COUNT(*) FROM shop_products')->fetchColumn(),
    'orders' => (int)$pdo->query('SELECT COUNT(*) FROM shop_orders')->fetchColumn(),
    'items' => (int)$pdo->query('SELECT COUNT(*) FROM shop_order_items')->fetchColumn(),
    'zones' => (int)$pdo->query('SELECT COUNT(*) FROM shop_shipping_zones')->fetchColumn(),
    'methods' => (int)$pdo->query('SELECT COUNT(*) FROM shop_shipping_methods')->fetchColumn(),
    'stock' => (string)$pdo->query("SELECT GROUP_CONCAT(CONCAT(id, ':', stock) ORDER BY id) FROM shop_products")->fetchColumn(),
];
$autoIncrements = [];
$autoStmt = $pdo->prepare("SELECT TABLE_NAME, AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('shop_products','shop_orders','shop_order_items','shop_shipping_zones','shop_shipping_methods')");
$autoStmt->execute();
foreach ($autoStmt->fetchAll() as $row) $autoIncrements[$row['TABLE_NAME']] = $row['AUTO_INCREMENT'] ?? '1';

try {
    $zoneStmt = $pdo->prepare('INSERT INTO shop_shipping_zones (code, name, is_rest_of_world, enabled, sort_order) VALUES (?, ?, ?, 1, ?)');
    $zoneStmt->execute(["test-th-{$token}", 'Test Thailand', 0, 1]);
    $thailandZoneId = (int)$pdo->lastInsertId();
    $zoneIds[] = $thailandZoneId;
    $zoneStmt->execute(["test-world-{$token}", 'Test Other International', 1, 2]);
    $worldZoneId = (int)$pdo->lastInsertId();
    $zoneIds[] = $worldZoneId;
    $pdo->prepare('INSERT INTO shop_shipping_zone_countries (zone_id, country_code) VALUES (?, ?)')->execute([$thailandZoneId, 'TH']);

    $methodStmt = $pdo->prepare('INSERT INTO shop_shipping_methods (zone_id, code, name, price, currency, estimated_delivery_min, estimated_delivery_max, estimated_delivery_unit, enabled, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $methodStmt->execute([$thailandZoneId, "test-standard-{$token}", 'Test Thailand Standard', '3.50', 'USD', 2, 4, 'business_days', 1, 1]);
    $thailandMethodId = (int)$pdo->lastInsertId();
    $methodStmt->execute([$thailandZoneId, "test-disabled-{$token}", 'Test Disabled', '1.00', 'USD', 1, 2, 'business_days', 0, 2]);
    $disabledMethodId = (int)$pdo->lastInsertId();
    $methodStmt->execute([$worldZoneId, "test-international-{$token}", 'Test Standard International', '12.00', 'USD', 7, 14, 'business_days', 1, 1]);
    $worldMethodId = (int)$pdo->lastInsertId();

    $productStmt = $pdo->prepare(
        "INSERT INTO shop_products
         (sku, slug, title, short_description, description, category, product_type, image_url, price, sale_price, stock,
          publication_status, storefront_visible, show_when_sold_out, featured, sort_order, purchase_action, external_url, active)
         VALUES (?, ?, ?, '', ?, NULL, ?, ?, ?, ?, ?, 'published', 1, 1, 0, 9000, ?, ?, 1)"
    );
    $productStmt->execute([
        "TEST-F1-DIGITAL-{$token}", "test-f1-digital-{$token}", 'F1 Digital Snapshot', 'F1 digital fixture.', 'digital',
        'img/test/f1-digital.jpg', '10.00', '7.00', 5, 'internal', null,
    ]);
    $digitalId = (int)$pdo->lastInsertId();
    $productIds[] = $digitalId;
    $productStmt->execute([
        "TEST-F1-EXTERNAL-{$token}", "test-f1-external-{$token}", 'F1 External', 'F1 external fixture.', 'physical',
        'img/test/f1-external.jpg', '9.00', null, 5, 'external', 'https://example.com/f1-external',
    ]);
    $externalId = (int)$pdo->lastInsertId();
    $productIds[] = $externalId;
    $productStmt->execute([
        "TEST-F1-INQUIRY-{$token}", "test-f1-inquiry-{$token}", 'F1 Inquiry', 'F1 inquiry fixture.', 'physical',
        'img/test/f1-inquiry.jpg', '11.00', null, 5, 'inquiry', null,
    ]);
    $inquiryId = (int)$pdo->lastInsertId();
    $productIds[] = $inquiryId;

    [$status] = f1_request('checkout-quote', 'GET');
    f1_expect($status === 405, 'Checkout quote accepted a non-POST request.');
    [$status] = f1_request('checkout-quote', 'POST', ['items' => '{}']);
    f1_expect($status === 422, 'Checkout quote accepted a non-list Cart.');
    [$status] = f1_request('checkout-quote', 'POST', ['items' => '[]']);
    f1_expect($status === 422, 'Checkout quote accepted an empty Cart.');
    $checks += 3;

    [$status, $physical] = f1_request('checkout-quote', 'POST', ['items' => f1_cart([['id' => 1, 'quantity' => 1]])]);
    $physicalQuote = $physical['quote'] ?? [];
    f1_expect($status === 200 && $physicalQuote['currency'] === 'USD' && $physicalQuote['subtotal'] === '18.00', 'Physical Cart quote currency or subtotal was incorrect.');
    f1_expect($physicalQuote['shippingRequired'] === true && $physicalQuote['shipping']['countryCode'] === null && $physicalQuote['shipping']['amount'] === null && $physicalQuote['total'] === null, 'Physical Cart did not wait for destination and shipping method.');
    $checks += 2;

    [$status, $thailand] = f1_request('checkout-quote', 'POST', [
        'items' => f1_cart([['id' => 1, 'quantity' => 1]]),
        'countryCode' => 'th',
    ]);
    $thailandQuote = $thailand['quote'] ?? [];
    f1_expect($status === 200 && $thailandQuote['shipping']['countryCode'] === 'TH' && $thailandQuote['shipping']['destinationSupported'], 'Exact-country shipping zone was not selected.');
    f1_expect(count($thailandQuote['shipping']['methods']) === 1 && $thailandQuote['shipping']['methods'][0]['id'] === $thailandMethodId && $thailandQuote['shipping']['amount'] === null, 'Enabled Thailand methods were not listed correctly.');
    f1_expect($thailandQuote['shipping']['methods'][0]['estimatedDelivery'] === ['minimum' => 2, 'maximum' => 4, 'unit' => 'business_days'], 'Shipping estimate was not returned from the method.');
    $checks += 3;

    [$status, $selectedThailand] = f1_request('checkout-quote', 'POST', [
        'items' => f1_cart([['id' => 1, 'quantity' => 1]]),
        'countryCode' => 'TH',
        'shippingMethodId' => (string)$thailandMethodId,
    ]);
    $selectedThailandQuote = $selectedThailand['quote'] ?? [];
    f1_expect($status === 200 && $selectedThailandQuote['shipping']['amount'] === '3.50' && $selectedThailandQuote['total'] === '21.50', 'Thailand shipping cost or total was incorrect.');
    f1_expect($selectedThailandQuote['shipping']['selectedMethod']['name'] === 'Test Thailand Standard', 'Selected shipping method snapshot data was missing.');
    $checks += 2;

    [$status] = f1_request('checkout-quote', 'POST', [
        'items' => f1_cart([['id' => 1, 'quantity' => 1]]),
        'countryCode' => 'TH',
        'shippingMethodId' => (string)$disabledMethodId,
    ]);
    f1_expect($status === 422, 'Disabled shipping method was accepted.');
    [$status] = f1_request('checkout-quote', 'POST', [
        'items' => f1_cart([['id' => 1, 'quantity' => 1]]),
        'countryCode' => 'TH',
        'shippingMethodId' => (string)$worldMethodId,
    ]);
    f1_expect($status === 422, 'Shipping method from another zone was accepted.');
    $checks += 2;

    [$status, $international] = f1_request('checkout-quote', 'POST', [
        'items' => f1_cart([['id' => 1, 'quantity' => 1]]),
        'countryCode' => 'US',
        'shippingMethodId' => (string)$worldMethodId,
    ]);
    $internationalQuote = $international['quote'] ?? [];
    f1_expect($status === 200 && $internationalQuote['shipping']['amount'] === '12.00' && $internationalQuote['total'] === '30.00', 'Rest-of-world shipping quote was incorrect.');
    f1_expect($internationalQuote['shipping']['selectedMethod']['estimatedDelivery']['maximum'] === 14, 'International delivery estimate was incorrect.');
    $checks += 2;

    [$status, $digital] = f1_request('checkout-quote', 'POST', [
        'items' => f1_cart([['id' => $digitalId, 'quantity' => 2]]),
    ]);
    $digitalQuote = $digital['quote'] ?? [];
    f1_expect($status === 200 && $digitalQuote['subtotal'] === '14.00' && $digitalQuote['total'] === '14.00', 'Digital sale pricing or total was incorrect.');
    f1_expect($digitalQuote['shippingRequired'] === false && $digitalQuote['shipping']['amount'] === '0.00' && $digitalQuote['shipping']['methods'] === [], 'Digital-only Cart incorrectly required shipping.');
    f1_expect($digitalQuote['items'][0]['productType'] === 'digital' && $digitalQuote['items'][0]['unitPrice'] === '7.00', 'Digital item snapshot was incorrect.');
    $checks += 3;

    [$status] = f1_request('checkout-quote', 'POST', [
        'items' => f1_cart([['id' => $digitalId, 'quantity' => 1]]),
        'shippingMethodId' => (string)$worldMethodId,
    ]);
    f1_expect($status === 422, 'Digital-only Cart accepted a shipping method.');
    $checks++;

    [$status, $mixed] = f1_request('checkout-quote', 'POST', [
        'items' => f1_cart([['id' => 1, 'quantity' => 1], ['id' => $digitalId, 'quantity' => 1]]),
        'countryCode' => 'TH',
        'shippingMethodId' => (string)$thailandMethodId,
    ]);
    $mixedQuote = $mixed['quote'] ?? [];
    f1_expect($status === 200 && $mixedQuote['subtotal'] === '25.00' && $mixedQuote['shipping']['amount'] === '3.50' && $mixedQuote['total'] === '28.50', 'Mixed Cart total was incorrect.');
    f1_expect($mixedQuote['shippingRequired'] === true && array_column($mixedQuote['items'], 'productType') === ['physical', 'digital'], 'Mixed Cart product-type behavior was incorrect.');
    $checks += 2;

    foreach ([$externalId, $inquiryId] as $ineligibleId) {
        [$status] = f1_request('checkout-quote', 'POST', ['items' => f1_cart([['id' => $ineligibleId, 'quantity' => 1]])]);
        f1_expect($status === 422, 'External or inquiry product entered Checkout.');
        $checks++;
    }
    [$status] = f1_request('checkout-quote', 'POST', ['items' => f1_cart([['id' => $digitalId, 'quantity' => 6]])]);
    f1_expect($status === 422, 'Checkout quote accepted quantity above stock.');
    [$status] = f1_request('checkout-quote', 'POST', ['items' => f1_cart([['id' => 1, 'quantity' => 1, 'price' => '0.01']])]);
    f1_expect($status === 422, 'Checkout quote accepted client-provided price data.');
    [$status] = f1_request('checkout-quote', 'POST', ['items' => f1_cart([['id' => 1, 'quantity' => 1]]), 'countryCode' => 'USA']);
    f1_expect($status === 422, 'Checkout quote accepted an invalid country code.');
    $checks += 3;

    [$status, $duplicates] = f1_request('checkout-quote', 'POST', [
        'items' => f1_cart([['id' => 1, 'quantity' => 1], ['id' => 1, 'quantity' => 2]]),
    ]);
    f1_expect($status === 200 && $duplicates['quote']['items'][0]['quantity'] === 3 && $duplicates['quote']['subtotal'] === '54.00', 'Duplicate Cart IDs were not aggregated authoritatively.');
    $checks++;

    $fixtureIdList = implode(',', array_map('intval', $productIds));
    f1_expect((string)$pdo->query("SELECT GROUP_CONCAT(CONCAT(id, ':', stock) ORDER BY id) FROM shop_products WHERE id NOT IN ({$fixtureIdList})")->fetchColumn() === $before['stock'], 'Checkout quotes changed product stock.');
    f1_expect((int)$pdo->query('SELECT COUNT(*) FROM shop_orders')->fetchColumn() === $before['orders'], 'Checkout quotes created an order.');
    f1_expect((int)$pdo->query('SELECT COUNT(*) FROM shop_order_items')->fetchColumn() === $before['items'], 'Checkout quotes created order items.');
    $checks += 3;

    $pdo->beginTransaction();
    $orderStmt = $pdo->prepare(
        "INSERT INTO shop_orders
         (customer_name, customer_email, customer_address, currency, shipping_required, subtotal, shipping_amount,
          tax_amount, discount_amount, total, status, order_origin, payment_status)
         VALUES ('Snapshot Guest', 'snapshot@example.com', NULL, 'USD', 0, 7.00, 0.00, 0.00, 0.00, 7.00,
                 'pending', 'checkout_v2', 'pending')"
    );
    $orderStmt->execute();
    $snapshotOrderId = (int)$pdo->lastInsertId();
    $itemStmt = $pdo->prepare('INSERT INTO shop_order_items (order_id, product_id, product_sku, product_name, product_type, currency, quantity, price, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $itemStmt->execute([$snapshotOrderId, $digitalId, "TEST-F1-DIGITAL-{$token}", 'F1 Digital Snapshot', 'digital', 'USD', 1, '7.00', '7.00']);
    $pdo->prepare('UPDATE shop_products SET title = ?, price = ?, sale_price = NULL WHERE id = ?')->execute(['Changed after order', '20.00', $digitalId]);
    $snapshot = $pdo->query('SELECT product_name, price, line_total, currency FROM shop_order_items WHERE order_id = ' . $snapshotOrderId)->fetch();
    f1_expect($snapshot === ['product_name' => 'F1 Digital Snapshot', 'price' => '7.00', 'line_total' => '7.00', 'currency' => 'USD'], 'Order-item snapshot changed with mutable product data.');
    $lifecycle = $pdo->query('SELECT status, payment_status, shipping_required FROM shop_orders WHERE id = ' . $snapshotOrderId)->fetch();
    f1_expect($lifecycle === ['status' => 'pending', 'payment_status' => 'pending', 'shipping_required' => 0], 'Order and payment lifecycle states were not independent.');
    $pdo->rollBack();
    $checks += 2;

    $constraintFailed = false;
    $pdo->beginTransaction();
    try {
        $pdo->exec("INSERT INTO shop_orders (customer_name, customer_email, currency, shipping_required, subtotal, total, order_origin) VALUES ('Bad Physical', 'bad@example.com', 'USD', 1, 1.00, 1.00, 'checkout_v2')");
    } catch (PDOException) {
        $constraintFailed = true;
    }
    $pdo->rollBack();
    f1_expect($constraintFailed, 'Checkout V2 physical order was accepted without shipping snapshots.');
    $checks++;
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($productIds as $productId) {
        $pdo->prepare('DELETE FROM shop_products WHERE id = ?')->execute([$productId]);
    }
    foreach ($zoneIds as $zoneId) {
        $pdo->prepare('DELETE FROM shop_shipping_zones WHERE id = ?')->execute([$zoneId]);
    }
    foreach ($autoIncrements as $table => $nextId) {
        if (preg_match('/\A[a-z_]+\z/', $table) && ctype_digit((string)$nextId)) {
            $pdo->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = " . (int)$nextId);
        }
    }
}

f1_expect((int)$pdo->query('SELECT COUNT(*) FROM shop_products')->fetchColumn() === $before['products'], 'Product cleanup count mismatch.');
f1_expect((int)$pdo->query('SELECT COUNT(*) FROM shop_orders')->fetchColumn() === $before['orders'], 'Order cleanup count mismatch.');
f1_expect((int)$pdo->query('SELECT COUNT(*) FROM shop_order_items')->fetchColumn() === $before['items'], 'Order-item cleanup count mismatch.');
f1_expect((int)$pdo->query('SELECT COUNT(*) FROM shop_shipping_zones')->fetchColumn() === $before['zones'], 'Shipping-zone cleanup count mismatch.');
f1_expect((int)$pdo->query('SELECT COUNT(*) FROM shop_shipping_methods')->fetchColumn() === $before['methods'], 'Shipping-method cleanup count mismatch.');
f1_expect((string)$pdo->query("SELECT GROUP_CONCAT(CONCAT(id, ':', stock) ORDER BY id) FROM shop_products")->fetchColumn() === $before['stock'], 'Final product stock fingerprint changed.');
$checks += 6;

echo "Shop F1 checkout foundation tests passed: {$checks} assertions.\n";
