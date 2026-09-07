<?php

declare(strict_types=1);

namespace Poland\Ksef\Parsing;

use DateTimeImmutable;
use Poland\Domain\Money;
use Poland\Ksef\KsefInvoiceMetadata;

/** The result of reading an FA document, including what could not be read. */
final class ParsedInvoice implements \JsonSerializable
{
    /**
     * @param array<string,array{net: Money, vat: Money}> $netByVatRate
     * @param list<string> $missing
     * @param list<string> $validationErrors
     */
    public function __construct(
        public readonly KsefInvoiceMetadata $metadata,
        public readonly ?DateTimeImmutable $saleDate,
        public readonly array $netByVatRate,
        public readonly array $missing,
        public readonly bool $validated,
        public readonly array $validationErrors,
        public readonly string $rootElement,
        public readonly ?string $namespace,
    ) {
    }

    /**
     * Whether enough was read to post this to the books without a human.
     *
     * Requires the fields an accounting entry cannot be made without. A document
     * missing any of them is not "mostly fine" — it is a document somebody has
     * to open.
     */
    public function isComplete(): bool
    {
        $essential = ['invoice_date', 'seller_nip', 'net', 'vat'];

        return array_intersect($essential, $this->missing) === [];
    }

    /** True when the totals the document states are internally consistent. */
    public function totalsAgree(): ?bool
    {
        $net = $this->metadata->net;
        $vat = $this->metadata->vat;
        $gross = $this->metadata->gross;

        if ($net === null || $vat === null || $gross === null) {
            return null;
        }

        return $net->plus($vat)->equals($gross);
    }

    public function jsonSerialize(): array
    {
        return [
            'metadata' => $this->metadata,
            'sale_date' => $this->saleDate?->format('Y-m-d'),
            'net_by_vat_rate' => array_map(
                static fn (array $r): array => ['net' => $r['net'], 'vat' => $r['vat']],
                $this->netByVatRate,
            ),
            'missing' => $this->missing,
            'is_complete' => $this->isComplete(),
            'totals_agree' => $this->totalsAgree(),
            'validated' => $this->validated,
            'validation_errors' => $this->validationErrors,
            'root_element' => $this->rootElement,
            'namespace' => $this->namespace,
        ];
    }
}
