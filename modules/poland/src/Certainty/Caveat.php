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
    /**
     * Canonical reason codes.
     *
     * Stable identifiers, not display strings: an interface, an alert rule and
     * a support conversation all need to name the same condition the same way,
     * and a translated message cannot do that.
     */
    public const OFFICIAL_RATES_NOT_VERIFIED = 'OFFICIAL_RATES_NOT_VERIFIED';

    public const KSEF_TRANSPORT_UNAVAILABLE = 'KSEF_TRANSPORT_UNAVAILABLE';

    public const BANK_STATEMENT_MISSING = 'BANK_STATEMENT_MISSING';

    public const BANK_STATEMENT_INCOMPLETE = 'BANK_STATEMENT_INCOMPLETE';

    public const BANK_STATEMENT_DID_NOT_BALANCE = 'BANK_STATEMENT_DID_NOT_BALANCE';

    public const POSSIBLE_DUPLICATE_TRANSACTION = 'POSSIBLE_DUPLICATE_TRANSACTION';

    public const UNMATCHED_TRANSACTIONS = 'UNMATCHED_TRANSACTIONS';

    public const INTERPRETATION_INCONCLUSIVE = 'INTERPRETATION_INCONCLUSIVE';

    public const DOCUMENT_ENGINE_DISAGREEMENT = 'DOCUMENT_ENGINE_DISAGREEMENT';

    public const NEW_DOCUMENTS_AFTER_REPORT = 'NEW_DOCUMENTS_AFTER_REPORT';

    public const OPERATION_FAILED = 'OPERATION_FAILED';

    public const INTEGRATION_DISABLED = 'INTEGRATION_DISABLED';

    public function __construct(
        public readonly string $code,
        public readonly DataCertainty $certainty,
        public readonly string $message,
        public readonly ?string $remedy = null,
        /** Who can clear this. Never null — "somebody" is not an answer. */
        public readonly ResolvedBy $resolvedBy = ResolvedBy::Operator,
    ) {
    }

    public static function bankStatementMissing(string $period): self
    {
        return new self(
            self::BANK_STATEMENT_MISSING,
            DataCertainty::NotEnoughData,
            'Brak wyciągu bankowego za '.$period.' — koszty mogą być niekompletne, '
            .'a wyniku finansowego nie można uznać za ostateczny.',
            'Wgraj wyciąg bankowy za ten okres.',
            ResolvedBy::User,
        );
    }

    public static function ksefUnavailable(?string $reason = null): self
    {
        return new self(
            self::KSEF_TRANSPORT_UNAVAILABLE,
            // BLOCKED: a production dependency is not connected. Distinct from
            // "no invoices this month", which is what a silent empty result
            // would look like.
            DataCertainty::Blocked,
            'Synchronizacja z KSeF niedostępna — faktury zakupowe mogą być niekompletne.'
            .($reason !== null ? ' Powód: '.$reason : ''),
            'Sprawdź konfigurację i token KSeF, potem uruchom synchronizację ponownie.',
            ResolvedBy::Operator,
        );
    }

    public static function ratesUnverified(string $tables): self
    {
        return new self(
            self::OFFICIAL_RATES_NOT_VERIFIED,
            // BLOCKED, not NOT ENOUGH DATA: the taxpayer cannot fix this by
            // uploading anything. It needs somebody to read the ZUS announcement.
            DataCertainty::Blocked,
            'Wyliczenie podatku zablokowane — wymagana weryfikacja stawek w źródłach '
            .'urzędowych ('.$tables.').',
            'php artisan poland:rate-provenance --todo',
            ResolvedBy::Accountant,
        );
    }

    public static function interpretationInconclusive(string $subject): self
    {
        return new self(
            self::INTERPRETATION_INCONCLUSIVE,
            DataCertainty::RequiresReview,
            'Nie udało się jednoznacznie ustalić wymaganego działania dla: '.$subject
            .' — wymagany przegląd ręczny.',
            'Otwórz dokument i zdecyduj ręcznie.',
            ResolvedBy::User,
        );
    }

    public static function newDocumentAfterReport(string $period, int $count): self
    {
        return new self(
            self::NEW_DOCUMENTS_AFTER_REPORT,
            DataCertainty::RequiresReview,
            sprintf(
                'Po wygenerowaniu raportu za %s pojawiło się %d nowych dokumentów. '
                .'Raport NIE został zmieniony — wymaga przeglądu i ponownego wygenerowania.',
                $period,
                $count,
            ),
            'Przejrzyj nowe dokumenty i wygeneruj nową wersję raportu.',
            ResolvedBy::User,
        );
    }

    /** An operation ran and broke — there is an error to diagnose and a retry. */
    public static function operationFailed(string $operation, string $error): self
    {
        return new self(
            self::OPERATION_FAILED,
            DataCertainty::Failed,
            sprintf('Operacja "%s" zakończyła się błędem: %s', $operation, $error),
            'Sprawdź logi i uruchom ponownie. To nie jest brak danych — coś się wykonało i nie zadziałało.',
            ResolvedBy::Operator,
        );
    }

    /** A production integration is deliberately off. */
    public static function integrationDisabled(string $integration): self
    {
        return new self(
            self::INTEGRATION_DISABLED,
            DataCertainty::Blocked,
            sprintf(
                'Integracja "%s" jest wyłączona. To NIE znaczy, że nie ma dokumentów — '
                .'znaczy, że system ich nie widzi.',
                $integration,
            ),
            'Włącz i skonfiguruj integrację, albo wprowadź dokumenty ręcznie.',
            ResolvedBy::Operator,
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
            self::BANK_STATEMENT_INCOMPLETE,
            DataCertainty::NotEnoughData,
            sprintf(
                'DANE BANKOWE NIEKOMPLETNE: wyciąg obejmuje tylko część okresu %s (%s). '
                .'Liczba zaimportowanych transakcji nie oznacza kompletu transakcji miesiąca.',
                $period,
                $covered,
            ),
            'Wgraj wyciąg obejmujący cały miesiąc.',
            ResolvedBy::User,
        );
    }

    /** Opening and closing balances do not add up — rows are probably missing. */
    public static function bankStatementDidNotBalance(int $count): self
    {
        return new self(
            self::BANK_STATEMENT_DID_NOT_BALANCE,
            DataCertainty::RequiresReview,
            sprintf(
                'WYCIĄG WYMAGA PRZEGLĄDU: %d wyciągów nie zgadza się z saldem otwarcia '
                .'i zamknięcia — prawdopodobnie brakuje transakcji.',
                $count,
            ),
            'Pobierz wyciąg ponownie z bankowości i zaimportuj.',
            ResolvedBy::User,
        );
    }

    public static function unmatchedTransactions(int $count): self
    {
        return new self(
            self::UNMATCHED_TRANSACTIONS,
            DataCertainty::RequiresReview,
            sprintf('%d transakcji bankowych nie zostało dopasowanych do dokumentów.', $count),
            'Przejrzyj listę NEEDS REVIEW i zaakceptuj lub odrzuć propozycje.',
            ResolvedBy::User,
        );
    }

    public static function engineDisagreesWithInterpretation(string $subject): self
    {
        return new self(
            self::DOCUMENT_ENGINE_DISAGREEMENT,
            DataCertainty::RequiresReview,
            'Wynik silnika księgowego różni się od interpretacji dokumentu ('.$subject.'). '
            .'Źródłem prawdy jest silnik — rozbieżność wymaga decyzji człowieka.',
            'Porównaj wyliczenie z dokumentem i rozstrzygnij ręcznie.',
            ResolvedBy::User,
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
            'resolved_by' => $this->resolvedBy->value,
            'resolved_by_label' => $this->resolvedBy->englishLabel(),
        ];
    }
}
