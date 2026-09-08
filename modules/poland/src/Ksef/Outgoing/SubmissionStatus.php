<?php

declare(strict_types=1);

namespace Poland\Ksef\Outgoing;

use DateTimeImmutable;
use Poland\Ksef\KsefNumber;
use Poland\Ksef\Transport\Dto\SessionInvoiceStatus;

/**
 * A KSeF invoice status translated into this system's state, with the
 * translation rules in one place:
 *
 *   100, 150            → PROCESSING
 *   200 + valid number  → ACCEPTED   (200 without a valid KSeF number → MANUAL_REVIEW)
 *   440 duplicate       → MANUAL_REVIEW, carrying the original references
 *   405 410 415 430 435 450 → REJECTED
 *   500, 550, unknown   → MANUAL_REVIEW
 */
final class SubmissionStatus
{
    /**
     * @param list<string> $details
     * @param array<string,string|null> $extensions
     */
    public function __construct(
        public readonly KsefSubmissionState $state,
        public readonly int $code,
        public readonly string $description,
        public readonly array $details,
        public readonly ?string $ksefNumber,
        public readonly ?DateTimeImmutable $acquisitionDate,
        public readonly ?DateTimeImmutable $permanentStorageDate,
        public readonly array $extensions,
        public readonly ?string $manualReviewReason,
        public readonly string $invoiceReference,
        public readonly string $invoiceHash,
        public readonly ?string $upoDownloadUrl,
    ) {
    }

    public static function fromKsef(SessionInvoiceStatus $raw): self
    {
        $code = $raw->statusCode;
        $reason = null;
        $ksefNumber = null;

        if ($code === 100 || $code === 150) {
            $state = KsefSubmissionState::Processing;
        } elseif ($code === 200) {
            if ($raw->ksefNumber !== null && KsefNumber::isValid($raw->ksefNumber)) {
                $state = KsefSubmissionState::Accepted;
                $ksefNumber = $raw->ksefNumber;
            } else {
                $state = KsefSubmissionState::ManualReview;
                $reason = 'KSeF zgłosił status 200 (Sukces), ale nie zwrócił poprawnego numeru KSeF'
                    .($raw->ksefNumber !== null ? ' ('.KsefNumber::reject($raw->ksefNumber).')' : '')
                    .'. Nie można uznać faktury za przyjętą bez numeru.';
            }
        } elseif ($code === 440) {
            $state = KsefSubmissionState::ManualReview;
            $reason = sprintf(
                'KSeF odrzucił dokument jako DUPLIKAT (440). Faktura o tym numerze już istnieje w KSeF: %s (sesja %s). '
                .'Sprawdź, czy to ta sama faktura — jeśli tak, przypisz istniejący numer; jeśli nie, zmień numer faktury.',
                $raw->extensions['originalKsefNumber'] ?? 'numer nieznany',
                $raw->extensions['originalSessionReferenceNumber'] ?? 'nieznana',
            );
        } elseif (in_array($code, [405, 410, 415, 430, 435, 450], true)) {
            $state = KsefSubmissionState::Rejected;
        } else {
            $state = KsefSubmissionState::ManualReview;
            $reason = sprintf('KSeF zwrócił status %d (%s), którego system nie interpretuje automatycznie.', $code, $raw->statusDescription)
                .($code === 550 ? ' KSeF sugeruje ponowną próbę — wymaga decyzji operatora.' : '');
        }

        return new self(
            $state,
            $code,
            $raw->statusDescription,
            $raw->details,
            $ksefNumber,
            $raw->acquisitionDate,
            $raw->permanentStorageDate,
            $raw->extensions,
            $reason,
            $raw->referenceNumber,
            $raw->invoiceHash,
            $raw->upoDownloadUrl,
        );
    }

    public function isFinal(): bool
    {
        return $this->state !== KsefSubmissionState::Processing;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'ksef_code' => $this->code,
            'ksef_description' => $this->description,
            'details' => $this->details,
            'ksef_number' => $this->ksefNumber,
            'acquisition_date' => $this->acquisitionDate?->format(DATE_ATOM),
            'permanent_storage_date' => $this->permanentStorageDate?->format(DATE_ATOM),
            'extensions' => $this->extensions,
            'manual_review_reason' => $this->manualReviewReason,
            'invoice_reference' => $this->invoiceReference,
        ];
    }
}
