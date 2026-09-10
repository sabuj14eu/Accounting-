<?php

declare(strict_types=1);

namespace Poland\Ksef\Parsing;

use DateTimeImmutable;

/**
 * What a correction invoice (KOR) says about the document it corrects:
 * `DaneFaKorygowanej`, `PrzyczynaKorekty`, `TypKorekty`.
 *
 * The KSeF number of the original is the strong link; the invoice number plus
 * the seller's NIP is the fallback. Neither present → the correction cannot be
 * attached to anything automatically and a person must pick the original or
 * confirm there is none in this system.
 */
final class CorrectionReference implements \JsonSerializable
{
    public function __construct(
        public readonly ?string $originalKsefNumber,
        public readonly ?string $originalInvoiceNumber,
        public readonly ?DateTimeImmutable $originalInvoiceDate,
        public readonly ?string $reason,
        /** FA `TypKorekty`: 1 = original period, 2 = correction period, 3 = other — kept verbatim. */
        public readonly ?string $type,
    ) {
    }

    public function canLinkAutomatically(): bool
    {
        return $this->originalKsefNumber !== null || $this->originalInvoiceNumber !== null;
    }

    public function jsonSerialize(): array
    {
        return [
            'original_ksef_number' => $this->originalKsefNumber,
            'original_invoice_number' => $this->originalInvoiceNumber,
            'original_invoice_date' => $this->originalInvoiceDate?->format('Y-m-d'),
            'reason' => $this->reason,
            'type' => $this->type,
            'can_link_automatically' => $this->canLinkAutomatically(),
        ];
    }
}
