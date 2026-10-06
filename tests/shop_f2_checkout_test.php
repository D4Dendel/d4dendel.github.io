<?php
declare(strict_types=1);

require __DIR__ . '/../api/config.php';

const SHOP_F2_TEST_API = 'http://127.0.0.1/dyndel-portfolio/api/index.php';

function f2_request(string $action, string $method = 'POST', array $data = []): array
{
    $curl = curl_init(SHOP_F2_TEST_API . '?action=' . rawurlencode($action));
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 10,
    ]);
    if ($method !== 'GET') curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
    $body = curl_exec($curl);
    if ($body === false) throw new RuntimeException('HTTP request failed: ' . curl_error($curl));
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return [$status, json_decode($body, true, 32, JSON_THROW_ON_ERROR)];
}

function f2_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function f2_order_payload(array $items, string $attemptToken, array $overrides = []): array
{
    return array_merge([
        'attemptToken' => $attemptToken,
        'items' => json_encode($items, JSON_THROW_ON_ERROR),
        'customerName' => 'Checkout Guest',
        'customerEmail' => 'guest@example.com',
        'customerPhone' => '+66 81 234 5678',
    ], $overrides);
}

$pdo = db();
$run = bin2hex(random_bytes(4));
$checks = 0;
$productIds = [];
$orderIds = [];
$zoneIds = [];
$before = [
    'products' => (int)$pdo->query('SELECT COUNT(*) FROM shop_products')->fetchColumn(),
    'orders' => (int)$pdo->query('SELECT COUNT(*) FROM shop_orders')->fetchColumn(),
    'items' => (int)$pdo->query('SELECT COUNT(*) FROM shop_order_items')->fetchColumn(),
    'zones' => (int)$pdo->query('SELECT COUNT(*) FROM shop_shipping_zones')->fetchColumn(),
    'countries' => (int)$pdo->query('SELECT COUNT(*) FROM shop_shipping_zone_countries')->fetchColumn(),
    'methods' => (int)$pdo->query('SELECT COUNT(*) FROM shop_shipping_methods')->fetchColumn(),
    'stock' => (string)$pdo->query("SELECT GROUP_CONCAT(CONCAT(id, ':', stock) ORDER BY id) FROM shop_products")->fetchColumn(),
];
$autoIncrements = [];
$autoStmt = $pdo->query("SELECT TABLE_NAME, AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('shop_products','shop_orders','shop_order_items','shop_shipping_zones','shop_shipping_methods')");
foreach ($autoStmt->fetchAll() as $row) $autoIncrements[$row['TABLE_NAME']] = $row['AUTO_INCREMENT'] ?? '1';

try {
    $zoneStmt = $pdo->prepare("INSERT INTO shop_shipping_zones (code, name, is_rest_of_world, enabled, sort_order) VALUES (?, 'F2 Test Thailand', 0, 1, 1)");
    $zoneStmt->execute(["test-f2-th-{$run}"]);
    $zoneId = (int)$pdo->lastInsertId();
    $zoneIds[] = $zoneId;
    $pdo->prepare("INSERT INTO shop_shipping_zone_countries (zone_id, country_code) VALUES (?, 'TH')")->execute([$zoneId]);
    $methodStmt = $pdo->prepare("INSERT INTO shop_shipping_methods (zone_id, code, name, price, currency, estimated_delivery_min, estimated_delivery_max, estimated_delivery_unit, enabled, sort_order) VALUES (?, ?, 'F2 Test International', 12.00, 'USD', 7, 14, 'business_days', 1, 1)");
    $methodStmt->execute([$zoneId, "test-f2-standard-{$run}"]);
    $methodId = (int)$pdo->lastInsertId();

    $productStmt = $pdo->prepare(
        "INSERT INTO shop_products
         (sku, slug, title, short_description, description, product_type, image_url, price, sale_price, stock,
          publication_status, storefront_visible, show_when_sold_out, featured, sort_order, purchase_action, external_url, active)
         VALUES (?, ?, ?, '', ?, ?, ?, ?, ?, ?, 'published', 1, 1, 0, 9900, ?, ?, 1)"
    );
    $productStmt->execute(["TEST-F2-DIGITAL-{$run}", "test-f2-digital-{$run}", 'F2 Digital', 'Digital checkout fixture.', 'digital', 'img/icon.png', '10.00', '7.00', 5, 'internal', null]);
    $digitalId = (int)$pdo->lastInsertId();
    $productIds[] = $digitalId;
    $productStmt->execute(["TEST-F2-EXTERNAL-{$run}", "test-f2-external-{$run}", 'F2 External', 'External checkout fixture.', 'physical', 'img/icon.png', '9.00', null, 5, 'external', 'https://example.com/f2']);
    $externalId = (int)$pdo->lastInsertId();
    $productIds[] = $externalId;

    [$status] = f2_request('checkout-order', 'GET');
    f2_expect($status === 405, 'Checkout order endpoint accepted GET.');
    [$status] = f2_request('checkout-order', 'POST', f2_order_payload([], bin2hex(random_bytes(16))));
    f2_expect($status === 422, 'Checkout order accepted an empty Cart.');
    [$status] = f2_request('checkout-order', 'POST', f2_order_payload([['id' => $externalId, 'quantity' => 1]], bin2hex(random_bytes(16))));
    f2_expect($status === 422, 'Checkout order accepted an external product.');
    [$status] = f2_request('checkout-order', 'POST', f2_order_payload([['id' => $digitalId, 'quantity' => 6]], bin2hex(random_bytes(16))));
    f2_expect($status === 422, 'Checkout order accepted quantity above stock.');
    [$status] = f2_request('checkout-order', 'POST', f2_order_payload([['id' => $digitalId, 'quantity' => 1]], 'short-token'));
    f2_expect($status === 422, 'Checkout order accepted an invalid attempt token.');
    [$status] = f2_request('checkout-order', 'POST', f2_order_payload([['id' => $digitalId, 'quantity' => 1]], bin2hex(random_bytes(16)), ['customerEmail' => 'invalid']));
    f2_expect($status === 422, 'Checkout order accepted an invalid email.');
    $checks += 6;

    $digitalToken = bin2hex(random_bytes(16));
    $digitalPayload = f2_order_payload([['id' => $digitalId, 'quantity' => 2]], $digitalToken, ['clientTotal' => '0.01']);
    [$status, $digitalResponse] = f2_request('checkout-order', 'POST', $digitalPayload);
    f2_expect($status === 201 && ($digitalResponse['order']['prepared'] ?? false), 'Digital order was not prepared.');
    $digitalOrderId = (int)$digitalResponse['order']['id'];
    $orderIds[] = $digitalOrderId;
    f2_expect($digitalResponse['order']['total'] === '14.00' && $digitalResponse['order']['currency'] === 'USD', 'Digital order trusted a client total or used the wrong currency.');
    $digitalOrder = $pdo->query("SELECT order_origin, status, payment_status, shipping_required, shipping_amount, total, customer_name, customer_email, customer_phone FROM shop_orders WHERE id = {$digitalOrderId}")->fetch();
    f2_expect($digitalOrder === [
        'order_origin' => 'checkout_v2', 'status' => 'pending', 'payment_status' => 'unpaid',
        'shipping_required' => 0, 'shipping_amount' => '0.00', 'total' => '14.00',
        'customer_name' => 'Checkout Guest', 'customer_email' => 'guest@example.com', 'customer_phone' => '+66 81 234 5678',
    ], 'Digital order lifecycle/contact snapshot was incorrect.');
    $digitalItem = $pdo->query("SELECT product_name, product_type, currency, quantity, price, line_total FROM shop_order_items WHERE order_id = {$digitalOrderId}")->fetch();
    f2_expect($digitalItem === ['product_name' => 'F2 Digital', 'product_type' => 'digital', 'currency' => 'USD', 'quantity' => 2, 'price' => '7.00', 'line_total' => '14.00'], 'Digital item snapshot was incorrect.');
    f2_expect((int)$pdo->query("SELECT stock FROM shop_products WHERE id = {$digitalId}")->fetchColumn() === 5, 'Digital pending order changed stock.');
    $checks += 5;

    [$status, $duplicateResponse] = f2_request('checkout-order', 'POST', $digitalPayload);
    f2_expect($status === 201 && ($duplicateResponse['order']['duplicate'] ?? false) && (int)$duplicateResponse['order']['id'] === $digitalOrderId, 'Idempotent retry did not return the original order.');
    f2_expect((int)$pdo->query("SELECT COUNT(*) FROM shop_orders WHERE checkout_attempt_token = " . $pdo->quote($digitalToken))->fetchColumn() === 1, 'Idempotent retry created a duplicate order.');
    [$status] = f2_request('checkout-order', 'POST', f2_order_payload([['id' => $digitalId, 'quantity' => 1]], $digitalToken));
    f2_expect($status === 409, 'Reused attempt token accepted changed Checkout data.');
    $checks += 3;

    $physicalToken = bin2hex(random_bytes(16));
    [$status] = f2_request('checkout-order', 'POST', f2_order_payload([['id' => 1, 'quantity' => 1]], $physicalToken, ['countryCode' => 'US']));
    f2_expect($status === 422, 'Physical Checkout proceeded without an eligible method/address.');
    $physicalPayload = f2_order_payload([['id' => 1, 'quantity' => 1]], $physicalToken, [
        'countryCode' => 'TH', 'shippingMethodId' => (string)$methodId,
        'addressLine1' => '123 Test Road', 'addressLine2' => 'Studio 4', 'city' => 'Bangkok',
        'region' => 'Bangkok', 'postalCode' => '10110',
    ]);
    [$status, $physicalResponse] = f2_request('checkout-order', 'POST', $physicalPayload);
    f2_expect($status === 201 && $physicalResponse['order']['total'] === '30.00', 'Physical order total was not rebuilt authoritatively.');
    $physicalOrderId = (int)$physicalResponse['order']['id'];
    $orderIds[] = $physicalOrderId;
    $physicalOrder = $pdo->query("SELECT shipping_required, shipping_address_line1, shipping_address_line2, shipping_city, shipping_region, shipping_postal_code, shipping_country_code, shipping_method_id, shipping_method_name, shipping_estimate_min, shipping_estimate_max, shipping_estimate_unit, shipping_amount, status, payment_status FROM shop_orders WHERE id = {$physicalOrderId}")->fetch();
    f2_expect($physicalOrder === [
        'shipping_required' => 1, 'shipping_address_line1' => '123 Test Road', 'shipping_address_line2' => 'Studio 4',
        'shipping_city' => 'Bangkok', 'shipping_region' => 'Bangkok', 'shipping_postal_code' => '10110',
        'shipping_country_code' => 'TH', 'shipping_method_id' => $methodId, 'shipping_method_name' => 'F2 Test International',
        'shipping_estimate_min' => 7, 'shipping_estimate_max' => 14, 'shipping_estimate_unit' => 'business_days',
        'shipping_amount' => '12.00', 'status' => 'pending', 'payment_status' => 'unpaid',
    ], 'Physical address or shipping snapshot was incorrect.');
    $checks += 3;

    $mixedToken = bin2hex(random_bytes(16));
    [$status, $mixedResponse] = f2_request('checkout-order', 'POST', f2_order_payload([
        ['id' => 1, 'quantity' => 1], ['id' => $digitalId, 'quantity' => 1],
    ], $mixedToken, [
        'countryCode' => 'TH', 'shippingMethodId' => (string)$methodId,
        'addressLine1' => '55 Mixed Lane', 'city' => 'Bangkok', 'postalCode' => '10200',
    ]));
    f2_expect($status === 201 && $mixedResponse['order']['total'] === '37.00', 'Mixed Checkout total or shipping requirement was incorrect.');
    $mixedOrderId = (int)$mixedResponse['order']['id'];
    $orderIds[] = $mixedOrderId;
    f2_expect((int)$pdo->query("SELECT shipping_required FROM shop_orders WHERE id = {$mixedOrderId}")->fetchColumn() === 1, 'Mixed order did not require shipping.');
    f2_expect((int)$pdo->query("SELECT COUNT(*) FROM shop_order_items WHERE order_id = {$mixedOrderId}")->fetchColumn() === 2, 'Mixed order item snapshots were incomplete.');
    f2_expect((string)$pdo->query("SELECT GROUP_CONCAT(CONCAT(id, ':', stock) ORDER BY id) FROM shop_products")->fetchColumn() === $before['stock'] . ",{$digitalId}:5,{$externalId}:5", 'Pending orders changed product stock.');
    $checks += 4;
} finally {
    foreach ($orderIds as $orderId) $pdo->prepare('DELETE FROM shop_orders WHERE id = ?')->execute([$orderId]);
    foreach ($productIds as $productId) $pdo->prepare('DELETE FROM shop_products WHERE id = ?')->execute([$productId]);
    foreach ($zoneIds as $zoneId) $pdo->prepare('DELETE FROM shop_shipping_zones WHERE id = ?')->execute([$zoneId]);
    foreach ($autoIncrements as $table => $nextId) {
        if (preg_match('/\A[a-z_]+\z/', $table) && ctype_digit((string)$nextId)) $pdo->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = " . (int)$nextId);
    }
}

foreach (['products', 'orders', 'items', 'zones', 'countries', 'methods'] as $key) {
    $table = ['products' => 'shop_products', 'orders' => 'shop_orders', 'items' => 'shop_order_items', 'zones' => 'shop_shipping_zones', 'countries' => 'shop_shipping_zone_countries', 'methods' => 'shop_shipping_methods'][$key];
    f2_expect((int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() === $before[$key], "Final {$key} count changed.");
    $checks++;
}
f2_expect((string)$pdo->query("SELECT GROUP_CONCAT(CONCAT(id, ':', stock) ORDER BY id) FROM shop_products")->fetchColumn() === $before['stock'], 'Final stock fingerprint changed.');
$checks++;

echo "Shop F2 Checkout V1 tests passed: {$checks} assertions.\n";
