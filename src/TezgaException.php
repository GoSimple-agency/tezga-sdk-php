<?php

declare(strict_types=1);

namespace GoSimple\Tezga;

/**
 * Thrown on any non-2xx response.
 *
 * The API returns { error, fields[] }, so the message is the API's `error` string and
 * getFields() carries per-field validation problems. Notable statuses: 402 (subscription
 * inactive or credit spent), 403 (read-only key, POS terminal key, or an IP outside the
 * key's allowlist), 409 (conflict, e.g. the externalId is in flight), 413 (body over
 * 512 KB), 422 (missing/empty array, over 500 items, or the Tax Administration rejected
 * it), 429 (rate limit).
 */
final class TezgaException extends \RuntimeException
{
    /** @param list<array{path?: string, message?: string}> $fields */
    public function __construct(
        string $message,
        private int $status,
        private mixed $body = null,
        private array $fields = [],
    ) {
        parent::__construct($message, $status);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getBody(): mixed
    {
        return $this->body;
    }

    /** @return list<array{path?: string, message?: string}> */
    public function getFields(): array
    {
        return $this->fields;
    }

    /** True when retrying later can succeed (rate limit, or a conflict still in flight). */
    public function isRetryable(): bool
    {
        return $this->status === 429 || $this->status === 409;
    }
}
