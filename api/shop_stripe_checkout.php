<?php
declare(strict_types=1);

function shop_stripe_order_id(mixed $id): int
{
    if ((!is_string($id) && !is_int($id)) || !preg_match('/\A[1-9][0-9]{0,9}\z/D', (string)$id)
        || (int)$id > 4294967295) throw new DomainException('Invalid order.');
    return (int)$id;
}
function shop_stripe_owned_order_id(mixed $id, array $session): int
{
    $orderId = shop_stripe_order_id($id);
    if (empty($session['checkout_order_bindings'][$orderId])) throw new DomainException('This order is not available in your checkout session.');
    return $orderId;
}

/** Internal service; HTTP caller must establish session ownership and CSRF first. */
function shop_stripe_start(PDO $pdo, ShopPaymentProviderRegistry $registry, int $orderId): array
{
    $lockName = 'dyndel-g3-' . substr(hash('sha256', (string)$orderId),0,48);
    $lock = $pdo->prepare('SELECT GET_LOCK(?,5)'); $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn() !== 1) throw new ShopStripeUnavailable('Another payment request is preparing this order. Retry shortly.');
    try {
        $orderStmt = $pdo->prepare('SELECT * FROM shop_orders WHERE id=?'); $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
        if (!$order || $order['order_origin'] !== 'checkout_v2' || $order['currency'] !== 'USD' || $order['status'] !== 'pending') throw new DomainException('Order is not eligible for card payment.');
        if ($order['payment_status'] === 'paid') return ['alreadyPaid'=>true, 'orderId'=>$orderId];
        if ($order['payment_status'] !== 'unpaid') throw new DomainException('Order is not awaiting payment.');
        shop_payment_cents($order['total']);
        $stmt = $pdo->prepare("SELECT * FROM shop_payments WHERE order_id=? AND status IN ('pending','paid') ORDER BY id DESC LIMIT 1");
        $stmt->execute([$orderId]); $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($payment && ($payment['provider'] !== 'stripe' || $payment['provider_mode'] !== 'test')) throw new DomainException('Order already has another payment attempt.');
        if ($payment && ($payment['amount'] !== $order['total'] || $payment['currency'] !== $order['currency'])) throw new DomainException('Order changed after the payment attempt.');
        if ($payment && $payment['provider_session_id'] !== null) {
            if (!StripeTestAdapter::safeCheckoutUrl($payment['provider_session_url'])
                || (int)$payment['provider_session_expires_at'] <= time()) throw new ShopStripeUnavailable('This payment session has expired. Wait for confirmation, then retry.');
            return ['orderId'=>$orderId, 'paymentId'=>(int)$payment['id'], 'url'=>$payment['provider_session_url']];
        }
        $available = false;
        foreach ($registry->settings($pdo) as $setting) if ($setting['key'] === 'stripe' && $setting['mode'] === 'test' && $setting['available']) $available = true;
        if (!$available) throw new ShopStripeUnavailable('Credit / Debit Card is currently unavailable. Your order remains unpaid and your Cart is unchanged.');
        if ($payment) {
            // Stripe can prune idempotency keys after 24 hours. Never recreate an
            // uncertain, unbound session after that window without reconciliation.
            $age = $pdo->prepare('SELECT TIMESTAMPDIFF(SECOND,created_at,NOW()) FROM shop_payments WHERE id=?');
            $age->execute([$payment['id']]);
            if ((int)$age->fetchColumn() >= 23*3600) throw new ShopStripeUnavailable('This payment attempt needs administrator reconciliation before retrying. Your order remains unpaid.');
        }
        if (!$payment) {
            // Mode and authoritative amount persist atomically in the G1 attempt.
            $payment = shop_payment_create($pdo, $orderId, 'stripe', bin2hex(random_bytes(24)),null,'test');
        }
        $session = $registry->createBoundSession($payment);
        shop_payment_transaction($pdo, static function () use ($pdo,$orderId,$payment,$session): array {
            shop_payment_lock_order($pdo,$orderId);
            $stmt = $pdo->prepare('SELECT * FROM shop_payments WHERE id=? FOR UPDATE'); $stmt->execute([$payment['id']]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$current || $current['provider_mode'] !== 'test' || $current['status'] !== 'pending'
                || $current['provider_session_id'] !== null) throw new DomainException('Payment session binding changed; retry.');
            $pdo->prepare('UPDATE shop_payments SET provider_session_id=?,provider_session_url=?,provider_session_expires_at=? WHERE id=?')
                ->execute([$session['id'],$session['url'],$session['expiresAt'],$payment['id']]);
            return [];
        });
        return ['orderId'=>$orderId, 'paymentId'=>(int)$payment['id'], 'url'=>$session['url']];
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
}

function shop_stripe_order_state(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('SELECT id,payment_status FROM shop_orders WHERE id=? AND order_origin=?');
    $stmt->execute([$orderId,'checkout_v2']); $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) throw new DomainException('Order is unavailable.');
    $stmt = $pdo->prepare('SELECT status FROM shop_payments WHERE order_id=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$orderId]); $paymentStatus = $stmt->fetchColumn();
    $items = [];
    if ($order['payment_status'] === 'paid') {
        $stmt = $pdo->prepare('SELECT product_id,quantity FROM shop_order_items WHERE order_id=? ORDER BY id');
        $stmt->execute([$orderId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) $items[] = ['id'=>(int)$item['product_id'],'quantity'=>(int)$item['quantity']];
    }
    return ['orderId'=>$orderId, 'paymentStatus'=>$order['payment_status'], 'attemptStatus'=>$paymentStatus ?: null, 'purchasedItems'=>$items];
}
