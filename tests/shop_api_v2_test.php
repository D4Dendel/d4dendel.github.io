<?php
declare(strict_types=1);

require __DIR__ . '/../api/config.php';

const SHOP_TEST_API = 'http://127.0.0.1/dyndel-portfolio/api/index.php';

function test_request(string $action, string $method = 'GET', array $data = [], ?string $sessionId = null): array
{
    $url = SHOP_TEST_API . '?action=' . rawurlencode($action);
    if ($method === 'GET' && $data) $url .= '&' . http_build_query($data);
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => 10,
    ]);
    if ($sessionId !== null) curl_setopt($curl, CURLOPT_COOKIE, 'PHPSESSID=' . $sessionId);
    if ($method !== 'GET') curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
    $body = curl_exec($curl);
    if ($body === false) throw new RuntimeException('HTTP request failed: ' . curl_error($curl));
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    try {
        $json = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException("API returned invalid JSON ({$status}): {$body}", 0, $error);
    }
    return [$status, $json];
}

function test_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function test_product_fields(string $token, string $name, array $overrides = []): array
{
    return array_replace([
        'sku' => 'TEST-' . strtoupper($token) . '-' . strtoupper($name),
        'slug' => 'test-' . $token . '-' . $name,
        'title' => 'Test ' . ucfirst($name),
        'shortDescription' => 'Plain short description.',
        'description' => 'Plain full description.',
        'category' => '',
        'productType' => 'physical',
        'price' => '10.00',
        'salePrice' => '',
        'stock' => '5',
        'publicationStatus' => 'published',
        'storefrontVisible' => '1',
        'showWhenSoldOut' => '1',
        'featured' => '0',
        'sortOrder' => '1000',
        'purchaseAction' => 'internal',
        'externalUrl' => '',
        'images' => json_encode([
            ['path' => 'img/test/' . $token . '-' . $name . '.jpg', 'altText' => '', 'sortOrder' => 1],
        ], JSON_THROW_ON_ERROR),
        'manualBadges' => '[]',
    ], $overrides);
}

$pdo = db();
$token = bin2hex(random_bytes(4));
$sessionId = 'shopv2test' . $token;
$productIds = [];
$orderIds = [];
$checks = 0;

$fingerprint = static function () use ($pdo): string {
    return (string)$pdo->query("SELECT SHA2(GROUP_CONCAT(CONCAT_WS('|',id,sku,title,description,image_url,price,stock,active,created_at) ORDER BY id SEPARATOR '||'),256) FROM shop_products")->fetchColumn();
};
$beforeFingerprint = $fingerprint();
$beforeCounts = [
    'products' => (int)$pdo->query('SELECT COUNT(*) FROM shop_products')->fetchColumn(),
    'orders' => (int)$pdo->query('SELECT COUNT(*) FROM shop_orders')->fetchColumn(),
    'items' => (int)$pdo->query('SELECT COUNT(*) FROM shop_order_items')->fetchColumn(),
];
$autoIncrements = [];
$autoStmt = $pdo->prepare("SELECT TABLE_NAME, AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('shop_products','shop_product_images','shop_product_badges','shop_orders','shop_order_items')");
$autoStmt->execute();
foreach ($autoStmt->fetchAll() as $row) $autoIncrements[$row['TABLE_NAME']] = $row['AUTO_INCREMENT'];

session_id($sessionId);
session_start();
$_SESSION['admin_id'] = ADMIN_USERNAME;
session_write_close();

try {
    [$status] = test_request('admin-products');
    test_expect($status === 401, 'Unauthenticated Admin read was not rejected.');
    $checks++;
    [$status, $admin] = test_request('admin-products', 'GET', [], $sessionId);
    test_expect($status === 200 && count($admin['products'] ?? []) >= 3, 'Authenticated Admin read failed.');
    $checks++;

    $fixtures = [
        'internal' => test_product_fields($token, 'internal', ['salePrice' => '7.50', 'sortOrder' => '1100']),
        'sold' => test_product_fields($token, 'sold', [
            'stock' => '0', 'salePrice' => '8.00', 'featured' => '1', 'sortOrder' => '1000',
            'images' => json_encode([
                ['path' => 'img/test/' . $token . '-sold-primary.jpg', 'altText' => '', 'sortOrder' => 1],
                ['path' => 'img/test/' . $token . '-sold-hover.jpg', 'altText' => 'Hover image', 'sortOrder' => 2],
            ], JSON_THROW_ON_ERROR),
            'manualBadges' => json_encode([['label' => 'Limited', 'sortOrder' => 1]], JSON_THROW_ON_ERROR),
        ]),
        'sold-hidden' => test_product_fields($token, 'sold-hidden', ['stock' => '0', 'showWhenSoldOut' => '0']),
        'hidden' => test_product_fields($token, 'hidden', ['storefrontVisible' => '0']),
        'draft' => test_product_fields($token, 'draft', ['publicationStatus' => 'draft', 'storefrontVisible' => '1']),
        'external' => test_product_fields($token, 'external', ['storefrontVisible' => '0', 'purchaseAction' => 'external', 'externalUrl' => 'https://example.com/product']),
        'inquiry' => test_product_fields($token, 'inquiry', ['storefrontVisible' => '0', 'purchaseAction' => 'inquiry']),
    ];
    $created = [];
    foreach ($fixtures as $key => $fields) {
        [$status, $body] = test_request('product', 'POST', $fields, $sessionId);
        test_expect($status === 201, "Fixture {$key} was not created: " . json_encode($body));
        $created[$key] = $body['product'];
        $productIds[] = (int)$body['id'];
    }
    $checks += count($fixtures);

    $legacyFields = [
        'sku' => 'TEST-' . strtoupper($token) . '-LEGACY',
        'title' => 'Legacy Form Product',
        'description' => 'Created through the unchanged Admin form contract.',
        'image' => 'img/test/' . $token . '-legacy.jpg',
        'price' => '12.25',
        'stock' => '3',
    ];
    [$status, $legacy] = test_request('product', 'POST', $legacyFields, $sessionId);
    test_expect($status === 201 && $legacy['product']['slug'] === 'legacy-form-product' && $legacy['product']['image'] === $legacyFields['image'], 'Legacy Admin create payload failed.');
    $productIds[] = (int)$legacy['id'];
    [$status, $legacyUpdate] = test_request('product', 'POST', $legacyFields + ['id' => $legacy['id']], $sessionId);
    test_expect($status === 200 && $legacyUpdate['updated'] === true, 'Legacy Admin update payload failed.');
    $checks += 2;

    [$status, $listing] = test_request('shop');
    test_expect($status === 200, 'Public listing failed.');
    $listedSlugs = array_column($listing['products'], 'slug');
    test_expect(in_array($created['internal']['slug'], $listedSlugs, true), 'Available published product was omitted.');
    test_expect(in_array($created['sold']['slug'], $listedSlugs, true), 'Configured sold-out product was omitted.');
    test_expect(!in_array($created['sold-hidden']['slug'], $listedSlugs, true), 'Hidden sold-out product was listed.');
    test_expect(!in_array($created['hidden']['slug'], $listedSlugs, true), 'Storefront-hidden product was listed.');
    test_expect(!in_array($created['draft']['slug'], $listedSlugs, true), 'Draft product was listed.');
    test_expect(array_search($created['internal']['slug'], $listedSlugs, true) < array_search($created['sold']['slug'], $listedSlugs, true), 'Available product was not ordered before sold-out product.');
    $checks += 6;

    $soldProduct = null;
    foreach ($listing['products'] as $product) if ($product['slug'] === $created['sold']['slug']) $soldProduct = $product;
    test_expect($soldProduct !== null && count($soldProduct['images']) === 2 && $soldProduct['images'][0]['sortOrder'] === 1 && $soldProduct['images'][1]['sortOrder'] === 2, 'Ordered multi-image response failed.');
    test_expect($soldProduct['currentPrice'] === '8.00' && $soldProduct['discountAmount'] === '2.00' && $soldProduct['discountPercentage'] === 20, 'Sale derivation failed.');
    test_expect(array_column($soldProduct['badges'], 'label') === ['Sale', 'Sold Out', 'Featured', 'Limited'], 'Derived/manual badge response failed.');
    $checks += 3;

    [$status] = test_request('shop-product', 'GET', ['slug' => $created['hidden']['slug']]);
    test_expect($status === 200, 'Published hidden product detail was not available.');
    [$status] = test_request('shop-product', 'GET', ['slug' => $created['draft']['slug']]);
    test_expect($status === 404, 'Draft product detail was publicly available.');
    $checks += 2;

    $invalidCases = [
        test_product_fields($token, 'bad-url', ['purchaseAction' => 'external', 'externalUrl' => 'ftp://example.com/file']),
        test_product_fields($token, 'duplicate-sku', ['sku' => $created['internal']['sku']]),
        test_product_fields($token, 'duplicate-slug', ['slug' => $created['internal']['slug']]),
        test_product_fields($token, 'bad-price', ['salePrice' => '10.00']),
        test_product_fields($token, 'reserved-badge', ['manualBadges' => json_encode([['label' => 'Sale', 'sortOrder' => 1]], JSON_THROW_ON_ERROR)]),
        test_product_fields($token, 'duplicate-badge', ['manualBadges' => json_encode([['label' => 'Limited', 'sortOrder' => 1], ['label' => 'limited', 'sortOrder' => 2]], JSON_THROW_ON_ERROR)]),
    ];
    foreach ($invalidCases as $case) {
        [$status] = test_request('product', 'POST', $case, $sessionId);
        test_expect($status === 422, 'Invalid product input was not rejected.');
        $checks++;
    }

    $orderBase = ['customerName' => 'API Test', 'customerEmail' => 'test@example.com', 'customerAddress' => 'Test address'];
    foreach (['external', 'inquiry'] as $key) {
        [$status] = test_request('order', 'POST', $orderBase + ['items' => json_encode([['id' => $created[$key]['id'], 'quantity' => 1]], JSON_THROW_ON_ERROR)]);
        test_expect($status === 422, ucfirst($key) . ' product was accepted by local checkout.');
        $checks++;
    }

    [$status, $order] = test_request('order', 'POST', $orderBase + ['items' => json_encode([
        ['id' => $created['internal']['id'], 'quantity' => 2],
        ['id' => $created['internal']['id'], 'quantity' => 3],
    ], JSON_THROW_ON_ERROR)]);
    test_expect($status === 201 && $order['order']['total'] === '37.50', 'Duplicate cart IDs were not aggregated with sale pricing.');
    $orderId = (int)$order['order']['id'];
    $orderIds[] = $orderId;
    $itemStmt = $pdo->prepare('SELECT quantity, price FROM shop_order_items WHERE order_id = ?');
    $itemStmt->execute([$orderId]);
    $orderItems = $itemStmt->fetchAll();
    test_expect(count($orderItems) === 1 && (int)$orderItems[0]['quantity'] === 5 && $orderItems[0]['price'] === '7.50', 'Aggregated order item snapshot was incorrect.');
    [$status, $deleteBlocked] = test_request('delete-product', 'POST', ['id' => $created['internal']['id']], $sessionId);
    test_expect($status === 409 && str_contains($deleteBlocked['error'] ?? '', 'existing order'), 'Ordered product deletion did not fail safely.');
    $checks += 3;

    foreach ([1, 2, 3] as $existingId) {
        $found = false;
        foreach ($listing['products'] as $product) if ($product['id'] === $existingId) $found = true;
        test_expect($found, "Existing product {$existingId} was not returned.");
        $checks++;
    }
} finally {
    if ($orderIds) {
        $delete = $pdo->prepare('DELETE FROM shop_orders WHERE id = ?');
        foreach ($orderIds as $orderId) $delete->execute([$orderId]);
    }
    if ($productIds) {
        $delete = $pdo->prepare('DELETE FROM shop_products WHERE id = ?');
        foreach ($productIds as $productId) $delete->execute([$productId]);
    }
    foreach ($autoIncrements as $table => $nextId) {
        if ($nextId !== null && preg_match('/\A[a-z_]+\z/', $table)) $pdo->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = " . (int)$nextId);
    }
    test_request('logout', 'POST', [], $sessionId);
}

test_expect($fingerprint() === $beforeFingerprint, 'Existing product fingerprint changed after cleanup.');
test_expect((int)$pdo->query('SELECT COUNT(*) FROM shop_products')->fetchColumn() === $beforeCounts['products'], 'Product cleanup count mismatch.');
test_expect((int)$pdo->query('SELECT COUNT(*) FROM shop_orders')->fetchColumn() === $beforeCounts['orders'], 'Order cleanup count mismatch.');
test_expect((int)$pdo->query('SELECT COUNT(*) FROM shop_order_items')->fetchColumn() === $beforeCounts['items'], 'Order-item cleanup count mismatch.');
$checks += 4;

echo "Shop API V2 integration tests passed: {$checks} assertions.\n";
