<?php
declare(strict_types=1);

interface ShopProviderConfigurationPolicy
{
    public function configuredForMode(array $credentials, string $mode): bool;
}
interface ShopAttemptBoundProviderAdapter
{
    public function callbackMode(): string;
    public function validateBinding(PDO $pdo, ShopVerifiedProviderResult $result): void;
}
final class ShopIgnoredStripeEvent extends RuntimeException {}
final class ShopStripeUnavailable extends RuntimeException {}

/** Official v1 REST API over verified TLS. No card data enters this server. */
final class StripeTestAdapter implements ShopPaymentProviderAdapter, ShopProviderConfigurationPolicy, ShopAttemptBoundProviderAdapter
{
    public const API_VERSION = '2026-09-30.endive';
    private ?Closure $transport;
    private string $baseUrl;
    public function __construct(?Closure $transport = null, ?string $baseUrl = null)
    {
        $this->transport = $transport;
        $configured = getenv('DYNDEL_PAYMENT_RETURN_BASE_URL');
        $this->baseUrl = rtrim($baseUrl ?? ($configured !== false ? $configured :
            (defined('SHOP_PAYMENT_RETURN_BASE_URL') ? constant('SHOP_PAYMENT_RETURN_BASE_URL') : 'http://localhost/dyndel-portfolio')), '/');
    }
    public function ready(): bool { return extension_loaded('curl') && $this->validBaseUrl(); }
    private function validBaseUrl(): bool
    {
        $url = parse_url($this->baseUrl);
        return is_array($url) && isset($url['host'], $url['scheme']) && !isset($url['user']) && !isset($url['pass'])
            && !isset($url['query']) && !isset($url['fragment'])
            && ($url['scheme'] === 'https' || ($url['scheme'] === 'http' && in_array($url['host'], ['localhost','127.0.0.1','[::1]'], true)));
    }
    public function configuredForMode(array $credentials, string $mode): bool
    {
        return $mode === 'test' && str_starts_with($credentials['secret_key'] ?? '', 'sk_test_')
            && strlen($credentials['secret_key']) > 12 && str_starts_with($credentials['webhook_secret'] ?? '', 'whsec_')
            && strlen($credentials['webhook_secret']) > 10;
    }
    public function callbackMode(): string { return 'test'; }
    public static function safeCheckoutUrl(mixed $url): bool
    {
        if (!is_string($url) || strlen($url) > 1000 || preg_match('/[\x00-\x20\x7f]/', $url)) return false;
        $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') === 'checkout.stripe.com' && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['port']);
    }
    public function createSession(array $payment, array $credentials): array
    {
        if (!$this->ready() || !$this->configuredForMode($credentials, 'test')
            || ($payment['provider'] ?? '') !== 'stripe' || ($payment['provider_mode'] ?? '') !== 'test'
            || ($payment['currency'] ?? '') !== 'USD' || ($payment['status'] ?? '') !== 'pending') {
            throw new ShopStripeUnavailable('Card payments are unavailable. Your order remains unpaid.');
        }
        $params = [
            'mode'=>'payment', 'allowed_payment_method_types'=>['card'],
            'adaptive_pricing'=>['enabled'=>'false'], 'automatic_tax'=>['enabled'=>'false'],
            'client_reference_id'=>(string)$payment['order_id'],
            'metadata'=>['dyndel_order_id'=>(string)$payment['order_id'], 'dyndel_payment_id'=>(string)$payment['id'], 'dyndel_mode'=>'test'],
            'line_items'=>[['quantity'=>1,'price_data'=>['currency'=>'usd',
                'unit_amount'=>shop_payment_cents($payment['amount']),
                'product_data'=>['name'=>'Dyndel order ' . (int)$payment['order_id']]]]],
            'success_url'=>$this->baseUrl . '/payment-return.html?outcome=success&order=' . (int)$payment['order_id'],
            'cancel_url'=>$this->baseUrl . '/payment-return.html?outcome=cancel&order=' . (int)$payment['order_id'],
        ];
        $idempotency = 'dyndel-g3-' . hash('sha256', $payment['id'] . ':' . $payment['attempt_reference']);
        if ($this->transport) {
            $session = ($this->transport)('/v1/checkout/sessions', $params, $idempotency, $credentials);
        } else {
            $curl = curl_init('https://api.stripe.com/v1/checkout/sessions');
            curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_POSTFIELDS=>http_build_query($params), CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>30,
                CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . $credentials['secret_key'],
                    'Stripe-Version: ' . self::API_VERSION, 'Idempotency-Key: ' . $idempotency],
            ]);
            $body = curl_exec($curl);
            $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            if ($body === false || $code < 200 || $code >= 300) throw new ShopStripeUnavailable('Card payment session could not be opened. Retry safely; your order remains unpaid.');
            try { $session = json_decode($body, true, 32, JSON_THROW_ON_ERROR); }
            catch (JsonException) { throw new ShopStripeUnavailable('Card payment session could not be opened.'); }
        }
        if (!is_array($session) || ($session['livemode'] ?? null) !== false || ($session['mode'] ?? '') !== 'payment'
            || ($session['amount_total'] ?? null) !== shop_payment_cents($payment['amount'])
            || ($session['currency'] ?? '') !== 'usd' || ($session['status'] ?? '') !== 'open'
            || ($session['payment_status'] ?? '') !== 'unpaid'
            || ($session['client_reference_id'] ?? '') !== $params['client_reference_id']
            || !is_array($session['metadata'] ?? null)
            || count($session['metadata']) !== count($params['metadata'])
            || ($session['metadata']['dyndel_order_id'] ?? null) !== $params['metadata']['dyndel_order_id']
            || ($session['metadata']['dyndel_payment_id'] ?? null) !== $params['metadata']['dyndel_payment_id']
            || ($session['metadata']['dyndel_mode'] ?? null) !== 'test'
            || !is_string($session['id'] ?? null) || !preg_match('/\Acs_test_[A-Za-z0-9]+\z/D', $session['id'])
            || !self::safeCheckoutUrl($session['url'] ?? null) || !is_int($session['expires_at'] ?? null) || $session['expires_at'] <= time()) {
            throw new ShopStripeUnavailable('Card payment session did not match the prepared order.');
        }
        return ['id'=>$session['id'], 'url'=>$session['url'], 'expiresAt'=>$session['expires_at']];
    }
    public function verifyCallback(string $payload, array $headers, array $credentials): ShopVerifiedProviderResult
    {
        $secret = $credentials['webhook_secret'] ?? '';
        if (!is_string($secret) || !str_starts_with($secret, 'whsec_')) throw new ShopStripeUnavailable('Webhook verification is not configured.');
        $header = $headers['stripe-signature'] ?? '';
        if (!is_string($header)) throw new DomainException('Invalid webhook signature.');
        $timestamp = null; $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key,$value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value) && strlen($value) <= 12) $timestamp = (int)$value;
            if ($key === 'v1' && preg_match('/\A[0-9a-f]{64}\z/D', $value)) $signatures[] = $value;
        }
        if ($timestamp === null || abs(time()-$timestamp) > 300) throw new DomainException('Invalid webhook signature.');
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $valid = false;
        foreach ($signatures as $signature) if (hash_equals($expected, $signature)) $valid = true;
        if (!$valid) throw new DomainException('Invalid webhook signature.');
        try { $event = json_decode($payload, true, 64, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new DomainException('Invalid webhook event.'); }
        if (!is_array($event) || ($event['livemode'] ?? null) !== false || isset($event['account'])) throw new DomainException('Only direct Stripe Test events are accepted.');
        $type = $event['type'] ?? '';
        if (!in_array($type, ['checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.async_payment_failed','checkout.session.expired'], true)) {
            throw new ShopIgnoredStripeEvent('Event is outside G3.');
        }
        $session = $event['data']['object'] ?? [];
        if (($session['object'] ?? '') !== 'checkout.session' || ($session['livemode'] ?? null) !== false
            || ($session['mode'] ?? '') !== 'payment' || ($session['currency'] ?? '') !== 'usd'
            || !is_int($session['amount_total'] ?? null) || !is_string($session['id'] ?? null)
            || !preg_match('/\Acs_test_[A-Za-z0-9]+\z/D', $session['id'])) throw new DomainException('Invalid Test Checkout session.');
        $paid = in_array($type, ['checkout.session.completed','checkout.session.async_payment_succeeded'], true);
        if ($paid && ($session['payment_status'] ?? '') !== 'paid') throw new ShopIgnoredStripeEvent('Payment is not yet verified Paid.');
        if (!$paid && ($session['payment_status'] ?? '') === 'paid') throw new DomainException('Conflicting terminal session event.');
        $status = $paid ? 'paid' : ($type === 'checkout.session.expired' ? 'cancelled' : 'failed');
        if (!$paid && (($type === 'checkout.session.expired' && ($session['status'] ?? '') !== 'expired')
            || ($type === 'checkout.session.async_payment_failed' && ($session['status'] ?? '') !== 'complete'))) throw new DomainException('Invalid terminal session state.');
        if ($paid && (($session['status'] ?? '') !== 'complete' || !is_string($session['payment_intent'] ?? null)
            || !preg_match('/\Api_[A-Za-z0-9]+\z/D', $session['payment_intent']))) throw new DomainException('Invalid paid capture.');
        $metadata = $session['metadata'] ?? [];
        foreach (['dyndel_order_id','dyndel_payment_id'] as $field) {
            if (!is_string($metadata[$field] ?? null) || !preg_match('/\A[1-9][0-9]{0,9}\z/D', $metadata[$field])) throw new DomainException('Invalid payment association.');
        }
        if (($metadata['dyndel_mode'] ?? '') !== 'test' || ($session['client_reference_id'] ?? '') !== $metadata['dyndel_order_id']
            || !is_string($event['id'] ?? null) || !preg_match('/\Aevt_[A-Za-z0-9]+\z/D', $event['id'])) throw new DomainException('Invalid payment association.');
        return new ShopVerifiedProviderResult((int)$metadata['dyndel_payment_id'], $event['id'], $type, $status,
            shop_money_from_cents($session['amount_total']), 'USD', $paid ? $session['payment_intent'] : null,
            $status === 'failed' ? 'session_payment_failed' : null, $session['id'], 'test', (int)$metadata['dyndel_order_id']);
    }
    public function validateBinding(PDO $pdo, ShopVerifiedProviderResult $result): void
    {
        $stmt = $pdo->prepare('SELECT order_id,provider,provider_mode,provider_session_id FROM shop_payments WHERE id=?');
        $stmt->execute([$result->paymentId]); $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($payment && $payment['provider_session_id'] === null) throw new ShopStripeUnavailable('Session assignment is pending; retry delivery.');
        if (!$payment || $payment['provider'] !== 'stripe' || $payment['provider_mode'] !== 'test'
            || $result->providerMode !== 'test' || (int)$payment['order_id'] !== $result->orderId
            || $payment['provider_session_id'] !== $result->sessionId) throw new DomainException('Webhook session does not match a bound payment.');
    }
}
