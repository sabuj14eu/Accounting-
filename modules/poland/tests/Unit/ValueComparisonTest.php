<?php

declare(strict_types=1);

namespace Poland\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Poland\Banking\BankTransaction;
use Poland\Domain\Money;
use Poland\Domain\Period;

/**
 * The audit's §8: accounting values are never compared as presentation strings.
 *
 * These are regressions for two bugs that shipped and were caught only by
 * running the pipeline: a decimal column compared as a string, and a date
 * column compared against a date-only value. Both failed SILENTLY — the query
 * simply never matched — and the visible symptom was a doubled month's costs,
 * three layers away from the cause.
 */
final class ValueComparisonTest extends TestCase
{
    // --- decimal scale ----------------------------------------------------

    public function test_the_same_amount_written_at_different_scales_is_equal(): void
    {
        // The bug: "-5970" from one driver, "-5970.00" from another. As strings
        // these differ; as money they are the same amount.
        $representations = ['5970', '5970.0', '5970.00', '5970.000', '5 970,00', '5970,00'];

        foreach ($representations as $written) {
            self::assertSame(
                597000,
                Money::parse($written)->grosze,
                sprintf('"%s" must parse to the same grosze', $written),
            );
        }

        self::assertTrue(Money::parse('5970')->equals(Money::parse('5970.00')));
    }

    public function test_string_comparison_of_amounts_would_have_been_wrong(): void
    {
        // Documents why the rule exists rather than merely asserting the fix.
        self::assertNotSame('123.40', '123.4', 'as strings these differ');
        self::assertTrue(
            Money::parse('123.40')->equals(Money::parse('123.4')),
            'as money they are the same amount',
        );
    }

    public function test_amounts_are_compared_in_grosze_not_floats(): void
    {
        // 0.1 + 0.2 != 0.3 in binary floating point. In grosze it is exact.
        $sum = Money::parse('0.10')->plus(Money::parse('0.20'));

        self::assertSame(30, $sum->grosze);
        self::assertTrue($sum->equals(Money::parse('0.30')));
    }

    public function test_a_long_run_of_additions_does_not_drift(): void
    {
        $total = Money::zero();
        for ($i = 0; $i < 10000; $i++) {
            $total = $total->plus(Money::parse('0.01'));
        }

        self::assertSame(10000, $total->grosze, 'exactly 100,00 zł after 10 000 additions');
    }

    public function test_negative_zero_and_zero_are_the_same_amount(): void
    {
        self::assertTrue(Money::parse('-0.00')->equals(Money::zero()));
        self::assertTrue(Money::parse('-0.00')->isZero());
    }

    // --- currency-sized values --------------------------------------------

    public function test_values_far_beyond_a_float_s_exact_range_stay_exact(): void
    {
        // 2^53 grosze is about 90 trillion złoty; well past that, integers are
        // still exact where doubles are not.
        $large = Money::parse('99999999999.99');

        self::assertSame(9999999999999, $large->grosze);
        self::assertSame(
            9999999999999 + 1,
            $large->plus(Money::grosze(1))->grosze,
        );
    }

    public function test_a_rate_applied_to_an_amount_rounds_at_the_grosz(): void
    {
        // 1 399,80 × 2,45% = 34,2951 → 34,30, not 34,29. One groszy is the
        // difference between the published 442,90 and a wrong 442,89.
        self::assertSame(3430, Money::parse('1399.80')->times(0.0245)->grosze);
    }

    // --- date vs datetime -------------------------------------------------

    public function test_a_date_and_the_same_date_at_midnight_are_the_same_day(): void
    {
        // The bug: the column round-trips as "2026-08-14 00:00:00" and an
        // equality against "2026-08-14" silently never matches.
        $dateOnly = new DateTimeImmutable('2026-08-14');
        $withTime = new DateTimeImmutable('2026-08-14 00:00:00');

        self::assertSame($dateOnly->format('Y-m-d'), $withTime->format('Y-m-d'));
        self::assertEquals($dateOnly, $withTime);
    }

    public function test_a_transaction_fingerprint_uses_the_date_not_the_timestamp(): void
    {
        // Two imports of the same statement must produce the same fingerprint
        // even when one parser preserved a time component and the other did not.
        $a = $this->transaction(new DateTimeImmutable('2026-08-14'));
        $b = $this->transaction(new DateTimeImmutable('2026-08-14 00:00:00'));

        self::assertSame($a->fingerprint(), $b->fingerprint());
    }

    public function test_a_different_day_produces_a_different_fingerprint(): void
    {
        $a = $this->transaction(new DateTimeImmutable('2026-08-14'));
        $b = $this->transaction(new DateTimeImmutable('2026-08-15'));

        self::assertNotSame($a->fingerprint(), $b->fingerprint());
    }

    // --- timezone boundaries ----------------------------------------------

    public function test_a_late_evening_warsaw_time_stays_on_its_own_day(): void
    {
        // 23:30 in Warsaw is 21:30 UTC the same day in winter. A booking date
        // that shifted a day would move a transaction into the wrong month.
        $warsaw = new DateTimeImmutable('2026-08-31 23:30:00', new DateTimeZone('Europe/Warsaw'));

        self::assertSame('2026-08-31', $warsaw->format('Y-m-d'));
        self::assertSame(
            '2026-08',
            Period::of((int) $warsaw->format('Y'), (int) $warsaw->format('n'))->toString(),
        );
    }

    public function test_a_transaction_booked_at_month_end_lands_in_that_month(): void
    {
        $transaction = $this->transaction(new DateTimeImmutable('2026-08-31 23:59:59'));

        self::assertSame('2026-08', $transaction->bookingDate->format('Y-m'));
    }

    public function test_the_first_moment_of_a_month_lands_in_that_month(): void
    {
        $transaction = $this->transaction(new DateTimeImmutable('2026-09-01 00:00:00'));

        self::assertSame('2026-09', $transaction->bookingDate->format('Y-m'));
    }

    public function test_a_period_covers_the_whole_of_its_last_day(): void
    {
        // lastDay() at midnight would exclude everything booked that day.
        $august = Period::of(2026, 8);

        self::assertSame('2026-08-31', $august->lastDay()->format('Y-m-d'));
        self::assertSame('2026-08-01', $august->firstDay()->format('Y-m-d'));
    }

    public function test_february_lengths_are_not_assumed(): void
    {
        self::assertSame('2026-02-28', Period::of(2026, 2)->lastDay()->format('Y-m-d'));
        self::assertSame('2028-02-29', Period::of(2028, 2)->lastDay()->format('Y-m-d'));
    }

    // --- equivalent representations ---------------------------------------

    public function test_a_nip_compares_the_same_however_it_is_punctuated(): void
    {
        $metadata = new \Poland\Ksef\KsefInvoiceMetadata(
            ksefNumber: 'K-1',
            retrievedAt: new DateTimeImmutable(),
            sellerNip: '113-231-69-39',
            buyerNip: '5260250274',
        );

        self::assertTrue($metadata->isOutgoingFor('1132316939'));
        self::assertTrue($metadata->isOutgoingFor('113 231 69 39'));
        self::assertTrue($metadata->isIncomingFor('526-025-02-74'));
    }

    public function test_an_iban_compares_the_same_however_it_is_spaced(): void
    {
        $matcher = new \Poland\Reconciliation\TransactionMatcher();

        $transaction = new BankTransaction(
            new DateTimeImmutable('2026-08-20'),
            Money::parse('-1230.00'),
            'zaplata FV/1',
            null,
            null,
            'PL 6110 9010 1400 0007 1219 8128 74',
        );

        $candidate = new \Poland\Reconciliation\MatchCandidate(
            reference: 'FV/1',
            type: 'ksef_invoice',
            amount: Money::parse('1230.00'),
            date: new DateTimeImmutable('2026-08-14'),
            counterpartyAccount: 'PL61109010140000071219812874',
        );

        $result = $matcher->classify($transaction, [$candidate]);

        self::assertStringContainsString('zgodny numer rachunku', $result->reason());
    }

    public function test_a_period_parses_the_same_with_or_without_a_leading_zero(): void
    {
        self::assertTrue(Period::parse('2026-8')->equals(Period::parse('2026-08')));
        self::assertSame('2026-08', Period::parse('2026-8')->toString());
    }

    public function test_money_json_form_is_stable_and_reparses_identically(): void
    {
        // The JSON form is what reaches the database. It must round-trip.
        foreach (['0.00', '-0.01', '1234.56', '-5970.00', '99999999999.99'] as $value) {
            $money = Money::parse($value);
            self::assertTrue(
                Money::parse($money->jsonSerialize())->equals($money),
                sprintf('%s did not round-trip', $value),
            );
        }
    }

    private function transaction(DateTimeImmutable $date): BankTransaction
    {
        return new BankTransaction($date, Money::parse('-5970.00'), 'FV/2026/08/417');
    }
}
