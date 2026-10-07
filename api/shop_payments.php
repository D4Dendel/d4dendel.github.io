<?php
declare(strict_types=1);

/** Internal domain service only. No HTTP routes and no provider credentials.
 * Load after shop_products.php and shop_checkout.php. Adapters must authenticate
 * provider results BEFORE calling shop_payment_process_verified_event().
 */

function shop_payment_identifier(string $value, int $maximum = 190): string
{
    if (strlen($value) > $maximum || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/-]*\z/D', $value)) {
        throw new DomainException('Invalid payment identifier.');
    }
    return $value;
}

function shop_payment_provider(string $provider): string
{
    if (!preg_match('/\A[a-z][a-z0-9_]{0,39}\z/D', $provider)) {
        throw new DomainException('Invalid payment provider.');
    }
    return $provider;
}

/** Unlike HTTP validation helpers, domain validation throws and permits rollback. */
function shop_payment_cents(mixed $amount): int
{
    if (!is_string($amount) || !preg_match('/\A(0|[1-9][0-9]{0,7})\.([0-9]{2})\z/D', $amount, $parts)) {
        throw new DomainException('Payment amount must be an exact DECIMAL(10,2) string.');
    }
    $cents = (int)$parts[1] * 100 + (int)$parts[2];
    if ($cents <= 0 || $cents > SHOP_MAX_MONEY_CENTS) throw new DomainException('Payment amount must be positive.');
    return $cents;
}

function shop_payment_transaction(PDO $pdo, callable $work): array
{
    if ($pdo->inTransaction()) throw new LogicException('Payment Core owns its transaction; nested transactions are unsupported.');
    $pdo->beginTransaction();
    try {
        $result = $work();
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function shop_payment_lock_order(PDO $pdo, int $orderId): array
{
    if ($orderId < 1) throw new DomainException('Invalid order ID.');
    $stmt = $pdo->prepare('SELECT * FROM shop_orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) throw new DomainException('Order does not exist.');
    return $order;
}

/** Persisted order data is the sole source of amount/currency/order identity.
 * Reusing a reference returns the same attempt; retries need a NEW reference
 * after a terminal failure/cancellation. No historical payment backfill.
 */
function shop_payment_create(PDO $pdo, int $orderId, string $provider, string $attemptReference, ?string $providerSessionId = null, ?string $providerMode = null): array
{
    shop_payment_provider($provider);
    shop_payment_identifier($attemptReference, 64);
    if ($providerSessionId !== null) shop_payment_identifier($providerSessionId);
    if ($providerMode !== null && !in_array($providerMode,['test','live'],true)) throw new DomainException('Invalid payment mode.');
    return shop_payment_transaction($pdo, static function () use ($pdo, $orderId, $provider, $attemptReference, $providerSessionId,$providerMode): array {
        $order = shop_payment_lock_order($pdo, $orderId);
        // Legacy orders remain readable but cannot enter a new payment flow.
        if ($order['order_origin'] !== 'checkout_v2' || $order['currency'] !== SHOP_CURRENCY) {
            throw new DomainException('Only USD Checkout V2 orders are eligible.');
        }
        shop_payment_cents((string)$order['total']);
        $existing = $pdo->prepare('SELECT * FROM shop_payments WHERE provider = ? AND attempt_reference = ? FOR UPDATE');
        $existing->execute([$provider, $attemptReference]);
        $payment = $existing->fetch(PDO::FETCH_ASSOC);
        if ($payment) {
            if ((int)$payment['order_id'] !== $orderId || $payment['amount'] !== $order['total']
                || $payment['currency'] !== $order['currency'] || $payment['provider_session_id'] !== $providerSessionId
                || $payment['provider_mode'] !== $providerMode) {
                throw new DomainException('Payment reference was already used with different details.');
            }
            return $payment;
        }
        if ($order['status'] !== 'pending' || $order['payment_status'] !== 'unpaid') {
            throw new DomainException('Order is not awaiting payment.');
        }
        $pending = $pdo->prepare("SELECT id FROM shop_payments WHERE order_id = ? AND status IN ('pending','paid') LIMIT 1");
        $pending->execute([$orderId]);
        if ($pending->fetchColumn()) throw new DomainException('Order already has an active or paid payment attempt.');
        $insert = $pdo->prepare('INSERT INTO shop_payments (order_id, provider, attempt_reference, provider_session_id, amount, currency,provider_mode) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([$orderId, $provider, $attemptReference, $providerSessionId, $order['total'], $order['currency'],$providerMode]);
        $select = $pdo->prepare('SELECT * FROM shop_payments WHERE id = ?');
        $select->execute([(int)$pdo->lastInsertId()]);
        return $select->fetch(PDO::FETCH_ASSOC);
    });
}

/**
 * Trusted adapter boundary: verified amount is compared, NEVER trusted as the
 * payment amount. The caller must verify signature/account/environment and map
 * the provider's final status before invoking this method. Browser input must
 * never reach this boundary. Capture IDs must be stable and provider-scoped.
 *
 * Events, payment transition and order payment_status commit atomically.
 * G3/G4 can extend the paid branch inside this transaction with inventory and
 * fulfillment checks. G1 intentionally makes no inventory/fulfillment changes.
 */
function shop_payment_process_verified_event(
    PDO $pdo, int $paymentId, string $provider, string $providerEventId,
    string $eventType, string $status, mixed $verifiedAmount, string $verifiedCurrency,
    ?string $providerPaymentId = null, ?string $failureCode = null
): array {
    shop_payment_provider($provider);
    shop_payment_identifier($providerEventId);
    shop_payment_identifier($eventType, 120);
    if (!in_array($status, ['paid', 'failed', 'cancelled'], true)) throw new DomainException('Invalid verified payment status.');
    $amountCents = shop_payment_cents($verifiedAmount);
    if ($verifiedCurrency !== SHOP_CURRENCY) throw new DomainException('Unsupported verified payment currency.');
    if ($providerPaymentId !== null) shop_payment_identifier($providerPaymentId);
    if ($failureCode !== null) shop_payment_identifier($failureCode, 80);
    if (($status === 'paid' && ($providerPaymentId === null || $failureCode !== null))
        || ($status !== 'paid' && $providerPaymentId !== null)) throw new DomainException('Invalid result metadata.');
    $hash = hash('sha256', json_encode([$paymentId, $provider, $eventType, $status, $verifiedAmount,
        $verifiedCurrency, $providerPaymentId, $failureCode], JSON_THROW_ON_ERROR));

    return shop_payment_transaction($pdo, static function () use ($pdo, $paymentId, $provider, $providerEventId,
        $eventType, $status, $amountCents, $verifiedCurrency, $providerPaymentId, $failureCode, $hash): array {
        $lookup = $pdo->prepare('SELECT order_id FROM shop_payments WHERE id = ?');
        $lookup->execute([$paymentId]);
        $orderId = $lookup->fetchColumn();
        if ($orderId === false) throw new DomainException('Payment does not exist.');
        // Every mutating path locks order THEN payment to serialize attempts.
        $order = shop_payment_lock_order($pdo, (int)$orderId);
        $select = $pdo->prepare('SELECT * FROM shop_payments WHERE id = ? FOR UPDATE');
        $select->execute([$paymentId]);
        $payment = $select->fetch(PDO::FETCH_ASSOC);
        if (!$payment || $payment['provider'] !== $provider || (int)$payment['order_id'] !== (int)$orderId
            || shop_payment_cents($payment['amount']) !== $amountCents || $payment['currency'] !== $verifiedCurrency) {
            throw new DomainException('Verified result does not match the payment.');
        }
        $eventStmt = $pdo->prepare('SELECT * FROM shop_payment_events WHERE provider = ? AND provider_event_id = ? FOR UPDATE');
        $eventStmt->execute([$provider, $providerEventId]);
        $event = $eventStmt->fetch(PDO::FETCH_ASSOC);
        if ($event) {
            if (!hash_equals($event['result_hash'], $hash) || $event['processing_status'] !== 'processed') {
                throw new DomainException('Provider event was already used or requires inspection.');
            }
            return ['payment' => $payment, 'duplicate' => true, 'changed' => false];
        }
        $changed = $payment['status'] !== $status;
        if ($changed && $payment['status'] !== 'pending') throw new DomainException('Terminal payment states cannot transition.');
        if (!$changed && ($payment['provider_payment_id'] !== $providerPaymentId || $payment['failure_code'] !== $failureCode)) {
            throw new DomainException('Repeated terminal result has different metadata.');
        }
        if ($changed && ($order['order_origin'] !== 'checkout_v2' || $order['status'] !== 'pending'
            || $order['payment_status'] !== 'unpaid' || $order['currency'] !== $payment['currency']
            || shop_payment_cents($order['total']) !== $amountCents)) {
            throw new DomainException('Order is no longer eligible for this payment result.');
        }
        $insert = $pdo->prepare('INSERT INTO shop_payment_events (provider, provider_event_id, event_type, payment_id, order_id, result_status, result_hash) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([$provider, $providerEventId, $eventType, $paymentId, $orderId, $status, $hash]);
        $eventId = (int)$pdo->lastInsertId();
        if ($changed) {
            $update = $pdo->prepare("UPDATE shop_payments SET status = ?, provider_payment_id = ?, failure_code = ?, paid_at = CASE WHEN ? = 'paid' THEN CURRENT_TIMESTAMP ELSE NULL END WHERE id = ?");
            $update->execute([$status, $providerPaymentId, $failureCode, $status, $paymentId]);
            if ($status === 'paid') {
                // Future atomic inventory/fulfillment finalization belongs HERE.
                $pdo->prepare("UPDATE shop_orders SET payment_status = 'paid' WHERE id = ?")->execute([$orderId]);
            }
        }
        $pdo->prepare("UPDATE shop_payment_events SET processing_status = 'processed', processed_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$eventId]);
        $select->execute([$paymentId]);
        return ['payment' => $select->fetch(PDO::FETCH_ASSOC), 'duplicate' => false, 'changed' => $changed];
    });
}
