<?php

declare(strict_types=1);

namespace Poland\Purchases;

use Poland\Ksef\Parsing\InvoiceLine;

/** One invoice line together with what (if anything) this shop knows it to be. */
final class LineResolution implements \JsonSerializable
{
    public const SOURCE_ALIAS = 'alias';

    public const SOURCE_OWNER = 'owner';

    public const SOURCE_NONE = 'none';

    public function __construct(
        public readonly InvoiceLine $line,
        public readonly ?ProductMapping $mapping,
        public readonly string $mappingSource = self::SOURCE_NONE,
    ) {
    }

    public static function unmapped(InvoiceLine $line): self
    {
        return new self($line, null, self::SOURCE_NONE);
    }

    public function isMapped(): bool
    {
        return $this->mapping !== null;
    }

    public function isTracked(): bool
    {
        return $this->mapping?->tracked === true;
    }

    public function jsonSerialize(): array
    {
        return [
            'line' => $this->line,
            'mapping' => $this->mapping,
            'mapping_source' => $this->mappingSource,
            'tracked' => $this->isTracked(),
        ];
    }
}
