<?php
declare(strict_types=1);
require __DIR__ . '/../api/config.php';
require __DIR__ . '/../api/shop_products.php';
require __DIR__ . '/../api/shop_checkout.php';
require __DIR__ . '/../api/shop_payments.php';
require __DIR__ . '/../api/shop_payment_providers.php';

$checks = 0;
function g2_expect(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function g2_reject(callable $operation, string $message): void
{
    try { $operation(); } catch (DomainException | LogicException | PDOException $error) { g2_expect(true, $message); return; }
    throw new RuntimeException($message);
}
function g2_mysql(string $database, string $sql, bool $success = true): void
{
    $process = proc_open(['C:\\xampp\\mysql\\bin\\mysql.exe','--host=127.0.0.1','--user=root','--database=' . $database,'--batch'],
        [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
    fwrite($pipes[0], $sql); fclose($pipes[0]);
    stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($process);
    g2_expect($success ? $code === 0 : $code !== 0, 'Unexpected migration/import result: ' . $error);
}
function g2_business_state(PDO $pdo): array
{
    $state = [];
    foreach (['shop_products','shop_product_images','shop_product_badges','shop_orders','shop_order_items',
        'shop_shipping_zones','shop_shipping_zone_countries','shop_shipping_methods','shop_payments','shop_payment_events',
        'site_settings','projects','content_entries','content_blocks'] as $table) {
        $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        $encoded = array_map(fn($row) => json_encode($row, JSON_THROW_ON_ERROR), $rows);
        sort($encoded, SORT_STRING);
        $state[$table] = ['count' => count($rows), 'hash' => hash('sha256', implode("\n", $encoded))];
    }
    return $state;
}
function g2_request(string $action, string $method = 'GET', array $data = [], ?string $sessionId = null): array
{
    $curl = curl_init('http://127.0.0.1/dyndel-portfolio/api/index.php?action=' . rawurlencode($action));
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 10]);
    if ($method !== 'GET') curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
    if ($sessionId) curl_setopt($curl, CURLOPT_COOKIE, 'PHPSESSID=' . $sessionId);
    $body = curl_exec($curl);
    if ($body === false) throw new RuntimeException('Local API request failed.');
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return [$status, json_decode($body, true, 32, JSON_THROW_ON_ERROR), $body];
}

final class G2FixtureAdapter implements ShopPaymentProviderAdapter
{
    public int $verifications = 0;
    public function ready(): bool { return true; }
    public function createSession(array $payment, array $credentials): array { throw new LogicException('In-memory fixture has no gateway.'); }
    public function verifyCallback(string $payload, array $headers, array $credentials): ShopVerifiedProviderResult
    {
        $this->verifications++;
        return new ShopVerifiedProviderResult(-1, 'fixture-event', 'fixture.result', 'paid', '1.00', 'USD', 'fixture-capture');
    }
}

$pdo = db();
$before = g2_business_state($pdo);
$database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$migration = file_get_contents(__DIR__ . '/../migrations/20261007_shop_g2_payment_providers.sql');
g2_mysql($database, $migration);
g2_expect(g2_business_state($pdo) === $before, 'Migration changed business state.');
$initialProviders = $pdo->query('SELECT * FROM shop_payment_providers ORDER BY provider')->fetchAll(PDO::FETCH_ASSOC);
g2_mysql($database, $migration);
g2_expect($pdo->query('SELECT * FROM shop_payment_providers ORDER BY provider')->fetchAll(PDO::FETCH_ASSOC) === $initialProviders, 'Migration retry rewrote settings.');
$fresh = 'dyndel_g2_test_' . bin2hex(random_bytes(6));
$freshCreated = false;
$sessionId = 'g2providers' . bin2hex(random_bytes(10));
$sessionCreated = false;
$environmentOriginal = [];

try {
    $pdo->exec("CREATE DATABASE `{$fresh}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $freshCreated = true;
    g2_mysql($fresh, $migration, false);
    $freshSql = preg_replace('/\ACREATE DATABASE[^;]+;\s*USE[^;]+;\s*/', '', file_get_contents(__DIR__ . '/../database.sql'));
    g2_mysql($fresh, $freshSql);
    g2_mysql($fresh, $migration);
    g2_mysql($fresh, $migration);
    $normalize = static fn(string $sql): string => preg_replace('/ DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_(?:general|unicode)_ci/', '', $sql);
    $liveSchema = $pdo->query('SHOW CREATE TABLE shop_payment_providers')->fetch(PDO::FETCH_NUM)[1];
    $freshSchema = $pdo->query("SHOW CREATE TABLE `{$fresh}`.shop_payment_providers")->fetch(PDO::FETCH_NUM)[1];
    g2_expect($normalize($liveSchema) === $normalize($freshSchema), 'Fresh and migrated provider schemas differ.');
    g2_expect(array_column($initialProviders, 'provider') === ['paypal','stripe'], 'Unexpected registry settings.');
    $columns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_payment_providers' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
    g2_expect($columns === ['provider','enabled','mode','created_at','updated_at'], 'Provider table stores unexpected/secret fields.');
    g2_reject(fn() => $pdo->exec("INSERT INTO shop_payment_providers(provider) VALUES ('unknown')"), 'Database accepted unknown provider.');
    g2_reject(fn() => $pdo->exec("UPDATE shop_payment_providers SET enabled = 2 WHERE provider = 'paypal'"), 'Database accepted invalid enabled state.');

    $registry = new ShopPaymentProviderRegistry();
    g2_expect(array_column($registry->settings($pdo), 'key') === ['paypal','stripe'], 'Registry includes unexpected providers.');
    g2_reject(fn() => $registry->validateProvider('api/evil.php'), 'Registry accepted a PHP path.');
    g2_reject(fn() => new ShopPaymentProviderRegistry(['evil' => new G2FixtureAdapter()]), 'Registry accepted arbitrary adapter keys.');
    foreach ([
        ['provider'=>'unknown','mode'=>'test','enabled'=>'0'],
        ['provider'=>'paypal','mode'=>'sandbox','enabled'=>'0'],
        ['provider'=>'paypal','mode'=>'test','enabled'=>'yes'],
        ['provider'=>'paypal','mode'=>'test','enabled'=>'0','secret_key'=>'__fixture_private__'],
        ['provider'=>'paypal','mode'=>'test','enabled'=>'0','adapter'=>'StripeAdapter'],
        ['provider'=>['paypal'],'mode'=>'test','enabled'=>'0'],
    ] as $invalid) g2_reject(fn() => $registry->update($pdo, $invalid), 'Invalid configuration accepted.');

    $setting = $registry->update($pdo, ['provider'=>'paypal','mode'=>'test','enabled'=>'1']);
    g2_expect($setting['enabled'] && $setting['mode'] === 'test' && !$setting['adapterReady'] && !$setting['available'], 'Dormant provider became available.');
    $registry->update($pdo, ['provider'=>'paypal','mode'=>'live','enabled'=>'0']);
    g2_expect($registry->settings($pdo)[0]['mode'] === 'live', 'Mode update did not persist.');
    // Markers simulate credential presence only in process memory, not accounts/keys.
    $completeLoader = static fn($provider, $mode, $fields): array => array_fill_keys($fields, '__fixture_present__');
    $emptyLoader = static fn($provider, $mode, $fields): array => [];
    $stub = new G2FixtureAdapter();
    $adapters = ['paypal'=>$stub,'stripe'=>new G2FixtureAdapter()];
    $configured = new ShopPaymentProviderRegistry($adapters, $completeLoader);
    $unconfigured = new ShopPaymentProviderRegistry($adapters, $emptyLoader);
    $fixtureSetting = ['provider'=>'paypal','mode'=>'test','enabled'=>1];
    g2_expect(!$unconfigured->describe($fixtureSetting)['available'], 'Enabled unconfigured provider available.');
    g2_expect(!$configured->describe(array_merge($fixtureSetting, ['enabled'=>0]))['available'], 'Disabled configured provider available.');
    g2_expect($configured->describe($fixtureSetting)['available'], 'Configured enabled implemented provider unavailable.');
    g2_expect(!(new ShopPaymentProviderRegistry(null, $completeLoader))->describe($fixtureSetting)['available'], 'Credentials bypassed dormant-adapter guard.');
    g2_expect(!str_contains(json_encode($configured->describe($fixtureSetting)), '__fixture_present__'), 'Registry leaked credential values.');
    $splitLoader = static fn($provider, $mode, $fields): array => $mode === 'test' ? array_fill_keys($fields, '__fixture_present__') : [];
    $split = new ShopPaymentProviderRegistry($adapters, $splitLoader);
    g2_expect($split->describe($fixtureSetting)['configured'] && !$split->describe(array_merge($fixtureSetting, ['mode'=>'live']))['configured'], 'Test credentials leaked into Live readiness.');

    foreach (['CLIENT_ID','CLIENT_SECRET','WEBHOOK_ID'] as $field) {
        $name = 'DYNDEL_PAYPAL_TEST_' . $field;
        $environmentOriginal[$name] = getenv($name);
        putenv($name . '=__fixture_present__');
    }
    g2_expect((new ShopPaymentProviderRegistry())->describe($fixtureSetting)['configured'], 'Environment credential source not used.');
    $name = 'DYNDEL_PAYPAL_LIVE_CLIENT_SECRET';
    $environmentOriginal[$name] = getenv($name);
    putenv($name . '=');
    g2_expect(!(new ShopPaymentProviderRegistry())->describe(array_merge($fixtureSetting, ['mode'=>'live']))['configured'], 'Live mode used Test credentials.');
    g2_reject(fn() => (new ShopUnconnectedProviderAdapter())->createSession([], []), 'Dormant adapter created a session.');
    g2_reject(fn() => (new ShopUnconnectedProviderAdapter())->verifyCallback('', [], []), 'Dormant adapter verified a callback.');
    $registry->update($pdo, ['provider'=>'paypal','mode'=>'test','enabled'=>'1']);
    $registry->update($pdo, ['provider'=>'stripe','mode'=>'test','enabled'=>'0']);
    $publicFixture = $configured->publicProviders($pdo);
    g2_expect($publicFixture === [['key'=>'paypal','displayName'=>'PayPal','mode'=>'test']], 'Public discovery disclosed internal settings.');
    g2_reject(fn() => $configured->processCallback($pdo, 'paypal', 'fixture', []), 'Framework bypassed G1 payment validation.');
    g2_expect($stub->verifications === 1, 'Framework did not use registered callback verifier.');
    g2_reject(fn() => $registry->processCallback($pdo, 'paypal', 'fixture', []), 'Unavailable adapter accepted callback.');

    [$status] = g2_request('admin-payment-providers');
    g2_expect($status === 401, 'Admin provider list lacks authentication.');
    [$status] = g2_request('save-payment-provider', 'POST', ['provider'=>'paypal','mode'=>'test','enabled'=>'0']);
    g2_expect($status === 401, 'Provider mutation lacks authentication.');
    [$status] = g2_request('save-payment-provider');
    g2_expect($status === 405, 'Mutation accepted GET.');
    [$status,$public] = g2_request('payment-providers');
    g2_expect($status === 200 && $public === ['currency'=>'USD','providers'=>[]], 'Dormant public discovery is unsafe.');
    [$status] = g2_request('payment-providers','POST');
    g2_expect($status === 405, 'Discovery accepted POST.');

    session_id($sessionId); session_start(); $_SESSION['admin_id'] = 'admin'; session_write_close();
    $sessionCreated = true;
    [$status,$admin,$raw] = g2_request('admin-payment-providers','GET',[],$sessionId);
    g2_expect($status === 200 && count($admin['providers']) === 2 && $admin['currency'] === 'USD', 'Authenticated Admin list failed.');
    g2_expect(!str_contains($raw, '__fixture_present__') && !str_contains($raw, '__fixture_private__'), 'API leaked credential markers.');
    $safeKeys = ['key','displayName','supported','configured','enabled','mode','adapterReady','available','status','createdAt','updatedAt'];
    foreach ($admin['providers'] as $provider) g2_expect(array_keys($provider) === $safeKeys, 'API exposed non-whitelisted provider fields.');
    $payload = ['provider'=>'stripe','mode'=>'live','enabled'=>'1'];
    [$status] = g2_request('save-payment-provider','POST',$payload,$sessionId);
    g2_expect($status === 403, 'Mutation lacks CSRF protection.');
    $payload['csrfToken'] = $admin['csrfToken'];
    [$status,$saved] = g2_request('save-payment-provider','POST',$payload,$sessionId);
    g2_expect($status === 200 && $saved['provider']['enabled'] && $saved['provider']['mode'] === 'live' && !$saved['provider']['available'], 'Safe metadata update failed.');
    foreach ([['provider'=>'unknown'],['mode'=>'bad'],['enabled'=>'2'],['secret_key'=>'__fixture_private__'],['adapter'=>'evil.php'],['mode'=>['live']]] as $invalid) {
        [$status,,$raw] = g2_request('save-payment-provider','POST',array_merge($payload,$invalid),$sessionId);
        g2_expect($status === 422 && !str_contains($raw, '__fixture_private__'), 'Invalid API metadata accepted or echoed.');
    }
    [$status,$public] = g2_request('payment-providers');
    g2_expect($status === 200 && $public === ['currency'=>'USD','providers'=>[]], 'Admin enabling exposed a dormant provider.');
    g2_expect(g2_business_state($pdo) === $before, 'Provider operations changed business state.');
} finally {
    foreach ($initialProviders as $provider) {
        $pdo->prepare('UPDATE shop_payment_providers SET enabled=?,mode=?,created_at=?,updated_at=? WHERE provider=?')
            ->execute([$provider['enabled'],$provider['mode'],$provider['created_at'],$provider['updated_at'],$provider['provider']]);
    }
    foreach ($environmentOriginal as $name => $value) putenv($value === false ? $name : $name . '=' . $value);
    if ($sessionCreated) { session_id($sessionId); session_start(); session_destroy(); }
    if ($freshCreated && preg_match('/\Adyndel_g2_test_[a-f0-9]{12}\z/D', $fresh)) $pdo->exec("DROP DATABASE `{$fresh}`");
    g2_expect($pdo->query('SELECT * FROM shop_payment_providers ORDER BY provider')->fetchAll(PDO::FETCH_ASSOC) === $initialProviders, 'Provider settings not restored.');
    g2_expect(g2_business_state($pdo) === $before, 'Business state not restored.');
}
echo 'Business rows before/after: ' . json_encode(array_map(fn($row) => $row['count'], $before)) . " (fingerprints identical).\n";
echo "Shop G2 Provider Framework tests passed: {$checks} assertions.\n";
