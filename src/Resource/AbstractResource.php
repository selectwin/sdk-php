<?php

declare(strict_types=1);

namespace Selectwin\Resource;

use Selectwin\ApiException as CoreApiException;
use Selectwin\Exception\ErrorFactory;
use Selectwin\ObjectSerializer;

/**
 * Base for the generated Stripe-style resources. Wraps a generated core Api,
 * translating {@see CoreApiException} into the typed SDK exception hierarchy and
 * coercing array request bodies into their generated models.
 */
abstract class AbstractResource
{
    public function __construct(protected object $api) {}

    /** Escape hatch: the underlying generated core Api (all `operationId` methods). */
    public function raw(): object
    {
        return $this->api;
    }

    /** Run a core call, mapping core exceptions to typed SDK exceptions. */
    protected function call(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (CoreApiException $e) {
            throw ErrorFactory::fromApiException($e);
        }
    }

    /** Coerce a plain array/object request body into its generated model. */
    protected function model(mixed $body, string $class): mixed
    {
        if ($body instanceof $class) {
            return $body;
        }
        if (is_object($body) && !($body instanceof \stdClass)) {
            return $body;
        }
        $data = json_decode(json_encode($body)); // deep stdClass for nested objects
        return ObjectSerializer::deserialize($data, $class, null);
    }
}
