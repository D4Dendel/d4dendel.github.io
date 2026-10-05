<?php
declare(strict_types=1);

const SHOP_MAX_IMAGES = 12;
const SHOP_MAX_MANUAL_BADGES = 3;
const SHOP_MAX_MONEY_CENTS = 9999999999;

function shop_text(mixed $value, string $field, int $maxLength, bool $allowEmpty = true): string
{
    if (!is_string($value)) json_response(['error' => "{$field} must be text."], 422);
    $value = trim($value);
    if (preg_match('//u', $value) !== 1) json_response(['error' => "{$field} must be valid UTF-8 text."], 422);
    if (!$allowEmpty && $value === '') json_response(['error' => "{$field} cannot be empty."], 422);
    if (mb_strlen($value, 'UTF-8') > $maxLength) json_response(['error' => "{$field} must be {$maxLength} characters or fewer."], 422);
    return $value;
}

function shop_optional_text(mixed $value, string $field, int $maxLength): ?string
{
    if ($value === null || $value === '') return null;
    return shop_text($value, $field, $maxLength);
}

function shop_sku(mixed $value): string
{
    $sku = shop_text($value, 'SKU', 80, false);
    if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $sku)) {
        json_response(['error' => 'SKU may contain only letters, numbers, periods, underscores, and hyphens.'], 422);
    }
    return $sku;
}

function shop_slug(mixed $value): string
{
    $slug = shop_text($value, 'Slug', 180, false);
    if (!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $slug)) {
        json_response(['error' => 'Slug must contain lowercase letters, numbers, and single hyphens only.'], 422);
    }
    return $slug;
}

function shop_slug_from_title(string $title): string
{
    $slug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
    $slug = substr($slug, 0, 180);
    if ($slug === '') json_response(['error' => 'Provide a slug for a title without Latin letters or numbers.'], 422);
    return $slug;
}

function shop_enum(mixed $value, string $field, array $allowed): string
{
    if (!is_string($value) || !in_array($value, $allowed, true)) {
        json_response(['error' => "Invalid {$field} value."], 422);
    }
    return $value;
}

function shop_boolean(mixed $value, string $field): int
{
    if (in_array($value, ['1', 1, true, 'true', 'on'], true)) return 1;
    if (in_array($value, ['0', 0, false, 'false', 'off', ''], true)) return 0;
    json_response(['error' => "Invalid {$field} value."], 422);
}

function shop_unsigned_integer(mixed $value, string $field, int $max = 4294967295): int
{
    if (is_int($value)) {
        $normalized = (string)$value;
    } elseif (is_string($value)) {
        $normalized = trim($value);
    } else {
        json_response(['error' => "{$field} must be a whole number."], 422);
    }
    if (!preg_match('/\A\d+\z/D', $normalized)) json_response(['error' => "{$field} must be a whole number."], 422);
    $normalized = ltrim($normalized, '0');
    $normalized = $normalized === '' ? '0' : $normalized;
    if (strlen($normalized) > strlen((string)$max) || (strlen($normalized) === strlen((string)$max) && strcmp($normalized, (string)$max) > 0)) {
        json_response(['error' => "{$field} is too large."], 422);
    }
    return (int)$normalized;
}

function shop_positive_integer(mixed $value, string $field): int
{
    $number = shop_unsigned_integer($value, $field);
    if ($number < 1) json_response(['error' => "{$field} must be at least 1."], 422);
    return $number;
}

/** @return array{value:string,cents:int} */
function shop_money(mixed $value, string $field): array
{
    if (!is_string($value) && !is_int($value)) json_response(['error' => "{$field} must be an exact decimal amount."], 422);
    $raw = trim((string)$value);
    if (!preg_match('/\A\d+(?:\.\d{1,2})?\z/D', $raw)) {
        json_response(['error' => "{$field} must be a non-negative decimal with no more than two decimal places."], 422);
    }
    [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
    $whole = ltrim($whole, '0');
    $whole = $whole === '' ? '0' : $whole;
    if (strlen($whole) > 8) json_response(['error' => "{$field} cannot exceed 99999999.99."], 422);
    $fraction = str_pad($fraction, 2, '0');
    $cents = ((int)$whole * 100) + (int)$fraction;
    if ($cents > SHOP_MAX_MONEY_CENTS) json_response(['error' => "{$field} cannot exceed 99999999.99."], 422);
    return ['value' => sprintf('%d.%02d', intdiv($cents, 100), $cents % 100), 'cents' => $cents];
}

function shop_money_from_cents(int $cents): string
{
    return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
}

function shop_external_url(mixed $value): string
{
    $url = shop_text($value, 'External URL', 2048, false);
    if (preg_match('/[\x00-\x20\x7f]/', $url)) json_response(['error' => 'External URL contains invalid characters.'], 422);
    $parts = parse_url($url);
    if ($parts === false || !filter_var($url, FILTER_VALIDATE_URL)) json_response(['error' => 'External URL must be a valid absolute HTTP(S) URL.'], 422);
    if (!in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        json_response(['error' => 'External URL must be an absolute HTTP(S) URL without credentials.'], 422);
    }
    return $url;
}

function shop_image_path(mixed $value): string
{
    $path = shop_text($value, 'Image path', 255, false);
    if (preg_match('/[\x00-\x20\x7f]/', $path)) json_response(['error' => 'Image path contains invalid characters.'], 422);
    $parts = parse_url($path);
    if ($parts === false) json_response(['error' => 'Image path is invalid.'], 422);
    if (isset($parts['scheme']) || isset($parts['host'])) {
        if (!filter_var($path, FILTER_VALIDATE_URL) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            json_response(['error' => 'Image path must be a valid HTTP(S) URL without credentials.'], 422);
        }
        return $path;
    }
    $urlPath = explode('?', explode('#', $path, 2)[0], 2)[0];
    if ($urlPath === '' || str_starts_with($urlPath, '/') || str_contains($path, '\\') || !preg_match("#\A[A-Za-z0-9][A-Za-z0-9._~!$&'()*+,;=:@%/?\\x23-]*\z#D", $path)) {
        json_response(['error' => 'Image path must be a valid site-relative path.'], 422);
    }
    if (preg_match('/%(?![a-f0-9]{2})/i', $urlPath)) json_response(['error' => 'Image path contains invalid URL encoding.'], 422);
    $decodedPath = $urlPath;
    for ($count = 0; $count < 8; $count++) {
        foreach (explode('/', $decodedPath) as $segment) {
            if ($segment === '.' || $segment === '..') json_response(['error' => 'Image path cannot traverse directories.'], 422);
        }
        if (str_contains($decodedPath, '\\') || preg_match('/[\x00-\x1f\x7f]/', $decodedPath)) json_response(['error' => 'Image path contains invalid characters.'], 422);
        $decoded = rawurldecode($decodedPath);
        if ($decoded === $decodedPath) return $path;
        $decodedPath = $decoded;
    }
    json_response(['error' => 'Image path contains excessive URL encoding.'], 422);
}

function shop_json_list(mixed $raw, string $field): array
{
    if (!is_string($raw) || strlen($raw) > 131072) json_response(['error' => "{$field} must be a JSON list no larger than 128 KB."], 422);
    try {
        $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        json_response(['error' => "{$field} must contain valid JSON."], 422);
    }
    if (!is_array($value) || !array_is_list($value)) json_response(['error' => "{$field} must be a JSON list."], 422);
    return $value;
}

function shop_validate_ordering(array $positions, string $field): void
{
    sort($positions, SORT_NUMERIC);
    if ($positions !== range(1, count($positions))) json_response(['error' => "{$field} must use each position from 1 through " . count($positions) . '.'], 422);
}

function shop_images(mixed $raw): array
{
    $items = shop_json_list($raw, 'Images');
    if (count($items) > SHOP_MAX_IMAGES) json_response(['error' => 'A product can have no more than ' . SHOP_MAX_IMAGES . ' images.'], 422);
    $images = [];
    $positions = [];
    foreach ($items as $index => $item) {
        if (!is_array($item) || array_is_list($item) || array_diff(array_keys($item), ['id', 'path', 'altText', 'sortOrder'])) {
            json_response(['error' => 'Each image must contain path, altText, and sortOrder.', 'image' => $index], 422);
        }
        if (!array_key_exists('path', $item) || !array_key_exists('altText', $item) || !array_key_exists('sortOrder', $item)) {
            json_response(['error' => 'Each image must contain path, altText, and sortOrder.', 'image' => $index], 422);
        }
        $sortOrder = shop_positive_integer($item['sortOrder'], 'Image sort order');
        $images[] = [
            'path' => shop_image_path($item['path']),
            'altText' => shop_text($item['altText'], 'Image alt text', 255),
            'sortOrder' => $sortOrder,
        ];
        $positions[] = $sortOrder;
    }
    if ($positions) shop_validate_ordering($positions, 'Image ordering');
    usort($images, static fn(array $left, array $right): int => $left['sortOrder'] <=> $right['sortOrder']);
    return $images;
}

function shop_manual_badges(mixed $raw): array
{
    $items = shop_json_list($raw, 'Manual badges');
    if (count($items) > SHOP_MAX_MANUAL_BADGES) json_response(['error' => 'A product can have no more than ' . SHOP_MAX_MANUAL_BADGES . ' manual badges.'], 422);
    $badges = [];
    $labels = [];
    $positions = [];
    $reserved = ['sale', 'sold out', 'featured'];
    foreach ($items as $index => $item) {
        if (!is_array($item) || array_is_list($item) || array_diff(array_keys($item), ['id', 'label', 'sortOrder'])) {
            json_response(['error' => 'Each manual badge must contain label and sortOrder.', 'badge' => $index], 422);
        }
        if (!array_key_exists('label', $item) || !array_key_exists('sortOrder', $item)) {
            json_response(['error' => 'Each manual badge must contain label and sortOrder.', 'badge' => $index], 422);
        }
        $label = shop_text($item['label'], 'Badge label', 32, false);
        $label = (string)preg_replace('/\s+/u', ' ', $label);
        if (preg_match('/[\x00-\x1f\x7f]/', $label)) json_response(['error' => 'Badge label contains invalid characters.'], 422);
        $normalized = mb_strtolower($label, 'UTF-8');
        if (in_array($normalized, $reserved, true)) json_response(['error' => "{$label} is a reserved derived badge."], 422);
        if (isset($labels[$normalized])) json_response(['error' => 'Manual badge labels must be unique.'], 422);
        $sortOrder = shop_positive_integer($item['sortOrder'], 'Badge sort order');
        $labels[$normalized] = true;
        $positions[] = $sortOrder;
        $badges[] = ['label' => $label, 'sortOrder' => $sortOrder];
    }
    if ($positions) shop_validate_ordering($positions, 'Badge ordering');
    usort($badges, static fn(array $left, array $right): int => $left['sortOrder'] <=> $right['sortOrder']);
    return $badges;
}

function shop_product_columns(): string
{
    return 'id, sku, slug, title, short_description, description, category, product_type, image_url, price, sale_price, stock, publication_status, storefront_visible, show_when_sold_out, featured, sort_order, purchase_action, external_url, active, created_at, updated_at';
}

function shop_fetch_relations(PDO $pdo, array $productIds): array
{
    if (!$productIds) return ['images' => [], 'badges' => []];
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $imageStmt = $pdo->prepare("SELECT id, product_id, image_path, alt_text, sort_order FROM shop_product_images WHERE product_id IN ({$placeholders}) ORDER BY product_id, sort_order, id");
    $imageStmt->execute($productIds);
    $badgeStmt = $pdo->prepare("SELECT id, product_id, label, sort_order FROM shop_product_badges WHERE product_id IN ({$placeholders}) ORDER BY product_id, sort_order, id");
    $badgeStmt->execute($productIds);
    $relations = ['images' => [], 'badges' => []];
    foreach ($imageStmt->fetchAll() as $image) $relations['images'][(int)$image['product_id']][] = $image;
    foreach ($badgeStmt->fetchAll() as $badge) $relations['badges'][(int)$badge['product_id']][] = $badge;
    return $relations;
}

function shop_product_response(array $row, array $images, array $manualBadges, bool $admin = false): array
{
    $regular = shop_money($row['price'], 'Stored regular price');
    $sale = $row['sale_price'] === null ? null : shop_money($row['sale_price'], 'Stored sale price');
    $onSale = $sale !== null && $sale['cents'] > 0 && $sale['cents'] < $regular['cents'];
    $currentCents = $onSale ? $sale['cents'] : $regular['cents'];
    $discountCents = $regular['cents'] - $currentCents;
    $discountPercentage = $onSale && $regular['cents'] > 0 ? intdiv($discountCents * 100, $regular['cents']) : 0;
    $available = (int)$row['stock'] > 0;

    $formattedImages = array_map(static fn(array $image): array => [
        'id' => (int)$image['id'],
        'path' => $image['image_path'],
        'altText' => $image['alt_text'],
        'sortOrder' => (int)$image['sort_order'],
    ], $images);
    $primaryImage = $formattedImages[0]['path'] ?? $row['image_url'];

    $badges = [];
    if ($onSale) $badges[] = ['key' => 'sale', 'label' => 'Sale', 'source' => 'derived'];
    if (!$available) $badges[] = ['key' => 'sold-out', 'label' => 'Sold Out', 'source' => 'derived'];
    if ((bool)$row['featured']) $badges[] = ['key' => 'featured', 'label' => 'Featured', 'source' => 'derived'];
    $formattedManualBadges = [];
    foreach ($manualBadges as $badge) {
        $formatted = ['id' => (int)$badge['id'], 'label' => $badge['label'], 'sortOrder' => (int)$badge['sort_order']];
        $formattedManualBadges[] = $formatted;
        $badges[] = [
            'key' => 'manual-' . $badge['id'],
            'label' => $badge['label'],
            'source' => 'manual',
            'sortOrder' => (int)$badge['sort_order'],
        ];
    }

    $product = [
        'id' => (int)$row['id'],
        'sku' => $row['sku'],
        'slug' => $row['slug'],
        'title' => $row['title'],
        'shortDescription' => $row['short_description'],
        'description' => $row['description'],
        'category' => $row['category'],
        'productType' => $row['product_type'],
        'price' => $regular['value'],
        'regularPrice' => $regular['value'],
        'salePrice' => $onSale ? $sale['value'] : null,
        'currentPrice' => shop_money_from_cents($currentCents),
        'onSale' => $onSale,
        'discountAmount' => shop_money_from_cents($discountCents),
        'discountPercentage' => $discountPercentage,
        'stock' => (int)$row['stock'],
        'available' => $available,
        'availability' => $available ? 'available' : 'sold_out',
        'featured' => (bool)$row['featured'],
        'purchaseAction' => $row['purchase_action'],
        'images' => $formattedImages,
        'image' => $primaryImage,
        'badges' => $badges,
    ];
    if ($row['purchase_action'] === 'external') $product['externalUrl'] = $row['external_url'];
    if ($admin) {
        $product += [
            'publicationStatus' => $row['publication_status'],
            'storefrontVisible' => (bool)$row['storefront_visible'],
            'showWhenSoldOut' => (bool)$row['show_when_sold_out'],
            'sortOrder' => (int)$row['sort_order'],
            'externalUrl' => $row['external_url'],
            'manualBadges' => $formattedManualBadges,
            'active' => (bool)$row['active'],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
        ];
    }
    return $product;
}

function shop_products_response(PDO $pdo, array $rows, bool $admin = false): array
{
    $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
    $relations = shop_fetch_relations($pdo, $ids);
    return array_map(static function (array $row) use ($relations, $admin): array {
        $id = (int)$row['id'];
        return shop_product_response($row, $relations['images'][$id] ?? [], $relations['badges'][$id] ?? [], $admin);
    }, $rows);
}

function shop_fetch_product(PDO $pdo, int $id, bool $admin = false): ?array
{
    $stmt = $pdo->prepare('SELECT ' . shop_product_columns() . ' FROM shop_products WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return null;
    return shop_products_response($pdo, [$row], $admin)[0];
}
