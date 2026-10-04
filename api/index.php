<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/config.php';

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
    $stmt = db()->query('SELECT id, sku, title, description, image_url AS image, price, stock FROM shop_products WHERE active = 1 ORDER BY created_at DESC');
    json_response(['products' => $stmt->fetchAll()]);
}

if ($action === 'admin-products' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_auth();
    json_response(['products' => db()->query('SELECT id, sku, title, description, image_url AS image, price, stock, active FROM shop_products ORDER BY created_at DESC')->fetchAll()]);
}

if ($action === 'order') {
    require_post();
    $customerName = request_string('customerName', 160);
    $customerEmail = filter_var(trim((string)($_POST['customerEmail'] ?? '')), FILTER_VALIDATE_EMAIL);
    $customerAddress = request_string('customerAddress', 1000);
    $items = json_decode((string)($_POST['items'] ?? ''), true);
    if (!$customerEmail || !is_array($items) || !$items) json_response(['error' => 'Complete your contact details and cart.'], 422);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $productStmt = $pdo->prepare('SELECT id, price, stock FROM shop_products WHERE id = ? AND active = 1 FOR UPDATE');
        $orderItems = [];
        $total = 0.0;
        foreach ($items as $item) {
            $productId = filter_var($item['id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
            if (!$productId || !$quantity || $quantity < 1) throw new RuntimeException('Invalid cart item.');
            $productStmt->execute([$productId]);
            $product = $productStmt->fetch();
            if (!$product || $quantity > (int)$product['stock']) throw new RuntimeException('One item is out of stock.');
            $total += (float)$product['price'] * $quantity;
            $orderItems[] = [$productId, $quantity, $product['price']];
        }
        $orderStmt = $pdo->prepare('INSERT INTO shop_orders (customer_name, customer_email, customer_address, total) VALUES (?, ?, ?, ?)');
        $orderStmt->execute([$customerName, $customerEmail, $customerAddress, $total]);
        $orderId = $pdo->lastInsertId();
        $itemStmt = $pdo->prepare('INSERT INTO shop_order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)');
        $stockStmt = $pdo->prepare('UPDATE shop_products SET stock = stock - ? WHERE id = ?');
        foreach ($orderItems as [$productId, $quantity, $price]) {
            $itemStmt->execute([$orderId, $productId, $quantity, $price]);
            $stockStmt->execute([$quantity, $productId]);
        }
        $pdo->commit();
        json_response(['order' => ['id' => $orderId, 'total' => number_format($total, 2, '.', '')]], 201);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(['error' => $error->getMessage()], 422);
    }
}

if ($action === 'session' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(['authenticated' => !empty($_SESSION['admin_id'])]);
}

if ($action === 'theme' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Cache-Control: no-store, max-age=0');
    json_response(['theme' => $readTheme()]);
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

if ($action === 'product') {
    require_post();
    require_auth();
    $title = request_string('title', 160);
    $sku = request_string('sku', 80);
    $description = request_string('description', 2000);
    $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
    $stock = filter_var($_POST['stock'] ?? null, FILTER_VALIDATE_INT);
    if ($price === false || $price < 0 || $stock === false || $stock < 0) json_response(['error' => 'Enter a valid price and stock quantity.'], 422);
    $imageUrl = trim((string)($_POST['image'] ?? ''));
    if (isset($_FILES['imageFile']) && $_FILES['imageFile']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['imageFile'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        if ($file['size'] > MAX_UPLOAD_BYTES || !isset($allowed[$mime])) json_response(['error' => 'Product image must be a JPG, PNG, GIF, or WebP under 8MB.'], 422);
        $folder = PROJECT_UPLOAD_DIR . 'shop/';
        if (!is_dir($folder) && !mkdir($folder, 0755, true)) json_response(['error' => 'Shop upload directory is unavailable.'], 500);
        $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($file['tmp_name'], $folder . $filename)) json_response(['error' => 'Could not save product image.'], 500);
        $imageUrl = PROJECT_UPLOAD_URL . 'shop/' . $filename;
    }
    if ($imageUrl === '') json_response(['error' => 'Add a product image.'], 422);
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    if ($id) {
        $stmt = db()->prepare('UPDATE shop_products SET sku = ?, title = ?, description = ?, image_url = ?, price = ?, stock = ?, active = ? WHERE id = ?');
        $stmt->execute([$sku, $title, $description, $imageUrl, $price, $stock, ($_POST['active'] ?? '1') === '1' ? 1 : 0, $id]);
        json_response(['updated' => true]);
    }
    $stmt = db()->prepare('INSERT INTO shop_products (sku, title, description, image_url, price, stock, active) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$sku, $title, $description, $imageUrl, $price, $stock, 1]);
    json_response(['id' => db()->lastInsertId()], 201);
}

if ($action === 'delete-product') {
    require_post();
    require_auth();
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) json_response(['error' => 'Invalid product.'], 422);
    $stmt = db()->prepare('DELETE FROM shop_products WHERE id = ?');
    $stmt->execute([$id]);
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
