# selectwin/sdk (PHP)

Official **Selectwin** PHP SDK — payments (credit card, PIX, boleto),
subscriptions, wallets, webhooks and more.

> Status: **early / work in progress** (`0.1.0`). Generated core (openapi-generator,
> Guzzle) + a hand-written DX shell (typed exceptions, retries, idempotency,
> pagination, webhook verification).

```bash
composer require selectwin/sdk
```

## Quickstart

```php
use Selectwin\SelectwinClient;
use Selectwin\Exception\CardException;

$sw = new SelectwinClient(getenv('SELECTWIN_API_KEY')); // sk_test_… / sk_live_…

// Create a PIX transaction (amounts in cents). Pass an array or a generated model:
$tx = $sw->transactions->create([
    'amount' => 9990,
    'payment' => ['method' => 'pix', 'currency' => 'BRL'],
]);

// id / id+body aliases
$one = $sw->transactions->retrieve('tra_…');
$sw->subscriptions->pause('subs_…');

// Typed exceptions — branch on the class / getSelectwinCode(), never the message
try {
    $sw->transactions->create([/* … */]);
} catch (CardException $e) {
    echo $e->getDisplayMessage(), $e->isReversible(); // buyer-facing + retryable
}
```

Every resource exposes **Stripe-style aliases** (`create`, `retrieve`, `update`, `list`,
`delete`, plus verbs like `$sw->subscriptions->pause('subs_…')`). The full generated API is
always reachable via `->raw()`:

```php
$sw->transactions->raw()->createTransaction($request);
```

### Pagination

Top-level `list()` returns a `Paginator` — iterate every item across pages, grab the first
page, or collect into an array:

```php
foreach ($sw->transactions->list(['limit' => 100]) as $tx) {
    // every transaction across all pages
}

$firstPage = $sw->transactions->list(['limit' => 20])->first(); // page object (getData(), getHasMore())
$some = $sw->customers->list()->toArray(500);                    // collect, optionally capped
```

### Webhooks

```php
// $rawBody MUST be the exact bytes received (do not re-serialize)
$event = $sw->constructEvent(
    $rawBody,
    $_SERVER['HTTP_X_SELECTWIN_SIGNATURE'] ?? null,
    getenv('SELECTWIN_WEBHOOK_SECRET'), // whsec_…
);
if ($event->type === 'transaction.approved') {
    $object = $event->object;
}
```

`Selectwin\WebhookEvents::ALL` is the authoritative catalog; `WebhookEvents::isValid($type)`
validates a value. `$sw->webhooks` also manages endpoints/events/dispatches.

## What the SDK adds over the raw generated client

The package is a **generated core** (from the OpenAPI v2.0.0 spec, namespace `Selectwin\Api`
/ `Selectwin\Model`) + a **hand-written DX shell**:

- **Typed client** — `new SelectwinClient($key)` → `$sw->transactions`, `$sw->subscriptions`, …
- **Stripe-style aliases** per resource accepting an array or a generated model; `->raw()` for
  the full generated surface.
- **Typed exceptions** by HTTP status / `error.code`: `CardException` (402,
  `getDisplayMessage()`/`isReversible()`), `ValidationException` (`getParams()`),
  `RateLimitException` (`getRetryAfter()`), `AuthenticationException`, `PermissionDeniedException`,
  `NotFoundException`, `ConflictException`, `ApiErrorException`, `ApiConnectionException`.
- **Auto-retries** (429/5xx/network) with backoff honouring `Retry-After` (Guzzle handler stack).
- **Idempotency** — an `X-Idempotency-Key` is added to every mutation.
- **Pagination** — `Paginator` (iterate, `first()`, `toArray()`, `pages()`).
- **Webhook verification** — `constructEvent` (HMAC-SHA256 of the raw body, constant-time).

Auth is the `selectkey` header; the environment (sandbox/production) is resolved from the key
prefix (`sk_test_` / `sk_live_`).

## Architecture

```
lib/                       # openapi-generator core (Selectwin\Api, \Model, Configuration…) — synced; DO NOT edit
src/
  SelectwinClient.php      # the client — wires Configuration + Guzzle into every resource
  Resource/                # GENERATED Stripe-style resources (gen_resources.php) — DO NOT edit
  Exception/Exceptions.php # typed exception hierarchy + ErrorFactory
  Http/ClientFactory.php   # Guzzle handler stack (retries) + idempotency middleware
  Pagination/Paginator.php
  Webhook/                 # WebhookSignature (constructEvent) + Event
  WebhookEvents.php        # GENERATED Event Catalog
```

`lib/` and `src/` share the `Selectwin\` namespace via two PSR-4 directories. Cross-cutting
concerns are injected once into the Guzzle client + `Configuration`, so every endpoint inherits
them and new endpoints work automatically on regen.

## Development

```bash
composer install
php scripts/sync_core.php          # copy the generated core into lib/ (from selectwin-sdks)
php scripts/gen_resources.php      # regenerate src/Resource/*
php scripts/gen_webhook_events.php # regenerate src/WebhookEvents.php
vendor/bin/phpunit --testsuite unit
SELECTWIN_SANDBOX_KEY=sk_test_... vendor/bin/phpunit --testsuite integration
```

## Roadmap

- Publish on Packagist once the API stabilises; a Laravel package (`selectwin/laravel`) on top.
