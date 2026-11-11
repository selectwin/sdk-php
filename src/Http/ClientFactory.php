<?php

declare(strict_types=1);

namespace Selectwin\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Builds the Guzzle client shared by every generated Api: a HandlerStack with
 * retry (429/5xx/network, backoff honouring Retry-After) + an idempotency-key
 * stamper on mutations. Injected once, so every endpoint inherits it.
 */
final class ClientFactory
{
    private const RETRYABLE = [429, 500, 502, 503, 504];
    private const MUTATING = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public static function create(int $maxRetries = 2, ?float $timeout = 60.0, ?callable $handler = null): Client
    {
        $stack = HandlerStack::create($handler);
        // ORDER MATTERS: the middleware pushed FIRST is the OUTERMOST. The path guard and the idempotency key run
        // before the retry, so every attempt is the same request carrying the SAME key. Pushed after the retry (as it
        // was until 11/11/2026), each attempt drew a new key: a 502/504 or a timeout after the API had already charged
        // became a second charge (security sweep 31, P-1).
        $stack->push(Middleware::mapRequest([self::class, 'rejectDotSegments']), 'selectwin_path_guard');
        $stack->push(Middleware::mapRequest([self::class, 'addIdempotencyKey']), 'selectwin_idempotency');
        $stack->push(Middleware::retry(
            self::decider($maxRetries),
            self::delay(),
        ), 'selectwin_retry');

        // The API never redirects. Following one would carry the `selectkey` header to another host (Guzzle strips
        // only Authorization and Cookie on a cross-host redirect) and could downgrade https to http.
        $config = ['handler' => $stack, 'http_errors' => true, 'allow_redirects' => false];
        if ($timeout && $timeout > 0) {
            $config['timeout'] = $timeout;
        }
        return new Client($config);
    }

    /**
     * A dot segment survives rawurlencode() and the URL is normalized before it reaches the API (curl and the edge
     * both resolve `..`): `$sw->variants->delete('prd_1', '..')` would become DELETE /v1/products/prd_1 — another
     * operation, with the seller's key. No Selectwin id is `.` or `..`, so a path carrying one is refused before it
     * is sent (security sweep 31, S-1).
     */
    public static function rejectDotSegments(RequestInterface $request): RequestInterface
    {
        foreach (explode('/', $request->getUri()->getPath()) as $segment) {
            if (preg_match('/^(?:\.|%2e){1,2}$/i', $segment) === 1) {
                throw new \InvalidArgumentException(
                    'Selectwin: a path parameter cannot be "." or ".." (the request would reach another endpoint).',
                );
            }
        }
        return $request;
    }

    public static function addIdempotencyKey(RequestInterface $request): RequestInterface
    {
        if (in_array(strtoupper($request->getMethod()), self::MUTATING, true)
            && !$request->hasHeader('X-Idempotency-Key')
        ) {
            return $request->withHeader('X-Idempotency-Key', bin2hex(random_bytes(16)));
        }
        return $request;
    }

    private static function decider(int $maxRetries): \Closure
    {
        return static function (
            int $retries,
            RequestInterface $request,
            ?ResponseInterface $response = null,
            ?\Throwable $exception = null,
        ) use ($maxRetries): bool {
            if ($retries >= $maxRetries) {
                return false;
            }
            if ($exception instanceof ConnectException) {
                return true;
            }
            $status = $response?->getStatusCode()
                ?? ($exception && method_exists($exception, 'getResponse')
                    ? $exception->getResponse()?->getStatusCode()
                    : null);
            return $status !== null && in_array($status, self::RETRYABLE, true);
        };
    }

    private static function delay(): \Closure
    {
        return static function (int $retries, ?ResponseInterface $response = null): int {
            $retryAfter = $response?->getHeaderLine('Retry-After');
            if ($retryAfter !== null && $retryAfter !== '' && is_numeric($retryAfter)) {
                return min((int) ((float) $retryAfter * 1000), 20_000);
            }
            return min(500 * (2 ** $retries), 8_000) + random_int(0, 250); // ms + jitter
        };
    }
}
