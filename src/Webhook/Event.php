<?php

declare(strict_types=1);

namespace Selectwin\Webhook;

/**
 * A verified webhook event (see the Event Catalog for `type` values).
 * The resource is at {@see $object} (delivered as `payload.object`).
 */
final class Event
{
    public readonly ?string $id;
    public readonly ?string $type;
    public readonly ?string $source;
    /** The resource object carried by the event (`payload.object`). */
    public readonly mixed $object;
    public readonly mixed $correlationId;
    public readonly mixed $account;
    public readonly mixed $createdAt;
    public readonly mixed $updatedAt;

    public function __construct(public readonly array $data)
    {
        $this->id = $data['id'] ?? null;
        $this->type = $data['type'] ?? null;
        $this->source = $data['source'] ?? null;
        $payload = $data['payload'] ?? [];
        $this->object = is_array($payload) ? ($payload['object'] ?? null) : null;
        $this->correlationId = $data['correlationId'] ?? null;
        $this->account = $data['account'] ?? null;
        $this->createdAt = $data['createdAt'] ?? null;
        $this->updatedAt = $data['updatedAt'] ?? null;
    }
}
