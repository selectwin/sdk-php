<?php

declare(strict_types=1);

namespace Selectwin\Exception;

use Selectwin\ApiException as CoreApiException;

/**
 * Base class for every error raised by the SDK. Branch on the class or
 * {@see getSelectwinCode()} (`error.code`), never the human message.
 */
class SelectwinException extends \Exception
{
    public function __construct(
        string $message = '',
        private ?string $selectwinCode = null,
        private ?int $statusCode = null,
        private ?string $requestId = null,
        private mixed $errorBody = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Stable, machine-readable error code (`error.code`); null for connection errors. */
    public function getSelectwinCode(): ?string
    {
        return $this->selectwinCode;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /** The parsed error envelope (or the underlying error for connection failures). */
    public function getErrorBody(): mixed
    {
        return $this->errorBody;
    }
}

/** The request never produced a response (network error, timeout, DNS...). */
class ApiConnectionException extends SelectwinException {}

/** A server-side error (5xx) or an otherwise-unclassified API error. */
class ApiErrorException extends SelectwinException {}

/** 401 — the API key/token is missing or invalid. */
class AuthenticationException extends SelectwinException {}

/** 403 — authenticated but not allowed (scope, IP restriction). */
class PermissionDeniedException extends SelectwinException {}

/** 404 — the resource does not exist. */
class NotFoundException extends SelectwinException {}

/** 409 — idempotency conflict (replay in progress or divergent body). */
class ConflictException extends SelectwinException {}

/** 400/422 — request validation failed. Per-field details in {@see getParams()}. */
class ValidationException extends SelectwinException
{
    private mixed $params = null;

    public function withParams(mixed $params): static
    {
        $this->params = $params;
        return $this;
    }

    public function getParams(): mixed
    {
        return $this->params;
    }
}

/** 402 — the issuer declined the charge (transactions/subscriptions). */
class CardException extends SelectwinException
{
    private ?string $displayMessage = null;
    private ?bool $reversible = null;

    public function withCardDetails(?string $displayMessage, ?bool $reversible): static
    {
        $this->displayMessage = $displayMessage;
        $this->reversible = $reversible;
        return $this;
    }

    /** Buyer-facing message safe to display (`error.displayMessage`). */
    public function getDisplayMessage(): ?string
    {
        return $this->displayMessage;
    }

    /** Whether the same charge can plausibly be retried (`error.reversible`). */
    public function isReversible(): ?bool
    {
        return $this->reversible;
    }
}

/** 429 — rate limit exceeded. {@see getRetryAfter()} is seconds, when sent. */
class RateLimitException extends SelectwinException
{
    private ?float $retryAfter = null;

    public function withRetryAfter(?float $retryAfter): static
    {
        $this->retryAfter = $retryAfter;
        return $this;
    }

    public function getRetryAfter(): ?float
    {
        return $this->retryAfter;
    }
}

/** A webhook signature could not be verified. */
class SignatureVerificationException extends SelectwinException {}

/**
 * Maps HTTP status + error envelopes to the typed exception hierarchy.
 */
final class ErrorFactory
{
    private const STATUS_MESSAGE = [
        400 => 'The request was invalid.',
        401 => 'Invalid or missing API credentials.',
        402 => 'The payment was declined.',
        403 => 'You do not have permission to perform this action.',
        404 => 'The requested resource was not found.',
        409 => 'Idempotency conflict.',
        422 => 'The request could not be processed.',
        429 => 'Too many requests.',
    ];

    /** Translate a generated core {@see CoreApiException} into a typed SDK exception. */
    public static function fromApiException(CoreApiException $e): SelectwinException
    {
        $status = $e->getCode();
        $headers = $e->getResponseHeaders() ?? [];
        $requestId = self::header($headers, 'x-request-id');
        $retryAfter = self::header($headers, 'retry-after');
        return self::build(
            is_int($status) ? $status : 0,
            $e->getResponseBody(),
            $requestId,
            $retryAfter !== null && is_numeric($retryAfter) ? (float) $retryAfter : null,
            $e,
        );
    }

    public static function build(
        int $status,
        mixed $body,
        ?string $requestId = null,
        ?float $retryAfter = null,
        ?\Throwable $previous = null,
    ): SelectwinException {
        $env = self::envelope($body);
        $err = is_array($env['error'] ?? null) ? $env['error'] : [];
        $code = $err['code'] ?? null;
        $message = $err['message'] ?? (self::STATUS_MESSAGE[$status] ?? ($status ? "HTTP {$status}" : 'Connection error'));
        $args = [$message, $code, $status ?: null, $requestId, $env ?: $body, $previous];

        if ($status === 0) {
            return new ApiConnectionException(...$args);
        }
        return match ($status) {
            400, 422 => (new ValidationException(...$args))->withParams($err['params'] ?? null),
            401 => new AuthenticationException(...$args),
            402 => (new CardException(...$args))->withCardDetails(
                $err['displayMessage'] ?? null,
                array_key_exists('reversible', $err) ? (bool) $err['reversible'] : null,
            ),
            403 => new PermissionDeniedException(...$args),
            404 => new NotFoundException(...$args),
            409 => new ConflictException(...$args),
            429 => (new RateLimitException(...$args))->withRetryAfter($retryAfter),
            default => new ApiErrorException(...$args),
        };
    }

    /** Best-effort parse of the error body into an assoc array. */
    private static function envelope(mixed $body): array
    {
        if (is_array($body)) {
            return $body;
        }
        if (is_object($body)) {
            $decoded = json_decode(json_encode($body), true);
            return is_array($decoded) ? $decoded : [];
        }
        if (is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $name) {
                return is_array($value) ? ($value[0] ?? null) : (string) $value;
            }
        }
        return null;
    }
}
