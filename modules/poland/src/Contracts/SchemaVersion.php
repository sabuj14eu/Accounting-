<?php

declare(strict_types=1);

namespace Poland\Contracts;

use Poland\Domain\Period;

/**
 * A version of an external schema (KSeF FA, JPK_V7M, and so on) with the period
 * it applies to.
 *
 * Polish e-filing schemas change on announced dates and old periods keep being
 * filed under old schemas — a correction to a 2025 month must use the schema
 * that was current then. So the version is chosen by the period being filed,
 * never by "the newest one we support".
 */
final class SchemaVersion implements \JsonSerializable
{
    public function __construct(
        /** Structure name, e.g. "JPK_V7M" or "FA". */
        public readonly string $structure,
        /** Version identifier as the authority writes it, e.g. "(2)" or "1-0E". */
        public readonly string $version,
        public readonly Period $effectiveFrom,
        public readonly ?Period $effectiveTo,
        /** XSD namespace or location, when the authority publishes one. */
        public readonly ?string $namespace = null,
        /** Local path to the XSD used for validation, when it has been obtained. */
        public readonly ?string $schemaPath = null,
        public readonly ?string $publishedUrl = null,
    ) {
    }

    public function covers(Period $period): bool
    {
        if ($period->isBefore($this->effectiveFrom)) {
            return false;
        }

        return $this->effectiveTo === null || ! $period->isAfter($this->effectiveTo);
    }

    /** Whether the XSD is actually present, so validation can really happen. */
    public function isValidatable(): bool
    {
        return $this->schemaPath !== null && is_file($this->schemaPath);
    }

    public function identifier(): string
    {
        return $this->structure.' '.$this->version;
    }

    public function jsonSerialize(): array
    {
        return [
            'structure' => $this->structure,
            'version' => $this->version,
            'effective_from' => $this->effectiveFrom->toString(),
            'effective_to' => $this->effectiveTo?->toString(),
            'namespace' => $this->namespace,
            'validatable' => $this->isValidatable(),
            'published_url' => $this->publishedUrl,
        ];
    }
}
