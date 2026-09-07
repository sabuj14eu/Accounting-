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
     * What a field reads as when the document did not supply it.
     *
     * A distinct marker rather than null or 0,00: rendered on a screen or
     * exported to a spreadsheet, an empty cell reads as "nothing was charged"
     * and 0,00 reads as "charged nothing". MISSING_FIELD reads as neither.
     */
    public const MISSING = 'MISSING_FIELD';

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
        /**
         * Elements present in the document that this parser does not map.
         *
         * Recorded rather than discarded: a field a future schema adds is
         * evidence that the parser is behind, and the document itself is kept
         * verbatim so nothing is actually lost either way.
         *
         * @var list<string>
         */
        public readonly array $unknownElements = [],
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

    /** True when the document did not supply this field. */
    public function isMissing(string $field): bool
    {
        return in_array($field, $this->missing, true);
    }

    /**
     * A field's value for display, or the MISSING_FIELD marker.
     *
     * Callers rendering an invoice use this instead of reading the metadata
     * directly, so an absent net amount cannot reach a screen as a blank or a
     * zero.
     */
    public function display(string $field): string
    {
        if ($this->isMissing($field)) {
            return self::MISSING;
        }

        $value = match ($field) {
            'net' => $this->metadata->net,
            'vat' => $this->metadata->vat,
            'gross' => $this->metadata->gross,
            'invoice_date' => $this->metadata->invoiceDate?->format('Y-m-d'),
            'sale_date' => $this->saleDate?->format('Y-m-d'),
            'seller_nip' => $this->metadata->sellerNip,
            'seller_name' => $this->metadata->sellerName,
            'buyer_nip' => $this->metadata->buyerNip,
            'buyer_name' => $this->metadata->buyerName,
            'invoice_number' => $this->metadata->invoiceNumber,
            'currency' => $this->metadata->currency,
            'invoice_type' => $this->metadata->invoiceType,
            default => null,
        };

        if ($value === null) {
            return self::MISSING;
        }

        return $value instanceof \Poland\Domain\Money ? $value->format() : (string) $value;
    }

    /**
     * Every field, rendered safely. Nothing here is ever an empty string or a
     * fabricated zero.
     *
     * @return array<string,string>
     */
    public function displayAll(): array
    {
        $fields = [
            'invoice_number', 'invoice_date', 'sale_date', 'seller_nip', 'seller_name',
            'buyer_nip', 'buyer_name', 'net', 'vat', 'gross', 'currency', 'invoice_type',
        ];

        $out = [];
        foreach ($fields as $field) {
            $out[$field] = $this->display($field);
        }

        return $out;
    }

    public function jsonSerialize(): array
    {
        return [
            'metadata' => $this->metadata,
            'display' => $this->displayAll(),
            'unknown_elements' => $this->unknownElements,
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
