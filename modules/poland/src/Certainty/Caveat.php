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
            // BLOCKED: a production dependency is not connected. Distinct from
            // "no invoices this month", which is what a silent empty result
            // would look like.
            DataCertainty::Blocked,
            'Synchronizacja z KSeF niedostępna — faktury zakupowe mogą być niekompletne.'
            .($reason !== null ? ' Powód: '.$reason : ''),
            'Sprawdź konfigurację i token KSeF, potem uruchom synchronizację ponownie.',
        );
    }

    public static function ratesUnverified(string $tables): self
    {
        return new self(
            'rates_unverified',
            // BLOCKED, not NOT ENOUGH DATA: the taxpayer cannot fix this by
            // uploading anything. It needs somebody to read the ZUS announcement.
            DataCertainty::Blocked,
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

    /** An operation ran and broke — there is an error to diagnose and a retry. */
    public static function operationFailed(string $operation, string $error): self
    {
        return new self(
            'operation_failed',
            DataCertainty::Failed,
            sprintf('Operacja "%s" zakończyła się błędem: %s', $operation, $error),
            'Sprawdź logi i uruchom ponownie. To nie jest brak danych — coś się wykonało i nie zadziałało.',
        );
    }

    /** A production integration is deliberately off. */
    public static function integrationDisabled(string $integration): self
    {
        return new self(
            'integration_disabled',
            DataCertainty::Blocked,
            sprintf(
                'Integracja "%s" jest wyłączona. To NIE znaczy, że nie ma dokumentów — '
                .'znaczy, że system ich nie widzi.',
                $integration,
            ),
            'Włącz i skonfiguruj integrację, albo wprowadź dokumenty ręcznie.',
        );
    }

    /**
     * The statement covers only part of the month.
     *
     * "3 transactions imported" does not mean "all 3 transactions for August",
     * and treating a part-month statement as the whole month understates costs
     * exactly as silently as importing none at all.
     */
    public static function bankStatementIncomplete(string $period, string $covered): self
    {
        return new self(
            'bank_statement_incomplete',
            DataCertainty::NotEnoughData,
            sprintf(
                'DANE BANKOWE NIEKOMPLETNE: wyciąg obejmuje tylko część okresu %s (%s). '
                .'Liczba zaimportowanych transakcji nie oznacza kompletu transakcji miesiąca.',
                $period,
                $covered,
            ),
            'Wgraj wyciąg obejmujący cały miesiąc.',
        );
    }

    /** Opening and closing balances do not add up — rows are probably missing. */
    public static function bankStatementDidNotBalance(int $count): self
    {
        return new self(
            'bank_statement_requires_review',
            DataCertainty::RequiresReview,
            sprintf(
                'WYCIĄG WYMAGA PRZEGLĄDU: %d wyciągów nie zgadza się z saldem otwarcia '
                .'i zamknięcia — prawdopodobnie brakuje transakcji.',
                $count,
            ),
            'Pobierz wyciąg ponownie z bankowości i zaimportuj.',
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
