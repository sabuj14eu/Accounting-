<?php

declare(strict_types=1);

namespace Poland\Contracts;

/**
 * What came back from an actual submission.
 *
 * A result without a reference is not an acceptance, whatever its status field
 * says. `accepted()` requires both, because "we got a 200" is the exact failure
 * mode that lets software claim something was filed when it was not.
 */
final class FilingResult implements \JsonSerializable
{
    /** @param array<string,mixed> $raw the receiving system's response, kept verbatim */
    public function __construct(
        public readonly string $status,
        public readonly ?string $reference,
        public readonly \DateTimeImmutable $at,
        public readonly array $raw = [],
        public readonly ?string $error = null,
    ) {
    }

    public function accepted(): bool
    {
        return $this->error === null
            && $this->reference !== null
            && trim($this->reference) !== '';
    }

    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status,
            'reference' => $this->reference,
            'accepted' => $this->accepted(),
            'at' => $this->at->format(DATE_ATOM),
            'error' => $this->error,
            'raw' => $this->raw,
        ];
    }
}
