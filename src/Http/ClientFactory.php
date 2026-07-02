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
        $stack->push(Middleware::retry(
            self::decider($maxRetries),
            self::delay(),
        ));
        $stack->push(Middleware::mapRequest([self::class, 'addIdempotencyKey']));

        $config = ['handler' => $stack, 'http_errors' => true];
        if ($timeout && $timeout > 0) {
            $config['timeout'] = $timeout;
        }
        return new Client($config);
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
