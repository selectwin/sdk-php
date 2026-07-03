<?php

declare(strict_types=1);

namespace Selectwin;

use GuzzleHttp\Client;
use Selectwin\Http\ClientFactory;
use Selectwin\Resource\ResourceRegistry;
use Selectwin\Webhook\Event;
use Selectwin\Webhook\WebhookSignature;

/**
 * The Selectwin client — the hand-written DX shell over the generated core.
 *
 *     $sw = new \Selectwin\SelectwinClient('sk_test_…');
 *     $tx = $sw->transactions->create(['amount' => 9990, 'payment' => ['method' => 'pix', 'currency' => 'BRL']]);
 *
 * One Guzzle client (retry + idempotency handler stack) and one Configuration
 * are shared by every resource, so auth, retries, idempotency and typed-error
 * mapping apply everywhere.
 *
 * @property-read \Selectwin\Resource\AddressesResource $addresses
 * @property-read \Selectwin\Resource\CardsResource $cards
 * @property-read \Selectwin\Resource\CheckoutResource $checkout
 * @property-read \Selectwin\Resource\CouponsResource $coupons
 * @property-read \Selectwin\Resource\CustomersResource $customers
 * @property-read \Selectwin\Resource\FinanceResource $finance
 * @property-read \Selectwin\Resource\ProductsResource $products
 * @property-read \Selectwin\Resource\ProductsVariantsResource $variants
 * @property-read \Selectwin\Resource\ReceivablesResource $receivables
 * @property-read \Selectwin\Resource\SimulatorsResource $simulators
 * @property-read \Selectwin\Resource\SubscriptionsResource $subscriptions
 * @property-read \Selectwin\Resource\TransactionsResource $transactions
 * @property-read \Selectwin\Resource\UtilsResource $utils
 * @property-read \Selectwin\Resource\WalletsResource $wallets
 * @property-read \Selectwin\Resource\WebhooksResource $webhooks
 * @property-read \Selectwin\Resource\WithdrawalsResource $withdrawals
 */
final class SelectwinClient
{
    private const DEFAULT_BASE_URL = 'https://api.selectwin.io';

    /**
     * SDK version — read from Composer's installed metadata (single source of truth =
     * the git tag / composer.json), so the User-Agent never drifts from the release.
     * Never hardcode a version constant here; it only drifts. Falls back to
     * `0.0.0-dev` when the package isn't installed via Composer.
     */
    public static function version(): string
    {
        if (
            class_exists(\Composer\InstalledVersions::class)
            && \Composer\InstalledVersions::isInstalled('selectwin/sdk')
        ) {
            $v = \Composer\InstalledVersions::getPrettyVersion('selectwin/sdk');
            if (is_string($v) && $v !== '') {
                return ltrim($v, 'v');
            }
        }

        return '0.0.0-dev';
    }

    private Configuration $config;
    private Client $http;
    private HeaderSelector $headerSelector;
    /** @var array<string, object> */
    private array $resources = [];

    /**
     * @param array{base_url?: string, max_retries?: int, timeout?: ?float, user_agent?: string, handler?: callable} $options
     */
    public function __construct(string $apiKey, array $options = [])
    {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('Selectwin: an API key is required (sk_test_… or sk_live_…).');
        }

        $this->config = (new Configuration())
            ->setHost($options['base_url'] ?? self::DEFAULT_BASE_URL)
            ->setUserAgent($options['user_agent'] ?? ('selectwin-php/' . self::version()));
        $this->config->setApiKey('selectkey', $apiKey); // Selectwin auth header

        $this->http = ClientFactory::create(
            $options['max_retries'] ?? 2,
            $options['timeout'] ?? 60.0,
            $options['handler'] ?? null, // inject a Guzzle handler (e.g. MockHandler) for tests
        );
        $this->headerSelector = new HeaderSelector();

        foreach (ResourceRegistry::MAP as $attr => [$apiClass, $resClass]) {
            $api = new $apiClass($this->http, $this->config, $this->headerSelector);
            $this->resources[$attr] = new $resClass($api);
        }
    }

    public function __get(string $name): object
    {
        return $this->resources[$name]
            ?? throw new \InvalidArgumentException("Unknown resource: {$name}");
    }

    public function __isset(string $name): bool
    {
        return isset($this->resources[$name]);
    }

    /** Verify a webhook signature and return the typed event. */
    public function constructEvent(string $rawBody, ?string $signatureHeader, string $secret, array $options = []): Event
    {
        return WebhookSignature::constructEvent($rawBody, $signatureHeader, $secret, $options);
    }

    /** Escape hatch: the shared Guzzle client. */
    public function getHttpClient(): Client
    {
        return $this->http;
    }

    /** Escape hatch: the shared core Configuration. */
    public function getConfiguration(): Configuration
    {
        return $this->config;
    }
}
