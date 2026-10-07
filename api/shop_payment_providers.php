<?php
declare(strict_types=1);

/** Trusted server code only. Credentials must never enter serialized responses. */
interface ShopPaymentProviderAdapter
{
    public function ready(): bool;
    /** $payment must be a persisted G1 record, never a browser-supplied quote. */
    public function createSession(array $payment, array $credentials): array;
    /** Authenticate signature/account/mode, then return normalized verified data. */
    public function verifyCallback(string $payload, array $headers, array $credentials): ShopVerifiedProviderResult;
}

final class ShopVerifiedProviderResult
{
    public function __construct(
        public readonly int $paymentId,
        public readonly string $eventId,
        public readonly string $eventType,
        public readonly string $status,
        public readonly string $amount,
        public readonly string $currency,
        public readonly ?string $captureId = null,
        public readonly ?string $failureCode = null
    ) {}
}

/** Deliberately dormant: no SDK, requests, transactions or callback verification. */
final class ShopUnconnectedProviderAdapter implements ShopPaymentProviderAdapter
{
    public function ready(): bool { return false; }
    public function createSession(array $payment, array $credentials): array
    {
        throw new LogicException('Provider integration is not implemented.');
    }
    public function verifyCallback(string $payload, array $headers, array $credentials): ShopVerifiedProviderResult
    {
        throw new LogicException('Provider integration is not implemented.');
    }
}

final class ShopPaymentProviderRegistry
{
    private const PROVIDERS = [
        'paypal' => ['name' => 'PayPal', 'fields' => ['client_id','client_secret','webhook_id']],
        'stripe' => ['name' => 'Stripe', 'fields' => ['publishable_key','secret_key','webhook_secret']],
    ];
    private array $adapters;
    private Closure $credentialLoader;

    /** Overrides are for trusted server composition/tests, NEVER HTTP/UI input. */
    public function __construct(?array $adapters = null, ?Closure $credentialLoader = null)
    {
        $this->adapters = $adapters ?? [
            'paypal' => new ShopUnconnectedProviderAdapter(),
            'stripe' => new ShopUnconnectedProviderAdapter(),
        ];
        if (array_keys($this->adapters) !== array_keys(self::PROVIDERS)) {
            throw new DomainException('Only the fixed PayPal and Stripe registry is supported.');
        }
        foreach ($this->adapters as $adapter) {
            if (!$adapter instanceof ShopPaymentProviderAdapter) throw new DomainException('Invalid provider adapter.');
        }
        $this->credentialLoader = $credentialLoader ?? static function (string $provider, string $mode, array $fields): array {
            $local = defined('SHOP_PAYMENT_PROVIDER_CREDENTIALS') ? constant('SHOP_PAYMENT_PROVIDER_CREDENTIALS') : [];
            if (!is_array($local)) $local = [];
            $credentials = [];
            foreach ($fields as $field) {
                $environment = getenv('DYNDEL_' . strtoupper("{$provider}_{$mode}_{$field}"));
                // An explicitly empty environment value disables the local fallback.
                $value = $environment !== false ? $environment : ($local[$provider][$mode][$field] ?? null);
                $credentials[$field] = is_string($value) ? trim($value) : '';
            }
            return $credentials;
        };
    }

    public function validateProvider(mixed $provider): string
    {
        if (!is_string($provider) || !isset(self::PROVIDERS[$provider])) throw new DomainException('Unknown payment provider.');
        return $provider;
    }

    public function validateMode(mixed $mode): string
    {
        if (!is_string($mode) || !in_array($mode, ['test','live'], true)) throw new DomainException('Mode must be Test or Live.');
        return $mode;
    }

    private function credentials(string $provider, string $mode): array
    {
        return ($this->credentialLoader)($provider, $mode, self::PROVIDERS[$provider]['fields']);
    }

    /** Whitelisted output only: no credential values, even public identifiers. */
    public function describe(array $setting): array
    {
        $provider = $this->validateProvider($setting['provider'] ?? null);
        $mode = $this->validateMode($setting['mode'] ?? null);
        $credentials = $this->credentials($provider, $mode);
        $configured = true;
        foreach (self::PROVIDERS[$provider]['fields'] as $field) {
            if (!isset($credentials[$field]) || !is_string($credentials[$field]) || trim($credentials[$field]) === '') $configured = false;
        }
        $enabled = (int)($setting['enabled'] ?? 0) === 1;
        $ready = $this->adapters[$provider]->ready();
        return [
            'key' => $provider, 'displayName' => self::PROVIDERS[$provider]['name'],
            'supported' => true, 'configured' => $configured, 'enabled' => $enabled,
            'mode' => $mode, 'adapterReady' => $ready,
            'available' => $configured && $enabled && $ready,
            'status' => !$configured ? 'Not configured' : (!$ready ? 'Configured' : ($enabled ? 'Enabled' : 'Disabled')),
            'createdAt' => $setting['created_at'] ?? null, 'updatedAt' => $setting['updated_at'] ?? null,
        ];
    }

    public function settings(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT provider,enabled,mode,created_at,updated_at FROM shop_payment_providers ORDER BY provider')->fetchAll(PDO::FETCH_ASSOC);
        $byProvider = array_column($rows, null, 'provider');
        $result = [];
        foreach (self::PROVIDERS as $key => $_) {
            $result[] = $this->describe($byProvider[$key] ?? ['provider' => $key, 'enabled' => 0, 'mode' => 'test']);
        }
        return $result;
    }

    public function update(PDO $pdo, array $input): array
    {
        if (array_diff(array_keys($input), ['provider','mode','enabled'])) throw new DomainException('Only provider, mode and enabled settings are accepted.');
        $provider = $this->validateProvider($input['provider'] ?? null);
        $mode = $this->validateMode($input['mode'] ?? null);
        $rawEnabled = $input['enabled'] ?? null;
        if (!in_array($rawEnabled, ['0','1',0,1,false,true], true)) throw new DomainException('Enabled must be On or Off.');
        $enabled = in_array($rawEnabled, ['1',1,true], true) ? 1 : 0;
        $stmt = $pdo->prepare('INSERT INTO shop_payment_providers (provider,enabled,mode) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), mode = VALUES(mode)');
        $stmt->execute([$provider, $enabled, $mode]);
        foreach ($this->settings($pdo) as $setting) if ($setting['key'] === $provider) return $setting;
        throw new LogicException('Provider settings could not be read.');
    }

    public function publicProviders(PDO $pdo): array
    {
        $available = [];
        foreach ($this->settings($pdo) as $setting) {
            if ($setting['available']) $available[] = [
                'key' => $setting['key'], 'displayName' => $setting['displayName'], 'mode' => $setting['mode'],
            ];
        }
        return $available;
    }

    /** Internal future callback boundary, not exposed as a webhook HTTP route. */
    public function processCallback(PDO $pdo, string $provider, string $payload, array $headers): array
    {
        $this->validateProvider($provider);
        $setting = null;
        foreach ($this->settings($pdo) as $candidate) if ($candidate['key'] === $provider) $setting = $candidate;
        if (!$setting || !$setting['available']) throw new DomainException('Provider is unavailable.');
        $verified = $this->adapters[$provider]->verifyCallback($payload, $headers, $this->credentials($provider, $setting['mode']));
        return shop_payment_process_verified_event($pdo, $verified->paymentId, $provider, $verified->eventId,
            $verified->eventType, $verified->status, $verified->amount, $verified->currency,
            $verified->captureId, $verified->failureCode);
    }
}
