<?php

declare(strict_types=1);

namespace Poland\Ksef;

use DateTimeImmutable;
use Poland\Domain\Money;

/**
 * What KSeF tells us about an invoice before we fetch its XML.
 *
 * Every field is nullable except the KSeF number and the retrieval timestamp,
 * because a metadata listing is not a guarantee of completeness and an absent
 * field must stay absent rather than becoming a zero or an empty string that
 * later reads as fact.
 */
final class KsefInvoiceMetadata implements \JsonSerializable
{
    public function __construct(
        /** The KSeF number — globally unique, and this system's identity for the invoice. */
        public readonly string $ksefNumber,
        /** When THIS system retrieved it. Always known. */
        public readonly DateTimeImmutable $retrievedAt,
        /** Date on the invoice (data wystawienia). */
        public readonly ?DateTimeImmutable $invoiceDate = null,
        /** When KSeF assigned the number and took permanent custody. */
        public readonly ?DateTimeImmutable $permanentStorageDate = null,
        public readonly ?string $invoiceNumber = null,
        public readonly ?string $sellerNip = null,
        public readonly ?string $sellerName = null,
        public readonly ?string $buyerNip = null,
        public readonly ?string $buyerName = null,
        public readonly ?Money $net = null,
        public readonly ?Money $vat = null,
        public readonly ?Money $gross = null,
        public readonly ?string $currency = null,
        /** KSeF's own status string, stored verbatim. */
        public readonly ?string $status = null,
        /** Invoice type as KSeF reports it (VAT, KOR, ZAL, ...). */
        public readonly ?string $invoiceType = null,
        /** @var array<string,mixed> Anything else KSeF sent, kept whole. */
        public readonly array $raw = [],
    ) {
    }

    /** Whether this taxpayer is the BUYER — i.e. it is an incoming cost invoice. */
    public function isIncomingFor(string $taxpayerNip): bool
    {
        return $this->digits($this->buyerNip) === $this->digits($taxpayerNip);
    }

    public function isOutgoingFor(string $taxpayerNip): bool
    {
        return $this->digits($this->sellerNip) === $this->digits($taxpayerNip);
    }

    /** True when a correction invoice — it supersedes something already recorded. */
    public function isCorrection(): bool
    {
        return $this->invoiceType !== null
            && str_contains(strtoupper($this->invoiceType), 'KOR');
    }

    /** Fields KSeF did not give us, so a caller can say so rather than assume. */
    public function missingFields(): array
    {
        $missing = [];
        foreach ([
            'invoice_date' => $this->invoiceDate,
            'seller_nip' => $this->sellerNip,
            'gross' => $this->gross,
            'net' => $this->net,
            'vat' => $this->vat,
        ] as $name => $value) {
            if ($value === null) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    private function digits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $value) ?? '';

        return $digits === '' ? null : $digits;
    }

    public function jsonSerialize(): array
    {
        return [
            'ksef_number' => $this->ksefNumber,
            'invoice_number' => $this->invoiceNumber,
            'invoice_date' => $this->invoiceDate?->format('Y-m-d'),
            'permanent_storage_date' => $this->permanentStorageDate?->format(DATE_ATOM),
            'retrieved_at' => $this->retrievedAt->format(DATE_ATOM),
            'seller_nip' => $this->sellerNip,
            'seller_name' => $this->sellerName,
            'buyer_nip' => $this->buyerNip,
            'buyer_name' => $this->buyerName,
            'net' => $this->net,
            'vat' => $this->vat,
            'gross' => $this->gross,
            'currency' => $this->currency,
            'status' => $this->status,
            'invoice_type' => $this->invoiceType,
            'missing_fields' => $this->missingFields(),
        ];
    }
}
