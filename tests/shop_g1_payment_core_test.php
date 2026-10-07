<?php
declare(strict_types=1);

require __DIR__ . '/../api/config.php';
require __DIR__ . '/../api/shop_products.php';
require __DIR__ . '/../api/shop_checkout.php';
require __DIR__ . '/../api/shop_payments.php';

// Separate connections exercise real concurrent event delivery and row locks.
if (($argv[1] ?? '') === '--event-worker') {
    $result = shop_payment_process_verified_event(db(), (int)$argv[2], 'test_provider', $argv[3],
        'capture.completed', 'paid', '12.34', 'USD', $argv[4]);
    echo json_encode(['duplicate' => $result['duplicate'], 'changed' => $result['changed']]);
    exit;
}

$checks = 0;
function g1_expect(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}

function g1_reject(callable $operation, string $message): void
{
    try { $operation(); } catch (DomainException | PDOException | LogicException | TypeError $error) {
        g1_expect(true, $message);
        return;
    }
    throw new RuntimeException($message);
}

function g1_mysql(string $database, string $sql, bool $success = true): string
{
    $process = proc_open(['C:\\xampp\\mysql\\bin\\mysql.exe', '--host=127.0.0.1', '--user=root',
        '--database=' . $database, '--batch'], [['pipe','r'], ['pipe','w'], ['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start local migration client.');
    fwrite($pipes[0], $sql);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($process);
    g1_expect($success ? $code === 0 : $code !== 0, 'Migration/import result unexpected: ' . $error);
    return $output;
}

function g1_snapshot(PDO $pdo): array
{
    $result = [];
    foreach (['shop_orders', 'shop_order_items', 'shop_products', 'shop_shipping_zones',
        'shop_shipping_zone_countries', 'shop_shipping_methods', 'site_settings', 'projects'] as $table) {
        $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        $encoded = array_map(static fn(array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows);
        sort($encoded, SORT_STRING);
        $result[$table] = ['count' => count($rows), 'sha256' => hash('sha256', implode("\n", $encoded))];
    }
    return $result;
}

$pdo = db();
$before = g1_snapshot($pdo);
$database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$migration = file_get_contents(__DIR__ . '/../migrations/20261007_shop_g1_payment_core.sql');
g1_mysql($database, $migration);
g1_expect(g1_snapshot($pdo) === $before, 'First migration changed business rows.');
g1_mysql($database, $migration);
g1_expect(g1_snapshot($pdo) === $before, 'Repeated migration changed business rows.');

$run = bin2hex(random_bytes(6));
$freshDatabase = 'dyndel_g1_test_' . $run;
$freshCreated = false;
$orderIds = [];
$initialPayments = $pdo->query('SELECT * FROM shop_payments ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$initialEvents = $pdo->query('SELECT * FROM shop_payment_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$autoIncrements = $pdo->query("SELECT TABLE_NAME, AUTO_INCREMENT FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('shop_orders','shop_order_items','shop_payments','shop_payment_events')")->fetchAll(PDO::FETCH_KEY_PAIR);

try {
    $pdo->exec("CREATE DATABASE `{$freshDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $freshCreated = true;
    g1_mysql($freshDatabase, $migration, false); // Missing F1/F2 must fail.
    $freshSql = file_get_contents(__DIR__ . '/../database.sql');
    $freshSql = preg_replace('/\ACREATE DATABASE[^;]+;\s*USE[^;]+;\s*/', '', $freshSql);
    g1_mysql($freshDatabase, $freshSql);
    g1_mysql($freshDatabase, $migration);
    g1_mysql($freshDatabase, $migration);
    foreach (['shop_payments', 'shop_payment_events'] as $table) {
        $live = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM)[1];
        $fresh = $pdo->query("SHOW CREATE TABLE `{$freshDatabase}`.`{$table}`")->fetch(PDO::FETCH_NUM)[1];
        // Existing installations inherit their database's UTF-8 default. All
        // provider identifiers explicitly use ascii_bin in either installation.
        $normalize = static fn(string $sql): string => preg_replace(
            ['/ AUTO_INCREMENT=\d+/', '/ DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_(?:general|unicode)_ci/'],
            ['', ''], str_replace("`{$freshDatabase}`.", '', $sql));
        g1_expect($normalize($live) === $normalize($fresh), "Fresh/migrated {$table} schemas differ.");
    }
    g1_mysql($freshDatabase, 'ALTER TABLE shop_payments DROP INDEX uq_shop_payments_capture;');
    g1_mysql($freshDatabase, $migration, false); // Incompatible objects must fail.

    $createOrder = static function (string $origin = 'checkout_v2', string $total = '12.34', string $status = 'pending', string $paymentStatus = 'unpaid') use ($pdo, &$orderIds): int {
        $stmt = $pdo->prepare('INSERT INTO shop_orders (customer_name,customer_email,currency,shipping_required,subtotal,total,status,order_origin,payment_status) VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?)');
        $stmt->execute(['G1 Test Fixture', 'g1-fixture@example.invalid', 'USD', $total, $total, $status, $origin, $paymentStatus]);
        $id = (int)$pdo->lastInsertId();
        $orderIds[] = $id;
        $product = $pdo->query('SELECT id,sku,title FROM shop_products ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare("INSERT INTO shop_order_items (order_id,product_id,product_sku,product_name,product_type,currency,quantity,price,line_total) VALUES (?, ?, ?, ?, 'digital', 'USD', 1, ?, ?)")
            ->execute([$id, $product['id'], $product['sku'], $product['title'], $total, $total]);
        return $id;
    };
    $paidOrder = $createOrder();
    $failedOrder = $createOrder();
    $cancelledOrder = $createOrder();
    $otherOrder = $createOrder();
    $legacyOrder = $createOrder('legacy');
    $zeroOrder = $createOrder('checkout_v2', '0.00');
    $closedOrder = $createOrder('checkout_v2', '12.34', 'cancelled');

    g1_reject(fn() => shop_payment_create($pdo, 0, 'test_provider', 'invalid'), 'Invalid order ID accepted.');
    g1_reject(fn() => shop_payment_create($pdo, 4294967295, 'test_provider', 'missing'), 'Missing order accepted.');
    g1_reject(fn() => shop_payment_create($pdo, $legacyOrder, 'test_provider', 'legacy'), 'Legacy order accepted.');
    g1_reject(fn() => shop_payment_create($pdo, $zeroOrder, 'test_provider', 'zero'), 'Zero payment accepted.');
    g1_reject(fn() => shop_payment_create($pdo, $closedOrder, 'test_provider', 'closed'), 'Cancelled order accepted.');
    g1_reject(fn() => shop_payment_create($pdo, $paidOrder, 'Stripe!', 'bad-provider'), 'Unsafe provider accepted.');
    g1_reject(fn() => shop_payment_create($pdo, $paidOrder, 'test_provider', ''), 'Empty reference accepted.');
    g1_reject(fn() => shop_payment_cents('12.345'), 'Overprecise amount accepted.');
    g1_reject(fn() => shop_payment_cents('1e2'), 'Exponent amount accepted.');
    g1_reject(fn() => shop_payment_cents(12.34), 'Floating-point amount accepted.');
    g1_reject(fn() => shop_payment_cents('100000000.00'), 'Overflow amount accepted.');
    g1_expect(shop_payment_cents('99999999.99') === SHOP_MAX_MONEY_CENTS && shop_payment_cents('0.01') === 1, 'Cents bounds are inexact.');

    $paid = shop_payment_create($pdo, $paidOrder, 'test_provider', "paid-{$run}", "session-{$run}");
    g1_expect($paid['amount'] === '12.34' && $paid['currency'] === 'USD' && (int)$paid['order_id'] === $paidOrder, 'Payment did not source persisted order values.');
    g1_expect($paid['status'] === 'pending' && $paid['paid_at'] === null, 'Payment did not start Pending.');
    g1_expect(shop_payment_create($pdo, $paidOrder, 'test_provider', "paid-{$run}", "session-{$run}") === $paid, 'Attempt retry was not idempotent.');
    g1_reject(fn() => shop_payment_create($pdo, $otherOrder, 'test_provider', "paid-{$run}", "session-{$run}"), 'Reference reused for a different order.');
    g1_reject(fn() => shop_payment_create($pdo, $paidOrder, 'paypal', "second-{$run}"), 'Concurrent active attempt allowed.');
    g1_reject(fn() => shop_payment_create($pdo, $otherOrder, 'test_provider', "other-{$run}", "session-{$run}"), 'Duplicate session accepted.');
    $failed = shop_payment_create($pdo, $failedOrder, 'test_provider', "failed-{$run}");
    $cancelled = shop_payment_create($pdo, $cancelledOrder, 'paypal', "cancelled-{$run}");
    $other = shop_payment_create($pdo, $otherOrder, 'stripe', "other-{$run}");
    $emit = static fn(array $payment, string $event, string $status = 'paid', string $amount = '12.34', string $currency = 'USD', ?string $capture = 'capture-default', ?string $failure = null): array =>
        shop_payment_process_verified_event($pdo, (int)$payment['id'], $payment['provider'], $event, 'capture.result', $status, $amount, $currency, $capture, $failure);
    g1_reject(fn() => $emit($paid, 'wrong-amount', 'paid', '0.01'), 'Verified amount mismatch accepted.');
    g1_reject(fn() => $emit($paid, 'wrong-currency', 'paid', '12.34', 'EUR'), 'Verified currency mismatch accepted.');
    g1_reject(fn() => $emit($paid, 'no-capture', 'paid', '12.34', 'USD', null), 'Paid without capture accepted.');
    g1_reject(fn() => $emit($paid, 'bad-state', 'refunded'), 'Unsupported transition accepted.');
    g1_reject(fn() => shop_payment_process_verified_event($pdo, (int)$paid['id'], 'paypal', 'wrong-provider', 'capture.result', 'paid', '12.34', 'USD', 'capture'), 'Provider mismatch accepted.');
    g1_reject(fn() => shop_payment_process_verified_event($pdo, 4294967295, 'paypal', 'missing-payment', 'capture.result', 'paid', '12.34', 'USD', 'capture'), 'Missing payment accepted.');

    $failedResult = $emit($failed, "failed-event-{$run}", 'failed', '12.34', 'USD', null, 'declined');
    $cancelledResult = $emit($cancelled, "cancel-event-{$run}", 'cancelled', '12.34', 'USD', null);
    g1_expect($failedResult['changed'] && $failedResult['payment']['status'] === 'failed', 'Pending -> Failed failed.');
    g1_expect($cancelledResult['changed'] && $cancelledResult['payment']['status'] === 'cancelled', 'Pending -> Cancelled failed.');
    foreach ([$failed, $cancelled] as $terminal) {
        g1_reject(fn() => $emit($terminal, 'late-paid-' . $terminal['id']), 'Failed/cancelled attempt became Paid.');
        g1_expect($pdo->query('SELECT payment_status FROM shop_orders WHERE id = ' . (int)$terminal['order_id'])->fetchColumn() === 'unpaid', 'Failed/cancelled attempt paid an order.');
    }
    $retry = shop_payment_create($pdo, $failedOrder, 'paypal', "retry-{$run}");
    g1_expect($retry['status'] === 'pending', 'Failed order could not start a new attempt.');
    g1_expect(!$emit($failed, "repeat-failure-{$run}", 'failed', '12.34', 'USD', null, 'declined')['changed'], 'Repeated failure was not idempotent.');

    // Two independent connections deliver the SAME paid event concurrently.
    $workers = [];
    foreach ([1,2] as $_) {
        $process = proc_open([PHP_BINARY, __FILE__, '--event-worker', (string)$paid['id'], "concurrent-{$run}", "capture-{$run}"],
            [['pipe','r'], ['pipe','w'], ['pipe','w']], $pipes);
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $workerResults = [];
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        g1_expect(proc_close($process) === 0, 'Concurrent result failed: ' . $error);
        $workerResults[] = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
    }
    g1_expect(count(array_filter($workerResults, fn($result) => $result['changed'])) === 1
        && count(array_filter($workerResults, fn($result) => $result['duplicate'])) === 1, 'Concurrent event finalized twice.');
    $paidAfter = $pdo->query('SELECT * FROM shop_payments WHERE id = ' . (int)$paid['id'])->fetch(PDO::FETCH_ASSOC);
    $orderAfter = $pdo->query("SELECT status,payment_status FROM shop_orders WHERE id = {$paidOrder}")->fetch(PDO::FETCH_ASSOC);
    g1_expect($paidAfter['status'] === 'paid' && $paidAfter['paid_at'] !== null && $orderAfter === ['status' => 'pending', 'payment_status' => 'paid'], 'Paid transition changed fulfillment or omitted payment status.');
    $sameCapture = $emit($paid, "new-paid-event-{$run}", 'paid', '12.34', 'USD', "capture-{$run}");
    g1_expect(!$sameCapture['changed'] && !$sameCapture['duplicate'] && $sameCapture['payment'] === $paidAfter, 'New event for same capture rewrote payment.');
    g1_expect($emit($paid, "new-paid-event-{$run}", 'paid', '12.34', 'USD', "capture-{$run}")['duplicate'], 'Repeated paid event not idempotent.');
    g1_reject(fn() => $emit($paid, "new-paid-event-{$run}", 'paid', '12.34', 'USD', 'different-capture'), 'Same event accepted changed result.');
    g1_reject(fn() => $emit($paid, "paid-to-failed-{$run}", 'failed', '12.34', 'USD', null), 'Paid -> Failed accepted.');
    g1_reject(fn() => $emit($paid, "new-capture-{$run}", 'paid', '12.34', 'USD', 'different-capture'), 'Paid attempt accepted another capture.');
    g1_reject(fn() => shop_payment_create($pdo, $paidOrder, 'paypal', "after-paid-{$run}"), 'Paid order accepted new attempt.');

    // The same event ID is legitimate in a different provider namespace.
    g1_expect($emit($retry, "new-paid-event-{$run}", 'paid', '12.34', 'USD', 'new-capture')['changed'], 'Provider event namespaces collided.');
    $collisionOrder = $createOrder();
    $collision = shop_payment_create($pdo, $collisionOrder, 'test_provider', "collision-{$run}");
    g1_reject(fn() => $emit($collision, "new-paid-event-{$run}"), 'Provider event reused for a different payment.');
    g1_reject(fn() => $emit($collision, "capture-collision-{$run}", 'paid', '12.34', 'USD', "capture-{$run}"), 'Capture reused across payments.');
    g1_expect($pdo->query("SELECT payment_status FROM shop_orders WHERE id = {$collisionOrder}")->fetchColumn() === 'unpaid', 'Capture collision partially paid an order.');
    $eventCount = $pdo->prepare('SELECT COUNT(*) FROM shop_payment_events WHERE provider_event_id = ?');
    $eventCount->execute(["capture-collision-{$run}"]);
    g1_expect((int)$eventCount->fetchColumn() === 0, 'Failed finalization left an event behind.');
    g1_reject(fn() => $pdo->prepare('INSERT INTO shop_payment_events (provider,provider_event_id,event_type,payment_id,order_id,result_status,result_hash,processing_status,processed_at) SELECT provider,provider_event_id,event_type,payment_id,order_id,result_status,result_hash,processing_status,processed_at FROM shop_payment_events WHERE provider_event_id = ? AND provider = ?')->execute(["concurrent-{$run}", 'test_provider']), 'Database allowed duplicate provider event.');
    g1_reject(fn() => $pdo->prepare('DELETE FROM shop_orders WHERE id = ?')->execute([$paidOrder]), 'Database permitted deletion of a payment order.');
    g1_reject(fn() => $pdo->prepare("INSERT INTO shop_payments (order_id,provider,attempt_reference,amount,currency) VALUES (4294967295,'test_provider','missing-fk',1.00,'USD')")->execute(), 'Database allowed dangling payment order.');
    g1_reject(fn() => $pdo->prepare("INSERT INTO shop_payments (order_id,provider,attempt_reference,amount,currency) VALUES (?,'test_provider','bad-currency',1.00,'EUR')")->execute([$collisionOrder]), 'Database allowed non-USD payment.');
    g1_reject(fn() => $pdo->prepare("INSERT INTO shop_payments (order_id,provider,attempt_reference,amount,currency) VALUES (?,'test_provider','bad-amount',0.00,'USD')")->execute([$collisionOrder]), 'Database allowed zero payment.');
    g1_reject(fn() => $pdo->prepare("INSERT INTO shop_payment_events (provider,provider_event_id,event_type,payment_id,order_id,result_status,result_hash) VALUES ('test_provider','bad-relation','capture.result',?,?,'paid',?)")->execute([$paid['id'], $collisionOrder, str_repeat('a',64)]), 'Database allowed an event with another order.');
    $pdo->prepare('UPDATE shop_orders SET subtotal = 12.35,total = 12.35 WHERE id = ?')->execute([$otherOrder]);
    g1_reject(fn() => $emit($other, "changed-order-{$run}"), 'Payment ignored persisted order drift.');
    $pdo->prepare('UPDATE shop_orders SET subtotal = 12.34,total = 12.34 WHERE id = ?')->execute([$otherOrder]);
    g1_expect($emit($other, "stripe-event-{$run}", 'paid', '12.34', 'USD', "capture-{$run}")['changed'], 'Capture IDs collided across provider namespaces.');
    g1_expect(!$emit($cancelled, "repeat-cancel-{$run}", 'cancelled', '12.34', 'USD', null)['changed'], 'Repeated cancellation was not idempotent.');
    $pdo->beginTransaction();
    try {
        g1_reject(fn() => shop_payment_create($pdo, $collisionOrder, 'test_provider', "nested-{$run}"), 'Nested transaction accepted.');
    } finally { $pdo->rollBack(); }
    g1_expect((int)$pdo->query('SELECT COUNT(*) FROM shop_orders')->fetchColumn() === $before['shop_orders']['count'] + count($orderIds), 'Core deleted or created orders.');
    g1_expect(g1_snapshot($pdo)['shop_products'] === $before['shop_products'], 'Payment transitions changed products or stock.');
} finally {
    foreach ($orderIds as $orderId) {
        $pdo->prepare('DELETE FROM shop_payment_events WHERE order_id = ?')->execute([$orderId]);
        $pdo->prepare('DELETE FROM shop_payments WHERE order_id = ?')->execute([$orderId]);
        $pdo->prepare('DELETE FROM shop_orders WHERE id = ?')->execute([$orderId]);
    }
    foreach ($autoIncrements as $table => $nextId) {
        if (in_array($table, ['shop_orders','shop_order_items','shop_payments','shop_payment_events'], true)
            && ctype_digit((string)$nextId)) $pdo->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = " . (int)$nextId);
    }
    if ($freshCreated && preg_match('/\Adyndel_g1_test_[a-f0-9]{12}\z/D', $freshDatabase)) {
        $pdo->exec("DROP DATABASE `{$freshDatabase}`");
    }
    g1_expect(g1_snapshot($pdo) === $before, 'Business state was not restored.');
    g1_expect($pdo->query('SELECT * FROM shop_payments ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $initialPayments, 'Existing payments changed.');
    g1_expect($pdo->query('SELECT * FROM shop_payment_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $initialEvents, 'Existing events changed.');
}

echo 'Database business rows before/after: ' . json_encode(array_map(fn($state) => $state['count'], $before)) . " (all fingerprints identical).\n";
echo "Shop G1 Payment Core tests passed: {$checks} assertions.\n";
