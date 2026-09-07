<?php

declare(strict_types=1);

namespace Poland\Contracts;

use Poland\Domain\Period;
use Poland\Reporting\FilingChannel;

/**
 * A document built and validated, ready to be submitted — and not submitted.
 *
 * It records the schema version and the rule/rate versions it was built from,
 * so that the document, the numbers in it and the law it was built under can
 * all be reconstructed later. Without that, a document filed in March cannot be
 * defended in an audit two years afterwards.
 */
final class PreparedDocument implements \JsonSerializable
{
    /**
     * @param array<string,mixed> $ruleVersions rate/rule table versions used
     * @param list<string> $validationErrors empty when the document validates
     */
    public function __construct(
        public readonly FilingChannel $channel,
        public readonly Period $period,
        public readonly SchemaVersion $schema,
        public readonly string $contentType,
        public readonly string $content,
        public readonly array $ruleVersions = [],
        public readonly array $validationErrors = [],
        /**
         * False when the schema XSD was not available, so the document could
         * not actually be checked. Distinct from "validated with no errors" —
         * an unvalidated document is not a valid one.
         */
        public readonly bool $validated = false,
        /**
         * Stable key that makes a resubmission idempotent: the same logical
         * document must never be filed twice because a retry looked like a new
         * one.
         */
        public readonly ?string $idempotencyKey = null,
    ) {
    }

    public function isValid(): bool
    {
        return $this->validated && $this->validationErrors === [];
    }

    public function checksum(): string
    {
        return hash('sha256', $this->content);
    }

    public function jsonSerialize(): array
    {
        return [
            'channel' => $this->channel->value,
            'period' => $this->period->toString(),
            'schema' => $this->schema,
            'content_type' => $this->contentType,
            'content_length' => strlen($this->content),
            'checksum' => $this->checksum(),
            'rule_versions' => $this->ruleVersions,
            'validated' => $this->validated,
            'valid' => $this->isValid(),
            'validation_errors' => $this->validationErrors,
            'idempotency_key' => $this->idempotencyKey,
        ];
    }
}
