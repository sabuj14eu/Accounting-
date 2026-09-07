<?php

declare(strict_types=1);

namespace Poland\Certainty;

/**
 * What each external integration is actually doing, phrased so it can never be
 * mistaken for a statement about the taxpayer's records.
 *
 * The audit's §22: unavailable production integrations must be labelled, not
 * presented as working. The wording matters as much as the flag — "NOT
 * CONNECTED" is about the system; "no invoices found" is about the month.
 */
final class IntegrationStatus implements \JsonSerializable
{
    public function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly bool $operational,
        public readonly string $detail,
        public readonly ?string $nextStep = null,
    ) {
    }

    public static function ksef(bool $transportEnabled, string $transportKind, ?string $reason = null): self
    {
        if (! $transportEnabled || $transportKind === 'disabled') {
            return new self(
                'KSeF',
                'NOT CONNECTED',
                false,
                'Transport HTTP do KSeF nie jest włączony. System NIE pobiera faktur zakupowych. '
                .'To nie znaczy, że faktur nie ma — znaczy, że ich nie widzimy.',
                'Zaimplementuj transport wg aktualnej oficjalnej specyfikacji i włącz KSEF_TRANSPORT_ENABLED.',
            );
        }

        if ($transportKind === 'fake') {
            return new self(
                'KSeF',
                'TEST TRANSPORT',
                false,
                'Używana jest ATRAPA transportu. Dane nie pochodzą z KSeF i nie mogą być '
                .'podstawą rozliczenia.',
                'Przełącz na transport produkcyjny przed rozliczeniem.',
            );
        }

        if ($reason !== null) {
            return new self('KSeF', 'UNAVAILABLE', false, 'Synchronizacja niedostępna: '.$reason, null);
        }

        return new self('KSeF', 'CONNECTED', true, 'Pobieranie faktur zakupowych działa (InvoiceRead).');
    }

    public static function ocr(bool $available): self
    {
        return $available
            ? new self('OCR / odczyt tekstu', 'AVAILABLE', true, 'Dokumenty PDF są odczytywane.')
            : new self(
                'OCR / odczyt tekstu',
                'NOT AVAILABLE',
                false,
                'Brak mechanizmu odczytu tekstu. Pisma PDF są przechowywane i oznaczane '
                .'DO PRZEGLĄDU RĘCZNEGO — nie są klasyfikowane automatycznie.',
                'Zainstaluj pdftotext/OCR i zaimplementuj TextExtractor.',
            );
    }

    public static function governmentSubmission(): self
    {
        return new self(
            'Wysyłka do organów (KSeF / JPK / pisma)',
            'DISABLED',
            false,
            'System niczego nie wysyła do ZUS, urzędu skarbowego ani KSeF. '
            .'Każdy kanał wysyłki zgłasza błąd zamiast wysyłać.',
            'Pozostaje wyłączone do czasu osobnego, kontrolowanego wdrożenia.',
        );
    }

    public static function rates(bool $verified, string $unverifiedTables = ''): self
    {
        return $verified
            ? new self('Stawki podatkowe', 'VERIFIED', true, 'Stawki potwierdzone w źródłach urzędowych.')
            : new self(
                'Stawki podatkowe',
                'NOT VERIFIED',
                false,
                'Stawki pochodzą ze źródeł wtórnych i nie zostały potwierdzone w publikacji '
                .'organu'.($unverifiedTables !== '' ? ' ('.$unverifiedTables.')' : '').'. '
                .'Wyniki nadają się do orientacji, NIE do zapłaty podatku.',
                'php artisan poland:rate-provenance --todo',
            );
    }

    public static function bankImport(): self
    {
        return new self(
            'Import wyciągów bankowych',
            'AVAILABLE',
            true,
            'CSV, MT940 i camt.053 są odczytywane i sprawdzane saldem. PDF nie jest '
            .'źródłem transakcji.',
        );
    }

    /**
     * The whole panel.
     *
     * @return list<self>
     */
    public static function panel(
        bool $ksefTransportEnabled,
        string $ksefTransportKind,
        bool $ocrAvailable,
        bool $ratesVerified,
        string $unverifiedTables = '',
        ?string $ksefReason = null,
    ): array {
        return [
            self::ksef($ksefTransportEnabled, $ksefTransportKind, $ksefReason),
            self::bankImport(),
            self::ocr($ocrAvailable),
            self::rates($ratesVerified, $unverifiedTables),
            self::governmentSubmission(),
        ];
    }

    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status,
            'operational' => $this->operational,
            'detail' => $this->detail,
            'next_step' => $this->nextStep,
        ];
    }
}
