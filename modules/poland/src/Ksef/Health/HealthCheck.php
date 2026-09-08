<?php

declare(strict_types=1);

namespace Poland\Ksef\Health;

/** One line of the health panel. `state` is a closed vocabulary a screen can colour. */
final class HealthCheck implements \JsonSerializable
{
    public const OK = 'OK';

    public const FAILED = 'FAILED';

    public const UNKNOWN = 'UNKNOWN';

    public const NOT_APPLICABLE = 'N/A';

    public function __construct(
        public readonly string $name,
        public readonly string $state,
        public readonly string $detail,
        public readonly ?string $checkedAt = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        return ['name' => $this->name, 'state' => $this->state, 'detail' => $this->detail, 'checked_at' => $this->checkedAt];
    }
}
