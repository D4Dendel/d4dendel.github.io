<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
ob_start();

$discardApiOutput = static function (): void {
    while (ob_get_level() > 0) ob_end_clean();
};

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $error) use ($discardApiOutput): void {
    error_log(sprintf('API failure: %s in %s:%d', $error->getMessage(), $error->getFile(), $error->getLine()));
    $discardApiOutput();
    json_response(['error' => 'The server could not complete the request.'], 500);
});

register_shutdown_function(static function () use ($discardApiOutput): void {
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    error_log(sprintf('API fatal error: %s in %s:%d', $error['message'], $error['file'], $error['line']));
    $discardApiOutput();
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => 'The server could not complete the request.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
});

$iniBytes = static function (string $value): int {
    $value = trim($value);
    if ($value === '') return 0;
    $unit = strtolower(substr($value, -1));
    $number = (float)$value;
    return match ($unit) {
        'g' => (int)($number * 1024 * 1024 * 1024),
        'm' => (int)($number * 1024 * 1024),
        'k' => (int)($number * 1024),
        default => (int)$number,
    };
};

$contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$postMaximum = $iniBytes((string)ini_get('post_max_size'));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $contentLength !== false && $postMaximum > 0 && $contentLength > $postMaximum) {
    error_log("API upload rejected: request length {$contentLength} exceeds post_max_size {$postMaximum}.");
    json_response(['error' => 'The upload request is too large. Choose files within the stated size limits.'], 413);
}

session_start();
require __DIR__ . '/shop_products.php';
require __DIR__ . '/shop_checkout.php';

$action = $_GET['action'] ?? 'projects';

$themeDefaults = [
    'accentColor' => '#c86f52',
    'pageBackground' => '#fff0e8',
    'surfaceColor' => '#fff8f3',
    'primaryText' => '#3d2925',
    'buttonRadius' => '999px',
    'galleryLayout' => 'uniform',
    'galleryEdge' => 'rounded'
];

$readTheme = static function () use ($themeDefaults): array {
    try {
        $row = db()->query('SELECT accent_color, page_background, surface_color, primary_text, button_radius, gallery_layout, gallery_edge FROM site_settings WHERE id = 1')->fetch();
        if (!$row) return $themeDefaults;
        $theme = [
            'accentColor' => $row['accent_color'],
            'pageBackground' => $row['page_background'],
            'surfaceColor' => $row['surface_color'],
            'primaryText' => $row['primary_text'],
            'buttonRadius' => $row['button_radius'],
            'galleryLayout' => $row['gallery_layout'],
            'galleryEdge' => $row['gallery_edge']
        ];
        foreach (['accentColor', 'pageBackground', 'surfaceColor', 'primaryText'] as $key) {
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string)$theme[$key])) $theme[$key] = $themeDefaults[$key];
        }
        if (!in_array($theme['buttonRadius'], ['2px', '4px', '8px', '999px'], true)) $theme['buttonRadius'] = $themeDefaults['buttonRadius'];
        if (!in_array($theme['galleryLayout'], ['uniform', 'masonry', 'editorial', 'clean'], true)) $theme['galleryLayout'] = $themeDefaults['galleryLayout'];
        if (!in_array($theme['galleryEdge'], ['rounded', 'slight', 'square', 'none'], true)) $theme['galleryEdge'] = $themeDefaults['galleryEdge'];
        return $theme;
    } catch (Throwable $error) {
        return $themeDefaults;
    }
};

$brandDefaults = [
    'brandName' => 'Dyndel Pino',
    'logoPath' => 'img/icon.png',
    'siteIconPath' => 'img/icon.png',
    'brandNameColor' => '#f3a889',
    'brandFont' => 'patrick-hand',
    'headingFont' => 'patrick-hand',
    'bodyFont' => 'nunito',
    'uiFont' => 'same-body',
    'pointerBrushEnabled' => true,
    'socials' => [
        'instagram' => 'https://www.instagram.com/d4dyndel',
        'facebook' => 'https://www.facebook.com/d4dyndel',
        'twitter' => 'https://twitter.com/d4dyndel',
        'youtube' => 'https://www.youtube.com/@d4dyndel',
    ],
    'updatedAt' => null,
];
$brandFonts = ['patrick-hand', 'nunito', 'georgia', 'system-sans'];
$uiFonts = ['same-body', 'nunito', 'georgia', 'system-sans'];

$readBrand = static function () use ($brandDefaults, $brandFonts, $uiFonts): array {
    try {
        $row = db()->query(
            'SELECT brand_name, brand_logo_path, site_icon_path, brand_name_color, brand_font, heading_font, body_font, ui_font, pointer_brush_enabled,
                    instagram_url, facebook_url, twitter_url, youtube_url, updated_at
             FROM site_settings WHERE id = 1'
        )->fetch();
        if (!$row) return $brandDefaults;
        $brand = [
            'brandName' => trim((string)$row['brand_name']) ?: $brandDefaults['brandName'],
            'logoPath' => trim((string)($row['brand_logo_path'] ?? '')) ?: null,
            'siteIconPath' => trim((string)($row['site_icon_path'] ?? '')) ?: $brandDefaults['siteIconPath'],
            'brandNameColor' => strtolower((string)$row['brand_name_color']),
            'brandFont' => (string)$row['brand_font'],
            'headingFont' => (string)$row['heading_font'],
            'bodyFont' => (string)$row['body_font'],
            'uiFont' => (string)$row['ui_font'],
            'pointerBrushEnabled' => (bool)$row['pointer_brush_enabled'],
            'socials' => [
                'instagram' => trim((string)($row['instagram_url'] ?? '')),
                'facebook' => trim((string)($row['facebook_url'] ?? '')),
                'twitter' => trim((string)($row['twitter_url'] ?? '')),
                'youtube' => trim((string)($row['youtube_url'] ?? '')),
            ],
            'updatedAt' => $row['updated_at'] ?? null,
        ];
        if (!preg_match('/^#[0-9a-f]{6}$/i', $brand['brandNameColor'])) $brand['brandNameColor'] = $brandDefaults['brandNameColor'];
        foreach (['brandFont', 'headingFont', 'bodyFont'] as $key) {
            if (!in_array($brand[$key], $brandFonts, true)) $brand[$key] = $brandDefaults[$key];
        }
        if (!in_array($brand['uiFont'], $uiFonts, true)) $brand['uiFont'] = $brandDefaults['uiFont'];
        return $brand;
    } catch (Throwable $error) {
        return $brandDefaults;
    }
};

$storeBrandImage = static function (mixed $file, string $kind): ?array {
    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $maximumBytes = $kind === 'icon' ? 1024 * 1024 : 4 * 1024 * 1024;
    $maximumDimension = $kind === 'icon' ? 1024 : 4096;
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || (int)($file['size'] ?? 0) < 1
        || (int)$file['size'] > $maximumBytes
        || !isset($file['tmp_name'])
        || !is_uploaded_file($file['tmp_name'])) {
        throw new DomainException(ucfirst($kind) . ' upload failed or exceeds the allowed size.');
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $image = @getimagesize($file['tmp_name']);
    if (!isset($allowed[$mime]) || $image === false || ($image['mime'] ?? '') !== $mime
        || $image[0] < 1 || $image[1] < 1 || $image[0] > $maximumDimension || $image[1] > $maximumDimension) {
        throw new DomainException(ucfirst($kind) . ' must be a valid JPG, PNG, GIF, or WebP image within the dimension limit.');
    }
    $folder = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'projectfolder' . DIRECTORY_SEPARATOR . 'brand' . DIRECTORY_SEPARATOR;
    if (!is_dir($folder) && !mkdir($folder, 0755, true)) throw new RuntimeException('Brand media directory is unavailable.');
    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $diskPath = $folder . $filename;
    if (!move_uploaded_file($file['tmp_name'], $diskPath)) throw new RuntimeException('Could not save brand media.');
    return ['path' => 'img/projectfolder/brand/' . $filename, 'diskPath' => $diskPath];
};

$postBoolean = static function (string $key): int {
    $value = $_POST[$key] ?? '0';
    if (in_array($value, ['1', 1, true, 'true', 'on'], true)) return 1;
    if (in_array($value, ['0', 0, false, 'false', 'off', ''], true)) return 0;
    json_response(['error' => "Invalid {$key} value."], 422);
};

$safeSlug = static function (string $value): string {
    $slug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $value), '-'));
    return substr($slug, 0, 180);
};

$contentText = static function (mixed $value, string $field, int $maxLength, bool $allowEmpty = true): string {
    if (!is_string($value)) json_response(['error' => "{$field} must be text."], 422);
    $value = trim($value);
    if (preg_match('//u', $value) !== 1) json_response(['error' => "{$field} must be valid UTF-8 text."], 422);
    if (!$allowEmpty && $value === '') json_response(['error' => "{$field} cannot be empty."], 422);
    if (mb_strlen($value, 'UTF-8') > $maxLength) json_response(['error' => "{$field} must be {$maxLength} characters or fewer."], 422);
    return $value;
};

$contentMediaUrl = static function (mixed $value, string $field, int $maxLength = 255) use ($contentText): string {
    $url = $contentText($value, $field, $maxLength, false);
    if (preg_match('/[\x00-\x20\x7f]/', $url)) json_response(['error' => "{$field} contains invalid characters."], 422);
    $parts = parse_url($url);
    if ($parts === false) json_response(['error' => "{$field} must be a valid HTTP(S) URL or site-relative path."], 422);
    if (isset($parts['scheme']) || isset($parts['host'])) {
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            json_response(['error' => "{$field} must be a valid HTTP(S) URL without credentials."], 422);
        }
        return $url;
    }
    $path = explode('?', explode('#', $url, 2)[0], 2)[0];
    if ($path === '' || str_starts_with($path, '/') || str_contains($url, '\\') || preg_match('/[\x00-\x1f\x7f]/', $url) || !preg_match("#\\A[A-Za-z0-9][A-Za-z0-9._~!$&'()*+,;=:@%/?\\x23-]*\\z#D", $url)) {
        json_response(['error' => "{$field} must be a valid site-relative path."], 422);
    }
    if (preg_match('/%(?![a-f0-9]{2})/i', $path)) json_response(['error' => "{$field} contains invalid URL encoding."], 422);
    $decodedPath = $path;
    for ($decodeCount = 0; $decodeCount < 8; $decodeCount++) {
        foreach (explode('/', $decodedPath) as $segment) {
            if ($segment === '.' || $segment === '..') json_response(['error' => "{$field} cannot traverse directories."], 422);
        }
        if (str_contains($decodedPath, '\\') || preg_match('/[\x00-\x1f\x7f]/', $decodedPath)) {
            json_response(['error' => "{$field} contains invalid path characters."], 422);
        }
        $decoded = rawurldecode($decodedPath);
        if ($decoded === $decodedPath) return $url;
        $decodedPath = $decoded;
    }
    json_response(['error' => "{$field} contains excessive URL encoding."], 422);
};

$contentBlocks = static function (mixed $raw) use ($contentText, $contentMediaUrl): array {
    if (!is_string($raw) || strlen($raw) > 1048576) json_response(['error' => 'Blocks must be a JSON list no larger than 1 MB.'], 422);
    try {
        $blocks = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        json_response(['error' => 'Blocks must contain valid JSON.'], 422);
    }
    if (!is_array($blocks) || !array_is_list($blocks)) json_response(['error' => 'Blocks must be a JSON list.'], 422);
    if (count($blocks) > 100) json_response(['error' => 'An article can have no more than 100 blocks.'], 422);

    $types = ['paragraph', 'heading', 'image', 'image_caption', 'video', 'quote', 'divider', 'gallery'];
    $validated = [];
    foreach ($blocks as $index => $block) {
        if (!$block instanceof stdClass) json_response(['error' => 'Each block must be an object.', 'block' => $index], 422);
        $blockFields = get_object_vars($block);
        if (array_diff(array_keys($blockFields), ['type', 'payload']) || !isset($blockFields['type'], $blockFields['payload']) || !is_string($blockFields['type'])) {
            json_response(['error' => 'Each block must contain only type and payload fields.', 'block' => $index], 422);
        }
        $type = $blockFields['type'];
        $payload = $blockFields['payload'];
        if (!in_array($type, $types, true)) json_response(['error' => 'Unsupported content block type.', 'block' => $index], 422);
        if (!$payload instanceof stdClass) json_response(['error' => 'Block payload must be an object.', 'block' => $index], 422);
        $fields = get_object_vars($payload);

        $requireFields = static function (array $required, array $optional = []) use ($fields, $index): void {
            if (array_diff(array_keys($fields), array_merge($required, $optional)) || array_diff($required, array_keys($fields))) {
                json_response(['error' => 'Block payload has missing or unsupported fields.', 'block' => $index], 422);
            }
        };
        $imageFields = static function (stdClass $image, int $imageIndex) use ($contentText, $contentMediaUrl, $index): array {
            $imageData = get_object_vars($image);
            if (array_diff(array_keys($imageData), ['url', 'alt', 'caption']) || !isset($imageData['url'], $imageData['alt']) || array_key_exists('caption', $imageData) && !is_string($imageData['caption'])) {
                json_response(['error' => 'Gallery images must contain url and alt, with an optional caption.', 'block' => $index, 'image' => $imageIndex], 422);
            }
            $item = [
                'url' => $contentMediaUrl($imageData['url'], 'Image URL'),
                'alt' => $contentText($imageData['alt'], 'Image alt text', 255)
            ];
            if (array_key_exists('caption', $imageData)) $item['caption'] = $contentText($imageData['caption'], 'Image caption', 500);
            return $item;
        };

        switch ($type) {
            case 'paragraph':
                $requireFields(['text']);
                $normalized = ['text' => $contentText($fields['text'], 'Paragraph text', 20000, false)];
                break;
            case 'heading':
                $requireFields(['text', 'level']);
                if (!is_int($fields['level']) || !in_array($fields['level'], [2, 3], true)) {
                    json_response(['error' => 'Heading level must be 2 or 3.', 'block' => $index], 422);
                }
                $normalized = ['text' => $contentText($fields['text'], 'Heading text', 300, false), 'level' => $fields['level']];
                break;
            case 'image':
                $requireFields(['url', 'alt']);
                $normalized = [
                    'url' => $contentMediaUrl($fields['url'], 'Image URL'),
                    'alt' => $contentText($fields['alt'], 'Image alt text', 255)
                ];
                break;
            case 'image_caption':
                $requireFields(['url', 'alt', 'caption']);
                $normalized = [
                    'url' => $contentMediaUrl($fields['url'], 'Image URL'),
                    'alt' => $contentText($fields['alt'], 'Image alt text', 255),
                    'caption' => $contentText($fields['caption'], 'Image caption', 500)
                ];
                break;
            case 'video':
                $requireFields(['provider', 'videoId']);
                if ($fields['provider'] !== 'youtube') json_response(['error' => 'Only YouTube video embeds are supported.'], 422);
                $videoId = $contentText($fields['videoId'], 'YouTube video ID', 11, false);
                if (!preg_match('/\A[A-Za-z0-9_-]{11}\z/', $videoId)) json_response(['error' => 'YouTube video ID must be exactly 11 valid characters.'], 422);
                $normalized = ['provider' => 'youtube', 'videoId' => $videoId];
                break;
            case 'quote':
                $requireFields(['text'], ['attribution']);
                $normalized = ['text' => $contentText($fields['text'], 'Quote text', 5000, false)];
                if (array_key_exists('attribution', $fields)) $normalized['attribution'] = $contentText($fields['attribution'], 'Quote attribution', 300);
                break;
            case 'divider':
                $requireFields([]);
                $normalized = new stdClass();
                break;
            case 'gallery':
                $requireFields(['images']);
                if (!is_array($fields['images']) || !array_is_list($fields['images']) || count($fields['images']) < 1 || count($fields['images']) > 20) {
                    json_response(['error' => 'Gallery blocks must contain between 1 and 20 images.', 'block' => $index], 422);
                }
                $normalized = ['images' => []];
                foreach ($fields['images'] as $imageIndex => $image) {
                    if (!$image instanceof stdClass) json_response(['error' => 'Each gallery item must be an object.', 'block' => $index, 'image' => $imageIndex], 422);
                    $normalized['images'][] = $imageFields($image, $imageIndex);
                }
                break;
        }
        $validated[] = ['type' => $type, 'payload' => $normalized];
    }
    return $validated;
};

if ($action === 'contact') {
    require_post();
    $name = request_string('name', 160);
    $email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $projectType = request_string('projectType', 80);
    $message = request_string('message', 5000);
    if (!$email) json_response(['error' => 'Enter a valid email address.'], 422);
    if (CONTACT_EMAIL === 'replace-with-your-email@example.com') json_response(['error' => 'Set CONTACT_EMAIL in api/config.php first.'], 500);
    $subject = 'New portfolio inquiry from ' . $name;
    $body = "Name: {$name}\nEmail: {$email}\nProject type: {$projectType}\n\n{$message}";
    $headers = "From: " . CONTACT_EMAIL . "\r\nReply-To: {$email}\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    if (!mail(CONTACT_EMAIL, $subject, $body, $headers)) json_response(['error' => 'The message could not be sent. Check the server mail settings.'], 500);
    json_response(['sent' => true]);
}

if ($action === 'shop' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT ' . shop_product_columns() . "
         FROM shop_products
         WHERE publication_status = 'published'
           AND storefront_visible = 1
           AND (stock > 0 OR show_when_sold_out = 1)
         ORDER BY CASE WHEN stock > 0 THEN 0 ELSE 1 END, sort_order ASC, id ASC"
    );
    $stmt->execute();
    json_response(['products' => shop_products_response($pdo, $stmt->fetchAll())]);
}

if ($action === 'shop-product' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $slug = trim((string)($_GET['slug'] ?? ''));
    if (!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $slug) || strlen($slug) > 180) {
        json_response(['error' => 'Product not found.'], 404);
    }
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT ' . shop_product_columns() . "
         FROM shop_products
         WHERE slug = ?
           AND publication_status = 'published'
           AND storefront_visible = 1
           AND (stock > 0 OR show_when_sold_out = 1)
         LIMIT 1"
    );
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    if (!$row) json_response(['error' => 'Product not found.'], 404);
    json_response(['product' => shop_products_response($pdo, [$row])[0]]);
}

if ($action === 'admin-products' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_auth();
    $pdo = db();
    $stmt = $pdo->prepare('SELECT ' . shop_product_columns() . ' FROM shop_products ORDER BY sort_order ASC, id ASC');
    $stmt->execute();
    json_response(['products' => shop_products_response($pdo, $stmt->fetchAll(), true)]);
}

if ($action === 'checkout-quote') {
    require_post();
    json_response(['quote' => shop_checkout_quote(
        db(),
        $_POST['items'] ?? null,
        $_POST['countryCode'] ?? null,
        $_POST['shippingMethodId'] ?? null
    )]);
}

if ($action === 'checkout-order') {
    require_post();
    json_response(['order' => shop_checkout_create_order(db(), $_POST)], 201);
}

if ($action === 'order') {
    // Legacy compatibility only. Shop F2 must use a separate payment-aware
    // order-creation flow; this endpoint still decrements stock immediately.
    require_post();
    $customerName = request_string('customerName', 160);
    $customerEmail = filter_var(trim((string)($_POST['customerEmail'] ?? '')), FILTER_VALIDATE_EMAIL);
    $customerAddress = request_string('customerAddress', 1000);
    try {
        $items = json_decode((string)($_POST['items'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $items = null;
    }
    if (!$customerEmail || !is_array($items) || !array_is_list($items) || !$items) json_response(['error' => 'Complete your contact details and cart.'], 422);

    $quantities = [];
    foreach ($items as $item) {
        if (!is_array($item)) json_response(['error' => 'Invalid cart item.'], 422);
        $productId = shop_positive_integer($item['id'] ?? null, 'Product ID');
        $quantity = shop_positive_integer($item['quantity'] ?? null, 'Quantity');
        $quantities[$productId] = ($quantities[$productId] ?? 0) + $quantity;
        if ($quantities[$productId] > 4294967295) json_response(['error' => 'Cart quantity is too large.'], 422);
    }
    ksort($quantities, SORT_NUMERIC);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $productStmt = $pdo->prepare("SELECT id, sku, title, product_type, price, sale_price, stock, publication_status, purchase_action FROM shop_products WHERE id = ? FOR UPDATE");
        $orderItems = [];
        $totalCents = 0;
        foreach ($quantities as $productId => $quantity) {
            $productStmt->execute([$productId]);
            $product = $productStmt->fetch();
            if (!$product || $product['publication_status'] !== 'published' || $product['purchase_action'] !== 'internal') {
                throw new RuntimeException('One item is not eligible for local checkout.');
            }
            if ($quantity > (int)$product['stock']) throw new RuntimeException('One item is out of stock.');
            $regular = shop_money($product['price'], 'Stored regular price');
            $sale = $product['sale_price'] === null ? null : shop_money($product['sale_price'], 'Stored sale price');
            $unitCents = $sale !== null && $sale['cents'] > 0 && $sale['cents'] < $regular['cents'] ? $sale['cents'] : $regular['cents'];
            if ($quantity > 0 && $unitCents > intdiv(SHOP_MAX_MONEY_CENTS - $totalCents, $quantity)) {
                throw new RuntimeException('Order total is too large.');
            }
            $totalCents += $unitCents * $quantity;
            $orderItems[] = [
                $productId,
                $product['sku'],
                $product['title'],
                $product['product_type'],
                $quantity,
                shop_money_from_cents($unitCents),
                shop_money_from_cents($unitCents * $quantity),
            ];
        }
        $orderStmt = $pdo->prepare('INSERT INTO shop_orders (customer_name, customer_email, customer_address, subtotal, total) VALUES (?, ?, ?, ?, ?)');
        $orderStmt->execute([$customerName, $customerEmail, $customerAddress, shop_money_from_cents($totalCents), shop_money_from_cents($totalCents)]);
        $orderId = $pdo->lastInsertId();
        $itemStmt = $pdo->prepare('INSERT INTO shop_order_items (order_id, product_id, product_sku, product_name, product_type, currency, quantity, price, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stockStmt = $pdo->prepare('UPDATE shop_products SET stock = stock - ? WHERE id = ?');
        foreach ($orderItems as [$productId, $sku, $name, $productType, $quantity, $price, $lineTotal]) {
            $itemStmt->execute([$orderId, $productId, $sku, $name, $productType, SHOP_CURRENCY, $quantity, $price, $lineTotal]);
            $stockStmt->execute([$quantity, $productId]);
        }
        $pdo->commit();
        json_response(['order' => ['id' => (int)$orderId, 'total' => shop_money_from_cents($totalCents)]], 201);
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Shop order failed: ' . $error->getMessage());
        json_response(['error' => 'The order could not be completed.'], 500);
    } catch (RuntimeException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(['error' => $error->getMessage()], 422);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Unexpected Shop order failure: ' . $error->getMessage());
        json_response(['error' => 'The order could not be completed.'], 500);
    }
}

if ($action === 'session' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(['authenticated' => !empty($_SESSION['admin_id'])]);
}

if ($action === 'theme' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Cache-Control: no-store, max-age=0');
    json_response(['theme' => $readTheme()]);
}

if ($action === 'brand' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Cache-Control: no-store, max-age=0');
    json_response(['brand' => $readBrand()]);
}

if ($action === 'save-brand') {
    require_post();
    require_auth();
    $current = $readBrand();
    $brandName = trim((string)($_POST['brandName'] ?? ''));
    if ($brandName === '' || preg_match('//u', $brandName) !== 1 || mb_strlen($brandName, 'UTF-8') > 120) {
        json_response(['error' => 'Brand Name is required and must be 120 characters or fewer.'], 422);
    }
    $brandNameColor = strtolower(trim((string)($_POST['brandNameColor'] ?? '')));
    if (!preg_match('/^#[0-9a-f]{6}$/', $brandNameColor)) json_response(['error' => 'Brand Name Color must be a six-digit HEX color.'], 422);
    $brandFont = (string)($_POST['brandFont'] ?? '');
    $headingFont = (string)($_POST['headingFont'] ?? '');
    $bodyFont = (string)($_POST['bodyFont'] ?? '');
    $uiFont = (string)($_POST['uiFont'] ?? '');
    foreach (['Brand Font' => $brandFont, 'Heading Font' => $headingFont, 'Body Font' => $bodyFont] as $label => $font) {
        if (!in_array($font, $brandFonts, true)) json_response(['error' => "Invalid {$label}."], 422);
    }
    if (!in_array($uiFont, $uiFonts, true)) json_response(['error' => 'Invalid UI Font.'], 422);
    $pointerBrushRaw = $_POST['pointerBrushEnabled'] ?? ($current['pointerBrushEnabled'] ? '1' : '0');
    if (in_array($pointerBrushRaw, ['1', 1, true, 'true', 'on'], true)) $pointerBrushEnabled = 1;
    elseif (in_array($pointerBrushRaw, ['0', 0, false, 'false', 'off'], true)) $pointerBrushEnabled = 0;
    else json_response(['error' => 'Pointer Brush Effect must be On or Off.'], 422);
    $socials = [];
    foreach (['instagram', 'facebook', 'twitter', 'youtube'] as $network) {
        $value = trim((string)($_POST[$network] ?? ''));
        if ($value !== '') {
            $parts = parse_url($value);
            if (!filter_var($value, FILTER_VALIDATE_URL) || !is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) {
                json_response(['error' => ucfirst($network) . ' must be a valid HTTP(S) URL or left blank.'], 422);
            }
            if (strlen($value) > 500) json_response(['error' => ucfirst($network) . ' URL must be 500 characters or fewer.'], 422);
        }
        $socials[$network] = $value;
    }

    $logo = null;
    $icon = null;
    try {
        $logo = $storeBrandImage($_FILES['logoFile'] ?? null, 'logo');
        $icon = $storeBrandImage($_FILES['iconFile'] ?? null, 'icon');
        $logoPath = !empty($_POST['textLogoOnly']) ? null : ($logo['path'] ?? $current['logoPath']);
        $siteIconPath = $icon['path'] ?? $current['siteIconPath'] ?? $brandDefaults['siteIconPath'];
        $stmt = db()->prepare(
            'INSERT INTO site_settings
                (id, brand_name, brand_logo_path, site_icon_path, brand_name_color, brand_font, heading_font, body_font, ui_font, pointer_brush_enabled,
                 instagram_url, facebook_url, twitter_url, youtube_url)
             VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                brand_name = VALUES(brand_name), brand_logo_path = VALUES(brand_logo_path), site_icon_path = VALUES(site_icon_path),
                brand_name_color = VALUES(brand_name_color), brand_font = VALUES(brand_font), heading_font = VALUES(heading_font),
                body_font = VALUES(body_font), ui_font = VALUES(ui_font), pointer_brush_enabled = VALUES(pointer_brush_enabled), instagram_url = VALUES(instagram_url),
                facebook_url = VALUES(facebook_url), twitter_url = VALUES(twitter_url), youtube_url = VALUES(youtube_url)'
        );
        $stmt->execute([
            $brandName, $logoPath, $siteIconPath, $brandNameColor, $brandFont, $headingFont, $bodyFont, $uiFont, $pointerBrushEnabled,
            $socials['instagram'], $socials['facebook'], $socials['twitter'], $socials['youtube'],
        ]);
    } catch (DomainException $error) {
        foreach ([$logo, $icon] as $upload) {
            if ($upload && is_file($upload['diskPath'])) unlink($upload['diskPath']);
        }
        json_response(['error' => $error->getMessage()], 422);
    } catch (Throwable $error) {
        foreach ([$logo, $icon] as $upload) {
            if ($upload && is_file($upload['diskPath'])) unlink($upload['diskPath']);
        }
        error_log('Brand Identity save failed: ' . $error->getMessage());
        json_response(['error' => 'Brand Identity could not be saved.'], 500);
    }
    json_response(['saved' => true, 'brand' => $readBrand()]);
}

if ($action === 'save-theme') {
    require_post();
    require_auth();
    $theme = [];
    foreach ([
        'accentColor' => 'accentColor',
        'pageBackground' => 'pageBackground',
        'surfaceColor' => 'surfaceColor',
        'primaryText' => 'primaryText'
    ] as $field => $key) {
        $value = trim((string)($_POST[$field] ?? ''));
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $value)) json_response(['error' => "Invalid {$field} color."], 422);
        $theme[$key] = strtolower($value);
    }
    $theme['buttonRadius'] = (string)($_POST['buttonRadius'] ?? '');
    $theme['galleryLayout'] = (string)($_POST['galleryLayout'] ?? '');
    $theme['galleryEdge'] = (string)($_POST['galleryEdge'] ?? '');
    if (!in_array($theme['buttonRadius'], ['2px', '4px', '8px', '999px'], true)) json_response(['error' => 'Invalid button radius.'], 422);
    if (!in_array($theme['galleryLayout'], ['uniform', 'masonry', 'editorial', 'clean'], true)) json_response(['error' => 'Invalid gallery layout.'], 422);
    if (!in_array($theme['galleryEdge'], ['rounded', 'slight', 'square', 'none'], true)) json_response(['error' => 'Invalid gallery edge style.'], 422);
    $stmt = db()->prepare('INSERT INTO site_settings (id, accent_color, page_background, surface_color, primary_text, button_radius, gallery_layout, gallery_edge) VALUES (1, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE accent_color = VALUES(accent_color), page_background = VALUES(page_background), surface_color = VALUES(surface_color), primary_text = VALUES(primary_text), button_radius = VALUES(button_radius), gallery_layout = VALUES(gallery_layout), gallery_edge = VALUES(gallery_edge)');
    $stmt->execute([$theme['accentColor'], $theme['pageBackground'], $theme['surfaceColor'], $theme['primaryText'], $theme['buttonRadius'], $theme['galleryLayout'], $theme['galleryEdge']]);
    json_response(['saved' => true, 'theme' => $theme]);
}

if ($action === 'reset-theme') {
    require_post();
    require_auth();
    $stmt = db()->prepare('INSERT INTO site_settings (id, accent_color, page_background, surface_color, primary_text, button_radius, gallery_layout, gallery_edge) VALUES (1, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE accent_color = VALUES(accent_color), page_background = VALUES(page_background), surface_color = VALUES(surface_color), primary_text = VALUES(primary_text), button_radius = VALUES(button_radius), gallery_layout = VALUES(gallery_layout), gallery_edge = VALUES(gallery_edge)');
    $stmt->execute([$themeDefaults['accentColor'], $themeDefaults['pageBackground'], $themeDefaults['surfaceColor'], $themeDefaults['primaryText'], $themeDefaults['buttonRadius'], $themeDefaults['galleryLayout'], $themeDefaults['galleryEdge']]);
    json_response(['reset' => true, 'theme' => $themeDefaults]);
}

if ($action === 'content' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Cache-Control: no-store, max-age=0');
    $entries = db()->query("SELECT id, slug, title, cover_image AS coverImage, type, excerpt, body, publish_date AS publishDate, status, featured, show_home AS showHome, show_card AS showCard, card_size AS cardSize FROM content_entries WHERE status = 'published' ORDER BY featured DESC, publish_date DESC, id DESC LIMIT 50")->fetchAll();
    foreach ($entries as &$entry) {
        $entry['featured'] = (bool)$entry['featured'];
        $entry['showHome'] = (bool)$entry['showHome'];
        $entry['showCard'] = (bool)$entry['showCard'];
    }
    json_response(['entries' => $entries]);
}

if ($action === 'admin-content' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_auth();
    $entries = db()->query('SELECT id, slug, title, cover_image AS coverImage, cover_alt AS coverAlt, type, excerpt, body, publish_date AS publishDate, status, featured, show_home AS showHome, show_card AS showCard, card_size AS cardSize, seo_title AS seoTitle, meta_description AS metaDescription, og_title AS ogTitle, og_description AS ogDescription, og_image AS ogImage, noindex FROM content_entries ORDER BY updated_at DESC, id DESC')->fetchAll();
    $entryIds = array_column($entries, 'id');
    $blocksByEntry = [];
    if ($entryIds) {
        $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
        $blockStmt = db()->prepare("SELECT id, content_entry_id AS contentEntryId, block_order AS blockOrder, block_type AS type, payload FROM content_blocks WHERE content_entry_id IN ({$placeholders}) ORDER BY content_entry_id, block_order");
        $blockStmt->execute($entryIds);
        foreach ($blockStmt->fetchAll() as $block) {
            try {
                $payload = json_decode($block['payload'], false, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new RuntimeException('A saved content block contains invalid JSON.', 0, $error);
            }
            $entryId = (string)$block['contentEntryId'];
            $blocksByEntry[$entryId][] = [
                'id' => (int)$block['id'],
                'blockOrder' => (int)$block['blockOrder'],
                'type' => $block['type'],
                'payload' => $payload
            ];
        }
    }
    foreach ($entries as &$entry) {
        $entry['featured'] = (bool)$entry['featured'];
        $entry['showHome'] = (bool)$entry['showHome'];
        $entry['showCard'] = (bool)$entry['showCard'];
        $entry['noindex'] = (bool)$entry['noindex'];
        $entry['blocks'] = $blocksByEntry[(string)$entry['id']] ?? [];
    }
    unset($entry);
    json_response(['entries' => $entries]);
}

if ($action === 'content-media-upload') {
    require_post();
    require_auth();
    $file = $_FILES['imageFile'] ?? null;
    if (!is_array($file) || !isset($file['error'], $file['size'], $file['tmp_name'])
        || !is_int($file['error']) || !is_int($file['size']) || !is_string($file['tmp_name'])
        || $file['error'] !== UPLOAD_ERR_OK || $file['size'] < 1 || $file['size'] > MAX_UPLOAD_BYTES
        || !is_uploaded_file($file['tmp_name'])) {
        json_response(['error' => 'Choose a single image file, 8MB or smaller.'], 422);
    }
    $size = filesize($file['tmp_name']);
    if ($size === false || $size < 1 || $size > MAX_UPLOAD_BYTES) {
        json_response(['error' => 'Image must be 8MB or smaller.'], 422);
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $image = @getimagesize($file['tmp_name']);
    if (!isset($allowed[$mime]) || $image === false || ($image['mime'] ?? '') !== $mime || $image[0] < 1 || $image[1] < 1) {
        json_response(['error' => 'Choose a valid JPG, PNG, GIF, or WebP image.'], 422);
    }
    // The destination and extension are server-controlled, never supplied by the client.
    $folder = PROJECT_UPLOAD_DIR . 'content/blocks/';
    if (!is_dir($folder) && !mkdir($folder, 0755, true)) {
        json_response(['error' => 'Content image upload directory is unavailable.'], 500);
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $folder . $filename)) {
        json_response(['error' => 'Could not save image.'], 500);
    }
    json_response(['url' => PROJECT_UPLOAD_URL . 'content/blocks/' . $filename], 201);
}

if ($action === 'content-entry') {
    require_post();
    require_auth();
    $idValue = trim((string)($_POST['id'] ?? ''));
    $id = $idValue === '' ? null : filter_var($idValue, FILTER_VALIDATE_INT);
    if ($idValue !== '' && (!$id || $id < 1)) json_response(['error' => 'Invalid content entry ID.'], 422);
    $title = request_string('title', 180);
    $body = trim((string)($_POST['body'] ?? ''));
    if ($body === '' || strlen($body) > 100000) json_response(['error' => 'Content body is required and must be 100,000 characters or fewer.'], 422);
    $excerpt = trim((string)($_POST['excerpt'] ?? ''));
    if (strlen($excerpt) > 500) json_response(['error' => 'Excerpt must be 500 characters or fewer.'], 422);
    $type = (string)($_POST['type'] ?? '');
    $status = (string)($_POST['status'] ?? '');
    $cardSize = (string)($_POST['cardSize'] ?? '');
    if (!in_array($type, ['blog', 'news', 'update', 'announcement'], true)) json_response(['error' => 'Invalid content type.'], 422);
    if (!in_array($status, ['draft', 'published'], true)) json_response(['error' => 'Invalid publication status.'], 422);
    if (!in_array($cardSize, ['standard', 'wide', 'featured'], true)) json_response(['error' => 'Invalid card size.'], 422);
    $featured = $postBoolean('featured');
    $showHome = $postBoolean('showHome');
    $showCard = $postBoolean('showCard');
    $publishDate = (string)($_POST['publishDate'] ?? '');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $publishDate);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (!$date || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count'])) || $date->format('Y-m-d') !== $publishDate) {
        json_response(['error' => 'Enter a valid publish date.'], 422);
    }
    $slug = $safeSlug(trim((string)($_POST['slug'] ?? '')) ?: $title);
    if ($slug === '') json_response(['error' => 'Enter a title that can form a valid slug.'], 422);

    $blocksSubmitted = array_key_exists('blocks', $_POST);
    $blocks = $blocksSubmitted ? $contentBlocks($_POST['blocks']) : null;
    $seoFields = [
        'seoTitle' => ['column' => 'seo_title', 'max' => 180],
        'metaDescription' => ['column' => 'meta_description', 'max' => 320],
        'ogTitle' => ['column' => 'og_title', 'max' => 180],
        'ogDescription' => ['column' => 'og_description', 'max' => 320],
        'coverAlt' => ['column' => 'cover_alt', 'max' => 255]
    ];
    $seoValues = [];
    foreach ($seoFields as $field => $config) {
        $seoValues[$field] = array_key_exists($field, $_POST)
            ? $contentText($_POST[$field], $field, $config['max'])
            : null;
        if ($seoValues[$field] === '') $seoValues[$field] = null;
    }
    $seoValues['ogImage'] = null;
    if (array_key_exists('ogImage', $_POST) && !is_string($_POST['ogImage'])) {
        json_response(['error' => 'Open Graph image URL must be text.'], 422);
    } elseif (array_key_exists('ogImage', $_POST) && trim($_POST['ogImage']) !== '') {
        $seoValues['ogImage'] = $contentMediaUrl($_POST['ogImage'], 'Open Graph image URL');
    }
    $noindex = null;
    if (array_key_exists('noindex', $_POST)) {
        $noindex = $postBoolean('noindex');
    }

    if (array_key_exists('coverImage', $_POST) && !is_string($_POST['coverImage'])) {
        json_response(['error' => 'Cover image URL must be text.'], 422);
    }
    $coverImage = trim((string)($_POST['coverImage'] ?? ''));
    $hasCoverUpload = isset($_FILES['coverImageFile']) && $_FILES['coverImageFile']['error'] !== UPLOAD_ERR_NO_FILE;
    if (!$hasCoverUpload && $coverImage !== '') $coverImage = $contentMediaUrl($coverImage, 'Cover image URL');
    $uploadedPath = null;
    if ($hasCoverUpload) {
        $file = $_FILES['coverImageFile'];
        if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > MAX_UPLOAD_BYTES) json_response(['error' => 'Cover image upload failed or exceeds 8MB.'], 422);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset($allowed[$mime])) json_response(['error' => 'Cover image must be JPG, PNG, GIF, or WebP.'], 422);
        $folder = PROJECT_UPLOAD_DIR . 'content/';
        if (!is_dir($folder) && !mkdir($folder, 0755, true)) json_response(['error' => 'Content upload directory is unavailable.'], 500);
        $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        $uploadedPath = $folder . $filename;
        if (!move_uploaded_file($file['tmp_name'], $uploadedPath)) json_response(['error' => 'Could not save cover image.'], 500);
        $coverImage = PROJECT_UPLOAD_URL . 'content/' . $filename;
    }
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $existing = null;
        if ($id) {
            $existingStmt = $pdo->prepare('SELECT * FROM content_entries WHERE id = ? FOR UPDATE');
            $existingStmt->execute([$id]);
            $existing = $existingStmt->fetch();
            if (!$existing) {
                $pdo->rollBack();
                if ($uploadedPath && is_file($uploadedPath)) unlink($uploadedPath);
                json_response(['error' => 'Content entry not found.'], 404);
            }
        }
        $duplicate = $pdo->prepare('SELECT id FROM content_entries WHERE slug = ? AND (? IS NULL OR id <> ?) LIMIT 1');
        $duplicate->execute([$slug, $id, $id]);
        if ($duplicate->fetch()) {
            $pdo->rollBack();
            if ($uploadedPath && is_file($uploadedPath)) unlink($uploadedPath);
            json_response(['error' => 'That content slug is already in use.'], 422);
        }
        if ($coverImage === '' && $existing) $coverImage = $existing['cover_image'] ?? '';
        foreach ($seoFields as $field => $config) {
            if ($seoValues[$field] === null && !array_key_exists($field, $_POST) && $existing) {
                $seoValues[$field] = $existing[$config['column']];
            }
        }
        if ($seoValues['ogImage'] === null && !array_key_exists('ogImage', $_POST) && $existing) {
            $seoValues['ogImage'] = $existing['og_image'];
        }
        if ($noindex === null) $noindex = $existing ? (int)$existing['noindex'] : 0;

        if ($id) {
            $stmt = $pdo->prepare('UPDATE content_entries SET slug = ?, title = ?, cover_image = ?, type = ?, excerpt = ?, body = ?, publish_date = ?, status = ?, featured = ?, show_home = ?, show_card = ?, card_size = ?, seo_title = ?, meta_description = ?, og_title = ?, og_description = ?, og_image = ?, noindex = ?, cover_alt = ? WHERE id = ?');
            $stmt->execute([$slug, $title, $coverImage ?: null, $type, $excerpt, $body, $publishDate, $status, $featured, $showHome, $showCard, $cardSize, $seoValues['seoTitle'], $seoValues['metaDescription'], $seoValues['ogTitle'], $seoValues['ogDescription'], $seoValues['ogImage'], $noindex, $seoValues['coverAlt'], $id]);
            $entryId = (int)$id;
        } else {
            $stmt = $pdo->prepare('INSERT INTO content_entries (slug, title, cover_image, type, excerpt, body, publish_date, status, featured, show_home, show_card, card_size, seo_title, meta_description, og_title, og_description, og_image, noindex, cover_alt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$slug, $title, $coverImage ?: null, $type, $excerpt, $body, $publishDate, $status, $featured, $showHome, $showCard, $cardSize, $seoValues['seoTitle'], $seoValues['metaDescription'], $seoValues['ogTitle'], $seoValues['ogDescription'], $seoValues['ogImage'], $noindex, $seoValues['coverAlt']]);
            $entryId = (int)$pdo->lastInsertId();
        }

        if ($blocksSubmitted) {
            $deleteBlocks = $pdo->prepare('DELETE FROM content_blocks WHERE content_entry_id = ?');
            $deleteBlocks->execute([$entryId]);
            $insertBlock = $pdo->prepare('INSERT INTO content_blocks (content_entry_id, block_order, block_type, payload) VALUES (?, ?, ?, ?)');
            foreach ($blocks as $order => $block) {
                $payload = json_encode($block['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $insertBlock->execute([$entryId, $order, $block['type'], $payload]);
            }
        }
        $pdo->commit();
        json_response([$id ? 'updated' : 'created' => true, 'id' => $entryId], $id ? 200 : 201);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($uploadedPath && is_file($uploadedPath)) unlink($uploadedPath);
        if ($error instanceof PDOException && (string)$error->getCode() === '23000' && (int)($error->errorInfo[1] ?? 0) === 1062) {
            json_response(['error' => 'That content slug is already in use.'], 422);
        }
        throw $error;
    }
}

if ($action === 'delete-content') {
    require_post();
    require_auth();
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) json_response(['error' => 'Invalid content entry.'], 422);
    $stmt = db()->prepare('DELETE FROM content_entries WHERE id = ?');
    $stmt->execute([$id]);
    json_response(['deleted' => $stmt->rowCount() > 0]);
}

if ($action === 'projects' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $showHomeOnly = ($_GET['home'] ?? '') === '1';
    $sql = 'SELECT id, title, slug, category, image_url AS image, image_urls, alt_text AS altText, description, show_home AS showHome, sort_order AS sortOrder, display_size AS displaySize FROM projects';
    if ($showHomeOnly) $sql .= ' WHERE show_home = 1';
    $sql .= ' ORDER BY sort_order ASC, created_at DESC, id DESC';
    $projects = db()->query($sql)->fetchAll();
    foreach ($projects as &$project) {
        $project['images'] = json_decode($project['image_urls'] ?? '', true) ?: [$project['image']];
        unset($project['image_urls']);
    }
    json_response(['projects' => $projects]);
}

if ($action === 'login') {
    require_post();
    $username = request_string('username', 80);
    $password = (string)($_POST['password'] ?? '');
    if (!hash_equals(ADMIN_USERNAME, $username) || !password_verify($password, ADMIN_PASSWORD_HASH)) {
        json_response(['error' => 'That login did not match.'], 422);
    }
    session_regenerate_id(true);
    $_SESSION['admin_id'] = ADMIN_USERNAME;
    json_response(['authenticated' => true]);
}

if ($action === 'logout') {
    require_post();
    $_SESSION = [];
    session_destroy();
    json_response(['authenticated' => false]);
}

if ($action === 'project') {
    require_post();
    require_auth();
    $title = request_string('title', 160);
    $slug = trim((string)($_POST['slug'] ?? ''));
    $slug = strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', $slug ?: $title));
    $slug = trim($slug, '-');
    if ($slug === '') json_response(['error' => 'Enter a valid project slug.'], 422);
    $altText = trim((string)($_POST['altText'] ?? '')) ?: $title;
    if (strlen($altText) > 255) json_response(['error' => 'Alt text is too long.'], 422);
    $sortOrder = filter_var($_POST['sortOrder'] ?? null, FILTER_VALIDATE_INT);
    $projectId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (($sortOrder === false || $sortOrder === null || $sortOrder < 0) && $projectId) {
        $existingOrder = db()->prepare('SELECT sort_order FROM projects WHERE id = ?');
        $existingOrder->execute([$projectId]);
        $sortOrder = $existingOrder->fetchColumn();
        if ($sortOrder === false) json_response(['error' => 'Project not found.'], 404);
    }
    if ($sortOrder === false || $sortOrder === null || $sortOrder < 0) {
        $sortOrder = (int)db()->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM projects')->fetchColumn();
    }
    $category = request_string('category', 80);
    $description = request_string('description', 2000);
    $showHome = ($_POST['showHome'] ?? '') === '1' ? 1 : 0;
    $displaySize = (string)($_POST['displaySize'] ?? '');
    if ($displaySize === '' && $projectId) {
        $existingSize = db()->prepare('SELECT display_size FROM projects WHERE id = ?');
        $existingSize->execute([$projectId]);
        $displaySize = (string)($existingSize->fetchColumn() ?: 'standard');
    }
    if ($displaySize === '') $displaySize = 'standard';
    if (!in_array($displaySize, ['standard', 'wide', 'tall', 'featured'], true)) json_response(['error' => 'Invalid project display size.'], 422);

    $folders = [
        'Illustration' => 'illustration',
        'Graphic Design' => 'graphic-design',
        'Portraits' => 'portraits',
        'Logos' => 'logos'
    ];
    if (!isset($folders[$category])) json_response(['error' => 'Invalid category.'], 422);
    $files = $_FILES['imageFiles'] ?? null;
    if (!$files || !isset($files['name'])) {
        if (!$projectId) json_response(['error' => 'Choose at least one image file.'], 422);
        $files = ['name' => []];
    } elseif (!is_array($files['name'])) {
        foreach ($files as $key => $value) $files[$key] = [$value];
    }
    $uploadIndexes = [];
    foreach ($files['name'] as $index => $name) {
        if ($files['error'][$index] !== UPLOAD_ERR_NO_FILE && $name !== '') $uploadIndexes[] = $index;
    }
    if (!$uploadIndexes && !$projectId) json_response(['error' => 'Choose at least one image file.'], 422);
    $fileCount = count($uploadIndexes);
    if ($fileCount > MAX_PROJECT_IMAGES) json_response(['error' => 'A project can have up to 12 images.'], 422);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $imageUrls = [];
    $folder = PROJECT_UPLOAD_DIR . $folders[$category] . '/';
    if ($fileCount && !is_dir($folder) && !mkdir($folder, 0755, true)) json_response(['error' => 'Upload directory is unavailable.'], 500);
    foreach ($uploadIndexes as $index) {
        if ($files['error'][$index] !== UPLOAD_ERR_OK) json_response(['error' => 'One of the selected images could not be uploaded.'], 422);
        if ($files['size'][$index] > MAX_UPLOAD_BYTES) json_response(['error' => 'Each image must be 8MB or smaller.'], 422);
        $mime = $finfo->file($files['tmp_name'][$index]);
        if (!isset($allowed[$mime])) json_response(['error' => 'Only JPG, PNG, GIF, or WebP images are allowed.'], 422);
        $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($files['tmp_name'][$index], $folder . $filename)) json_response(['error' => 'Could not save image.'], 500);
        $imageUrls[] = PROJECT_UPLOAD_URL . $folders[$category] . '/' . $filename;
    }
    if ($projectId) {
        $slugCheck = db()->prepare('SELECT id FROM projects WHERE slug = ? AND id <> ? LIMIT 1');
        $slugCheck->execute([$slug, $projectId]);
        if ($slugCheck->fetch()) json_response(['error' => 'That project slug is already in use.'], 422);
        if (!$imageUrls) {
            $existing = db()->prepare('SELECT image_url, image_urls FROM projects WHERE id = ?');
            $existing->execute([$projectId]);
            $current = $existing->fetch();
            if (!$current) json_response(['error' => 'Project not found.'], 404);
            $imageUrls = json_decode($current['image_urls'] ?? '', true) ?: [$current['image_url']];
        }
        $stmt = db()->prepare('UPDATE projects SET title = ?, slug = ?, category = ?, image_url = ?, image_urls = ?, alt_text = ?, description = ?, show_home = ?, sort_order = ?, display_size = ? WHERE id = ?');
        $stmt->execute([$title, $slug, $category, $imageUrls[0], json_encode($imageUrls), $altText, $description, $showHome, $sortOrder, $displaySize, $projectId]);
        json_response(['updated' => true]);
    }
    $slugCheck = db()->prepare('SELECT id FROM projects WHERE slug = ? LIMIT 1');
    $slugCheck->execute([$slug]);
    if ($slugCheck->fetch()) json_response(['error' => 'That project slug is already in use.'], 422);
    $stmt = db()->prepare('INSERT INTO projects (title, slug, category, image_url, image_urls, alt_text, description, show_home, sort_order, display_size) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$title, $slug, $category, $imageUrls[0], json_encode($imageUrls), $altText, $description, $showHome, $sortOrder, $displaySize]);
    json_response(['project' => ['id' => db()->lastInsertId(), 'title' => $title, 'slug' => $slug, 'category' => $category, 'image' => $imageUrls[0], 'images' => $imageUrls, 'altText' => $altText, 'description' => $description, 'showHome' => (bool)$showHome, 'sortOrder' => $sortOrder, 'displaySize' => $displaySize]], 201);
}

if ($action === 'delete-project') {
    require_post();
    require_auth();
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) json_response(['error' => 'Invalid project.'], 422);
    $stmt = db()->prepare('DELETE FROM projects WHERE id = ?');
    $stmt->execute([$id]);
    json_response(['deleted' => $stmt->rowCount() > 0]);
}

if ($action === 'product-image-upload') {
    require_post();
    require_auth();
    $id = shop_positive_integer($_POST['id'] ?? null, 'Product ID');
    $altText = shop_text($_POST['altText'] ?? '', 'Image alt text', 255);
    $pdo = db();
    $stored = shop_store_uploaded_image($_FILES['imageFile'] ?? null);
    try {
        $pdo->beginTransaction();
        $product = $pdo->prepare('SELECT id FROM shop_products WHERE id = ? FOR UPDATE');
        $product->execute([$id]);
        if (!$product->fetch()) throw new DomainException('Product not found.');
        $count = $pdo->prepare('SELECT COUNT(*) FROM shop_product_images WHERE product_id = ?');
        $count->execute([$id]);
        $position = (int)$count->fetchColumn() + 1;
        if ($position > SHOP_MAX_IMAGES) throw new DomainException('A product can have no more than ' . SHOP_MAX_IMAGES . ' images.');
        $insert = $pdo->prepare('INSERT INTO shop_product_images (product_id, image_path, alt_text, sort_order) VALUES (?, ?, ?, ?)');
        $insert->execute([$id, $stored['path'], $altText, $position]);
        if ($position === 1) {
            $primary = $pdo->prepare('UPDATE shop_products SET image_url = ? WHERE id = ?');
            $primary->execute([$stored['path'], $id]);
        }
        $pdo->commit();
        json_response(['uploaded' => true, 'product' => shop_fetch_product($pdo, $id, true)], 201);
    } catch (DomainException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (is_file($stored['diskPath'])) unlink($stored['diskPath']);
        json_response(['error' => $error->getMessage()], 422);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (is_file($stored['diskPath'])) unlink($stored['diskPath']);
        throw $error;
    }
}

if ($action === 'product') {
    require_post();
    require_auth();
    $pdo = db();
    $id = isset($_POST['id']) && $_POST['id'] !== '' ? shop_positive_integer($_POST['id'], 'Product ID') : null;
    $current = null;
    $existingImages = [];
    $existingBadges = [];
    if ($id !== null) {
        $stmt = $pdo->prepare('SELECT ' . shop_product_columns() . ' FROM shop_products WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $current = $stmt->fetch();
        if (!$current) json_response(['error' => 'Product not found.'], 404);
        $relations = shop_fetch_relations($pdo, [$id]);
        foreach ($relations['images'][$id] ?? [] as $image) {
            $existingImages[] = ['id' => (int)$image['id'], 'path' => $image['image_path'], 'altText' => $image['alt_text'], 'sortOrder' => (int)$image['sort_order']];
        }
        foreach ($relations['badges'][$id] ?? [] as $badge) {
            $existingBadges[] = ['label' => $badge['label'], 'sortOrder' => (int)$badge['sort_order']];
        }
    }

    $required = static function (string $key) use ($current): mixed {
        if (array_key_exists($key, $_POST)) return $_POST[$key];
        if ($current !== null) return $current[$key];
        json_response(['error' => "{$key} is required."], 422);
    };
    $optional = static function (string $postKey, string $column, mixed $default) use ($current): mixed {
        if (array_key_exists($postKey, $_POST)) return $_POST[$postKey];
        return $current !== null ? $current[$column] : $default;
    };

    $sku = shop_sku($required('sku'));
    $title = shop_text($required('title'), 'Title', 160, false);
    $slug = array_key_exists('slug', $_POST)
        ? shop_slug($_POST['slug'])
        : ($current !== null ? $current['slug'] : shop_slug_from_title($title));
    $shortDescription = shop_text($optional('shortDescription', 'short_description', mb_substr((string)$required('description'), 0, 500, 'UTF-8')), 'Short description', 500);
    $description = shop_text($required('description'), 'Description', 65535);
    if (strlen($description) > 65535) json_response(['error' => 'Description is too large to store.'], 422);
    $category = shop_optional_text($optional('category', 'category', null), 'Category', 80);
    $productType = shop_enum($optional('productType', 'product_type', 'physical'), 'product type', ['physical', 'digital']);
    $regular = shop_money($required('price'), 'Regular price');
    $saleRaw = $optional('salePrice', 'sale_price', null);
    $sale = $saleRaw === null || $saleRaw === '' ? null : shop_money($saleRaw, 'Sale price');
    if ($sale !== null && ($sale['cents'] <= 0 || $sale['cents'] >= $regular['cents'])) {
        json_response(['error' => 'Sale price must be positive and lower than regular price.'], 422);
    }
    $stock = shop_unsigned_integer($required('stock'), 'Stock');
    $publicationStatus = shop_enum($optional('publicationStatus', 'publication_status', 'published'), 'publication status', ['draft', 'published']);
    $storefrontVisible = shop_boolean($optional('storefrontVisible', 'storefront_visible', 1), 'storefront visibility');
    $showWhenSoldOut = shop_boolean($optional('showWhenSoldOut', 'show_when_sold_out', 1), 'sold-out visibility');
    $featured = shop_boolean($optional('featured', 'featured', 0), 'featured');
    if (array_key_exists('sortOrder', $_POST)) {
        $sortOrder = shop_unsigned_integer($_POST['sortOrder'], 'Sort order');
    } elseif ($current !== null) {
        $sortOrder = (int)$current['sort_order'];
    } else {
        $sortStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM shop_products');
        $sortStmt->execute();
        $sortOrder = (int)$sortStmt->fetchColumn();
    }
    $purchaseAction = shop_enum($optional('purchaseAction', 'purchase_action', 'internal'), 'purchase action', ['internal', 'external', 'inquiry']);
    if ($purchaseAction === 'external') {
        $externalUrl = shop_external_url($optional('externalUrl', 'external_url', null));
    } else {
        $externalUrl = null;
    }

    $replaceImages = array_key_exists('images', $_POST);
    $images = $replaceImages ? shop_images($_POST['images']) : $existingImages;
    $uploadedPath = null;
    $uploadedDiskPath = null;
    if (isset($_FILES['imageFile'])) {
        $uploadError = (int)($_FILES['imageFile']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK && $uploadError !== UPLOAD_ERR_NO_FILE) json_response(['error' => 'Product image upload failed.'], 422);
        if ($uploadError === UPLOAD_ERR_OK) {
            if ($replaceImages) json_response(['error' => 'Submit either an image upload or the ordered images list, not both.'], 422);
            $stored = shop_store_uploaded_image($_FILES['imageFile']);
            $uploadedPath = $stored['path'];
            $uploadedDiskPath = $stored['diskPath'];
        }
    }
    $legacyImage = trim((string)($_POST['image'] ?? ''));
    if ($replaceImages && $legacyImage !== '') json_response(['error' => 'Submit either image or the ordered images list, not both.'], 422);
    $primaryReplacement = $uploadedPath ?? ($legacyImage !== '' ? shop_image_path($legacyImage) : null);
    if (!$replaceImages && $primaryReplacement !== null) {
        if ($images) {
            if ($images[0]['path'] !== $primaryReplacement) {
                $images[0]['path'] = $primaryReplacement;
                $replaceImages = true;
            }
        } else {
            $images[] = ['path' => $primaryReplacement, 'altText' => '', 'sortOrder' => 1];
            $replaceImages = true;
        }
    }
    if ($publicationStatus === 'published' && !$images) json_response(['error' => 'Published products require at least one image.'], 422);
    $imageUrl = $images[0]['path'] ?? '';

    $replaceBadges = array_key_exists('manualBadges', $_POST);
    $manualBadges = $replaceBadges ? shop_manual_badges($_POST['manualBadges']) : $existingBadges;

    if ($publicationStatus === 'published' && $description === '') json_response(['error' => 'Published products require a full description.'], 422);

    $pdo->beginTransaction();
    try {
        $duplicateSku = $pdo->prepare('SELECT id FROM shop_products WHERE sku = ? AND (? IS NULL OR id <> ?) LIMIT 1');
        $duplicateSku->execute([$sku, $id, $id]);
        if ($duplicateSku->fetch()) throw new DomainException('That SKU is already in use.');
        $duplicateSlug = $pdo->prepare('SELECT id FROM shop_products WHERE slug = ? AND (? IS NULL OR id <> ?) LIMIT 1');
        $duplicateSlug->execute([$slug, $id, $id]);
        if ($duplicateSlug->fetch()) throw new DomainException('That product slug is already in use.');

        $values = [
            $sku, $slug, $title, $shortDescription, $description, $category, $productType,
            $imageUrl, $regular['value'], $sale['value'] ?? null, $stock, $publicationStatus,
            $storefrontVisible, $showWhenSoldOut, $featured, $sortOrder, $purchaseAction, $externalUrl,
        ];
        if ($id !== null) {
            $stmt = $pdo->prepare('UPDATE shop_products SET sku = ?, slug = ?, title = ?, short_description = ?, description = ?, category = ?, product_type = ?, image_url = ?, price = ?, sale_price = ?, stock = ?, publication_status = ?, storefront_visible = ?, show_when_sold_out = ?, featured = ?, sort_order = ?, purchase_action = ?, external_url = ? WHERE id = ?');
            $stmt->execute([...$values, $id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO shop_products (sku, slug, title, short_description, description, category, product_type, image_url, price, sale_price, stock, publication_status, storefront_visible, show_when_sold_out, featured, sort_order, purchase_action, external_url, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)');
            $stmt->execute($values);
            $id = (int)$pdo->lastInsertId();
            $replaceImages = true;
            $replaceBadges = true;
        }

        if ($replaceImages) {
            shop_sync_product_images($pdo, $id, $images);
        }
        if ($replaceBadges) {
            $deleteBadges = $pdo->prepare('DELETE FROM shop_product_badges WHERE product_id = ?');
            $deleteBadges->execute([$id]);
            $insertBadge = $pdo->prepare('INSERT INTO shop_product_badges (product_id, label, sort_order) VALUES (?, ?, ?)');
            foreach ($manualBadges as $badge) $insertBadge->execute([$id, $badge['label'], $badge['sortOrder']]);
        }

        $pdo->commit();
        $product = shop_fetch_product($pdo, $id, true);
        json_response(['id' => $id, 'updated' => $current !== null, 'product' => $product], $current !== null ? 200 : 201);
    } catch (DomainException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($uploadedDiskPath && is_file($uploadedDiskPath)) unlink($uploadedDiskPath);
        json_response(['error' => $error->getMessage()], 422);
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($uploadedDiskPath && is_file($uploadedDiskPath)) unlink($uploadedDiskPath);
        if ($error->getCode() === '23000') json_response(['error' => 'Product data conflicts with an existing value or constraint.'], 422);
        throw $error;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($uploadedDiskPath && is_file($uploadedDiskPath)) unlink($uploadedDiskPath);
        throw $error;
    }
}

if ($action === 'delete-product') {
    require_post();
    require_auth();
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) json_response(['error' => 'Invalid product.'], 422);
    $pdo = db();
    $orderCheck = $pdo->prepare('SELECT 1 FROM shop_order_items WHERE product_id = ? LIMIT 1');
    $orderCheck->execute([$id]);
    if ($orderCheck->fetchColumn()) {
        json_response(['error' => 'This product belongs to an existing order and cannot be deleted. Unpublish it instead.'], 409);
    }
    $stmt = $pdo->prepare('DELETE FROM shop_products WHERE id = ?');
    try {
        $stmt->execute([$id]);
    } catch (PDOException $error) {
        if ($error->getCode() === '23000') {
            json_response(['error' => 'This product is still referenced and cannot be deleted. Unpublish it instead.'], 409);
        }
        throw $error;
    }
    json_response(['deleted' => $stmt->rowCount() > 0]);
}

if ($action === 'visibility') {
    require_post();
    require_auth();
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) json_response(['error' => 'Invalid project.'], 422);
    $stmt = db()->prepare('UPDATE projects SET show_home = ? WHERE id = ?');
    $stmt->execute([($_POST['showHome'] ?? '') === '1' ? 1 : 0, $id]);
    json_response(['updated' => true]);
}

json_response(['error' => 'Unknown action.'], 404);
