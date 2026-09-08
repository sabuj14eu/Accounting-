<?php

declare(strict_types=1);

namespace Poland\Ksef\Fa3;

use DateTimeImmutable;

/** What the validator concluded, with every reason a document was refused. */
final class Fa3ValidationResult implements \JsonSerializable
{
    /** @param list<string> $errors */
    public function __construct(
        public readonly bool $valid,
        public readonly array $errors,
        public readonly string $schema,
        public readonly int $sizeBytes,
        public readonly DateTimeImmutable $validatedAt,
        public readonly string $stage,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'valid' => $this->valid,
            'stage' => $this->stage,
            'errors' => $this->errors,
            'schema' => $this->schema,
            'size_bytes' => $this->sizeBytes,
            'validated_at' => $this->validatedAt->format(DATE_ATOM),
        ];
    }
}
