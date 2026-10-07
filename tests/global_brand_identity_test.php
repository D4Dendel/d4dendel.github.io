<?php
declare(strict_types=1);

require __DIR__ . '/../api/config.php';

const BRAND_TEST_API = 'http://127.0.0.1/dyndel-portfolio/api/index.php';

function brand_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function brand_request(string $action, string $method = 'GET', array $data = [], ?string $sessionId = null): array
{
    $curl = curl_init(BRAND_TEST_API . '?action=' . rawurlencode($action));
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($method !== 'GET') curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    if ($sessionId) curl_setopt($curl, CURLOPT_COOKIE, 'PHPSESSID=' . $sessionId);
    $body = curl_exec($curl);
    if ($body === false) throw new RuntimeException('HTTP request failed: ' . curl_error($curl));
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $contentType = (string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
    curl_close($curl);
    return [$status, json_decode($body, true, 32, JSON_THROW_ON_ERROR), $contentType, $body];
}

$pdo = db();
$columns = [
    'brand_name', 'brand_logo_path', 'site_icon_path', 'brand_name_color', 'brand_font', 'heading_font', 'body_font', 'ui_font', 'pointer_brush_enabled',
    'instagram_url', 'facebook_url', 'twitter_url', 'youtube_url', 'updated_at',
];
$before = $pdo->query('SELECT ' . implode(', ', $columns) . ' FROM site_settings WHERE id = 1')->fetch();
brand_expect((bool)$before, 'Brand settings row was missing.');
$beforeFiles = glob(__DIR__ . '/../img/projectfolder/brand/*') ?: [];
$createdFiles = [];
$checks = 0;
$sessionId = 'brandtest' . bin2hex(random_bytes(6));
session_id($sessionId);
session_start();
$_SESSION['admin_id'] = 'admin';
session_write_close();
$pngPath = tempnam(sys_get_temp_dir(), 'dyndel-brand-');
file_put_contents($pngPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
$textPath = tempnam(sys_get_temp_dir(), 'dyndel-brand-invalid-');
file_put_contents($textPath, '<?php echo "unsafe";');
$gifPath = tempnam(sys_get_temp_dir(), 'dyndel-brand-gif-');
file_put_contents($gifPath, hex2bin('47494638396101000100800000000000ffffff21ff0b4e45545343415045322e30030100000021f904000a0000002c000000000100010000020244010021f904000a0000002c00000000010001000002024c01003b'));

$valid = [
    'brandName' => 'Dyndel Browser Brand',
    'brandNameColor' => '#245f4b',
    'brandFont' => 'nunito',
    'headingFont' => 'georgia',
    'bodyFont' => 'system-sans',
    'uiFont' => 'same-body',
    'pointerBrushEnabled' => '1',
    'instagram' => 'https://instagram.com/dyndel-browser',
    'facebook' => '',
    'twitter' => '',
    'youtube' => 'https://youtube.com/@dyndel-browser',
];

try {
    [$status, $public, $contentType] = brand_request('brand');
    brand_expect($status === 200 && isset($public['brand']['brandName'], $public['brand']['socials']), 'Public Brand Identity did not load.');
    brand_expect($public['brand']['brandName'] === $before['brand_name'], 'Public Brand Name did not match storage.');
    brand_expect(str_starts_with(strtolower($contentType), 'application/json'), 'Public Brand Identity did not return JSON content type.');
    $checks += 3;

    [$status, , $contentType] = brand_request('save-brand', 'POST', $valid);
    brand_expect($status === 401 && str_starts_with(strtolower($contentType), 'application/json'), 'Unauthenticated Brand Identity save was accepted or did not return JSON.');
    foreach ([
        ['brandName' => '', 'brandNameColor' => '#245f4b'],
        ['brandName' => 'Valid', 'brandNameColor' => 'green'],
        ['brandName' => 'Valid', 'brandNameColor' => '#245f4b', 'brandFont' => 'remote-font'],
        ['brandName' => 'Valid', 'brandNameColor' => '#245f4b', 'pointerBrushEnabled' => 'sometimes'],
        ['brandName' => 'Valid', 'brandNameColor' => '#245f4b', 'brandFont' => 'nunito', 'headingFont' => 'georgia', 'bodyFont' => 'nunito', 'uiFont' => 'same-body', 'instagram' => 'javascript:alert(1)'],
    ] as $invalid) {
        [$status] = brand_request('save-brand', 'POST', array_merge($valid, $invalid), $sessionId);
        brand_expect($status === 422, 'Invalid Brand Identity input was accepted.');
        $checks++;
    }

    $invalidUpload = $valid + ['logoFile' => new CURLFile($textPath, 'text/plain', 'unsafe.php')];
    [$status, , $contentType] = brand_request('save-brand', 'POST', $invalidUpload, $sessionId);
    brand_expect($status === 422 && str_starts_with(strtolower($contentType), 'application/json'), 'Executable/non-image Brand upload was accepted or did not return JSON.');
    $checks++;

    $filesBeforeMixedUpload = glob(__DIR__ . '/../img/projectfolder/brand/*') ?: [];
    $mixedUpload = $valid + [
        'logoFile' => new CURLFile($pngPath, 'image/png', 'brand-logo.png'),
        'iconFile' => new CURLFile($textPath, 'text/plain', 'unsafe.php'),
    ];
    [$status] = brand_request('save-brand', 'POST', $mixedUpload, $sessionId);
    brand_expect($status === 422, 'A valid logo paired with an invalid icon was accepted.');
    brand_expect((glob(__DIR__ . '/../img/projectfolder/brand/*') ?: []) === $filesBeforeMixedUpload, 'A partially valid Brand upload left an orphaned file.');
    $checks += 2;

    foreach ([['1', true], ['0', false], ['1', true]] as [$storedValue, $expected]) {
        [$status, $toggleSave] = brand_request('save-brand', 'POST', array_merge($valid, ['pointerBrushEnabled' => $storedValue]), $sessionId);
        brand_expect($status === 200 && ($toggleSave['brand']['pointerBrushEnabled'] ?? null) === $expected, 'Pointer Brush Effect toggle did not save.');
        [$status, $toggleReload] = brand_request('brand');
        brand_expect($status === 200 && ($toggleReload['brand']['pointerBrushEnabled'] ?? null) === $expected, 'Pointer Brush Effect did not persist after reload.');
        $checks += 2;
    }

    [$status, $settingsOnly, $contentType] = brand_request('save-brand', 'POST', $valid, $sessionId);
    brand_expect($status === 200 && str_starts_with(strtolower($contentType), 'application/json'), 'Settings-only Brand Identity save failed its JSON contract.');
    brand_expect($settingsOnly['brand']['logoPath'] === $before['brand_logo_path'] && $settingsOnly['brand']['siteIconPath'] === $before['site_icon_path'], 'Settings-only Brand save changed media paths.');
    $checks += 2;

    $gifLogoUpload = $valid + ['logoFile' => new CURLFile($gifPath, 'image/gif', 'animated-logo.gif')];
    [$status, $gifLogo, $contentType] = brand_request('save-brand', 'POST', $gifLogoUpload, $sessionId);
    brand_expect($status === 200 && str_starts_with(strtolower($contentType), 'application/json') && str_ends_with($gifLogo['brand']['logoPath'], '.gif'), 'GIF logo-only upload failed.');
    brand_expect($gifLogo['brand']['siteIconPath'] === $before['site_icon_path'], 'GIF logo-only upload changed the favicon.');
    $createdFiles[] = realpath(__DIR__ . '/../' . $gifLogo['brand']['logoPath']) ?: __DIR__ . '/../' . $gifLogo['brand']['logoPath'];
    $checks += 2;

    $gifIconUpload = $valid + ['iconFile' => new CURLFile($gifPath, 'image/gif', 'animated-icon.gif')];
    [$status, $gifIcon, $contentType] = brand_request('save-brand', 'POST', $gifIconUpload, $sessionId);
    brand_expect($status === 200 && str_starts_with(strtolower($contentType), 'application/json') && str_ends_with($gifIcon['brand']['siteIconPath'], '.gif'), 'GIF favicon-only upload failed.');
    brand_expect($gifIcon['brand']['logoPath'] === $gifLogo['brand']['logoPath'], 'GIF favicon-only upload changed the logo.');
    $createdFiles[] = realpath(__DIR__ . '/../' . $gifIcon['brand']['siteIconPath']) ?: __DIR__ . '/../' . $gifIcon['brand']['siteIconPath'];
    $checks += 2;

    $gifBothUpload = $valid + [
        'logoFile' => new CURLFile($gifPath, 'image/gif', 'animated-logo.gif'),
        'iconFile' => new CURLFile($gifPath, 'image/gif', 'animated-icon.gif'),
    ];
    [$status, $gifBoth, $contentType] = brand_request('save-brand', 'POST', $gifBothUpload, $sessionId);
    brand_expect($status === 200 && str_starts_with(strtolower($contentType), 'application/json') && str_ends_with($gifBoth['brand']['logoPath'], '.gif') && str_ends_with($gifBoth['brand']['siteIconPath'], '.gif'), 'Combined GIF logo/favicon upload failed.');
    foreach (['logoPath', 'siteIconPath'] as $key) {
        $createdFiles[] = realpath(__DIR__ . '/../' . $gifBoth['brand'][$key]) ?: __DIR__ . '/../' . $gifBoth['brand'][$key];
    }
    $checks++;

    $upload = $valid + [
        'logoFile' => new CURLFile($pngPath, 'image/png', 'brand-logo.png'),
        'iconFile' => new CURLFile($pngPath, 'image/png', 'brand-icon.png'),
    ];
    [$status, $saved] = brand_request('save-brand', 'POST', $upload, $sessionId);
    brand_expect($status === 200 && ($saved['saved'] ?? false), 'Valid Brand Identity save failed.');
    $brand = $saved['brand'];
    brand_expect($brand['brandName'] === $valid['brandName'] && $brand['brandNameColor'] === $valid['brandNameColor'], 'Brand name or color did not persist.');
    brand_expect($brand['brandFont'] === 'nunito' && $brand['headingFont'] === 'georgia' && $brand['bodyFont'] === 'system-sans' && $brand['uiFont'] === 'same-body', 'Curated typography did not persist.');
    brand_expect($brand['socials'] === ['instagram' => $valid['instagram'], 'facebook' => '', 'twitter' => '', 'youtube' => $valid['youtube']], 'Social settings did not persist exactly.');
    foreach (['logoPath', 'siteIconPath'] as $key) {
        brand_expect((bool)preg_match('#\Aimg/projectfolder/brand/[0-9a-f]{32}\.png\z#', $brand[$key]), "{$key} was not safely randomized.");
        $diskPath = __DIR__ . '/../' . str_replace('/', DIRECTORY_SEPARATOR, $brand[$key]);
        brand_expect(is_file($diskPath), "{$key} was not stored in the dedicated Brand directory.");
        $createdFiles[] = realpath($diskPath) ?: $diskPath;
        $checks += 2;
    }
    $checks += 4;

    [$status, $textOnly] = brand_request('save-brand', 'POST', array_merge($valid, ['textLogoOnly' => '1', 'instagram' => '', 'youtube' => '']), $sessionId);
    brand_expect($status === 200 && $textOnly['brand']['logoPath'] === null, 'Text-only logo fallback did not persist.');
    brand_expect($textOnly['brand']['siteIconPath'] === $brand['siteIconPath'], 'Saving text-only logo unexpectedly changed the favicon.');
    brand_expect(array_filter($textOnly['brand']['socials']) === [], 'Blank social profiles were not preserved as hidden values.');
    $checks += 3;
} finally {
    $assignments = implode(', ', array_map(static fn(string $column): string => "`{$column}` = ?", $columns));
    $restore = $pdo->prepare("UPDATE site_settings SET {$assignments} WHERE id = 1");
    $restore->execute(array_map(static fn(string $column): mixed => $before[$column], $columns));
    foreach ($createdFiles as $file) {
        if (is_file($file) && !in_array($file, $beforeFiles, true)) unlink($file);
    }
    @unlink($pngPath);
    @unlink($textPath);
    @unlink($gifPath);
}

$after = $pdo->query('SELECT ' . implode(', ', $columns) . ' FROM site_settings WHERE id = 1')->fetch();
brand_expect($after === $before, 'Brand Identity test did not restore the original settings row exactly.');
brand_expect((glob(__DIR__ . '/../img/projectfolder/brand/*') ?: []) === $beforeFiles, 'Brand Identity test left uploaded fixtures behind.');
$checks += 2;

echo "Global Brand Identity integration tests passed: {$checks} assertions.\n";
