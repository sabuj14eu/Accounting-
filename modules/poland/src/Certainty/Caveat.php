<?php

declare(strict_types=1);

namespace Poland\Certainty;

/**
 * A named reason a figure is less certain than it looks.
 *
 * The wording is fixed here rather than written at each call site, so the same
 * missing source always reads the same way and cannot be softened by accident
 * in one screen and not another.
 */
final class Caveat implements \JsonSerializable
{
    public function __construct(
        public readonly string $code,
        public readonly DataCertainty $certainty,
        public readonly string $message,
        public readonly ?string $remedy = null,
    ) {
    }

    public static function bankStatementMissing(string $period): self
    {
        return new self(
            'bank_statement_missing',
            DataCertainty::NotEnoughData,
            'Brak wyciągu bankowego za '.$period.' — koszty mogą być niekompletne, '
            .'a wyniku finansowego nie można uznać za ostateczny.',
            'Wgraj wyciąg bankowy za ten okres.',
        );
    }

    public static function ksefUnavailable(?string $reason = null): self
    {
        return new self(
            'ksef_unavailable',
            DataCertainty::NotEnoughData,
            'Synchronizacja z KSeF niedostępna — faktury zakupowe mogą być niekompletne.'
            .($reason !== null ? ' Powód: '.$reason : ''),
            'Sprawdź konfigurację i token KSeF, potem uruchom synchronizację ponownie.',
        );
    }

    public static function ratesUnverified(string $tables): self
    {
        return new self(
            'rates_unverified',
            DataCertainty::NotEnoughData,
            'Wyliczenie podatku zablokowane — wymagana weryfikacja stawek w źródłach '
            .'urzędowych ('.$tables.').',
            'php artisan poland:rate-provenance --todo',
        );
    }

    public static function interpretationInconclusive(string $subject): self
    {
        return new self(
            'interpretation_inconclusive',
            DataCertainty::RequiresReview,
            'Nie udało się jednoznacznie ustalić wymaganego działania dla: '.$subject
            .' — wymagany przegląd ręczny.',
            'Otwórz dokument i zdecyduj ręcznie.',
        );
    }

    public static function newDocumentAfterReport(string $period, int $count): self
    {
        return new self(
            'new_documents_after_report',
            DataCertainty::RequiresReview,
            sprintf(
                'Po wygenerowaniu raportu za %s pojawiło się %d nowych dokumentów. '
                .'Raport NIE został zmieniony — wymaga przeglądu i ponownego wygenerowania.',
                $period,
                $count,
            ),
            'Przejrzyj nowe dokumenty i wygeneruj nową wersję raportu.',
        );
    }

    public static function unmatchedTransactions(int $count): self
    {
        return new self(
            'unmatched_transactions',
            DataCertainty::RequiresReview,
            sprintf('%d transakcji bankowych nie zostało dopasowanych do dokumentów.', $count),
            'Przejrzyj listę NEEDS REVIEW i zaakceptuj lub odrzuć propozycje.',
        );
    }

    public static function engineDisagreesWithInterpretation(string $subject): self
    {
        return new self(
            'engine_interpretation_conflict',
            DataCertainty::RequiresReview,
            'Wynik silnika księgowego różni się od interpretacji dokumentu ('.$subject.'). '
            .'Źródłem prawdy jest silnik — rozbieżność wymaga decyzji człowieka.',
            'Porównaj wyliczenie z dokumentem i rozstrzygnij ręcznie.',
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'code' => $this->code,
            'certainty' => $this->certainty->value,
            'certainty_label' => $this->certainty->label(),
            'message' => $this->message,
            'remedy' => $this->remedy,
        ];
    }
}
