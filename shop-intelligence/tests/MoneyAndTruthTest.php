<?php

declare(strict_types=1);

namespace Shop\Tests;

use PHPUnit\Framework\TestCase;
use Shop\Truth\Certainty;
use Shop\Truth\EvidenceType;
use Shop\Truth\Figure;
use Shop\Truth\Money;
use Shop\Truth\Provenance;
use Shop\Truth\ReviewFlag;
use Shop\Truth\Total;

final class MoneyAndTruthTest extends TestCase
{
    /** R09 — a total built from mixed sources takes the WEAKEST provenance, never an average. */
    public function test_r09_provenance_combines_by_worst_case(): void
    {
        $this->assertSame(
            Provenance::USER_DECLARATION,
            Provenance::worst(
                Provenance::OFFICIAL_ACCOUNTING_FACT,
                Provenance::ANALYTICAL_ESTIMATE,
                Provenance::USER_DECLARATION,
            ),
        );

        $this->assertSame(
            Provenance::AI_SUGGESTION,
            Provenance::worst(Provenance::OFFICIAL_ACCOUNTING_FACT, Provenance::AI_SUGGESTION),
        );
    }

    /** R10 — the same rule for how settled a number is. */
    public function test_r10_certainty_combines_by_worst_case(): void
    {
        $this->assertSame(
            Certainty::ESTIMATED,
            Certainty::worst(Certainty::ACTUAL, Certainty::EXPECTED, Certainty::ESTIMATED),
        );

        $this->assertSame(
            Certainty::EXPECTED,
            Certainty::worst(Certainty::ACTUAL, Certainty::EXPECTED),
        );
    }

    /** R11 — §25: ACTUAL and EXPECTED are never added together without the split being shown. */
    public function test_r11_a_mixed_total_cannot_be_described_without_its_split(): void
    {
        $total = Total::of([
            new Figure(Money::parse('4 000,00'), EvidenceType::BANK_CONFIRMED, 'Rent, paid'),
            new Figure(Money::parse('1 200,00'), EvidenceType::RECURRING_SCHEDULE, 'Electricity, due'),
        ]);

        $this->assertTrue($total->isMixed());

        $description = $total->describe();
        $this->assertStringContainsString('5 200,00 zł', $description);
        $this->assertStringContainsString('mixed', $description);
        $this->assertStringContainsString('ACTUAL 4 000,00 zł', $description);
        $this->assertStringContainsString('EXPECTED 1 200,00 zł', $description);
    }

    /** R12 — §25 and the accounting side's own law: absence is not zero. */
    public function test_r12_an_empty_set_of_figures_is_not_actual_zero(): void
    {
        $total = Total::of([]);

        $this->assertTrue($total->amount->isZero());
        $this->assertNotSame(Certainty::ACTUAL, $total->certainty);
        $this->assertStringContainsString('NO DATA', $total->describe());
        $this->assertStringContainsString('not the same as zero', $total->describe());
    }

    /** R13 — §18, §8 and §6 all say it; the type system says it once. */
    public function test_r13_a_review_flag_cannot_accuse_anyone(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/accuses somebody/');

        new ReviewFlag(
            'CASH_SHORT',
            'cash',
            'The 300 zł difference is theft by a member of staff.',
            ['somebody took it'],
        );
    }

    /** R13b — the guard reads Polish too, since that is the language of the shop. */
    public function test_r13_the_accusation_guard_reads_polish(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ReviewFlag(
            'CASH_SHORT',
            'kasa',
            'Różnica 300 zł to kradzież.',
            ['ktoś się pomylił'],
        );
    }

    /** R14 — a finding with no benign reading is an insinuation, not a finding. */
    public function test_r14_a_review_flag_must_offer_innocent_explanations(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no innocent explanation/');

        new ReviewFlag('STOCK_SHORT', 'meat', '3 kg missing.', []);
    }

    public function test_money_refuses_an_amount_it_cannot_read_one_way_only(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::parse('1,234');
    }

    public function test_money_reads_both_polish_and_english_formatting(): void
    {
        $this->assertSame(500000, Money::parse('5 000,00')->grosze);
        $this->assertSame(500000, Money::parse('5,000.00')->grosze);
        $this->assertSame(500000, Money::parse('5000')->grosze);
        $this->assertSame(-200000, Money::parse('-2 000,00')->grosze);
    }

    public function test_a_figure_cannot_exist_without_saying_what_it_is(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Figure(Money::parse(100), EvidenceType::BANK_CONFIRMED, '   ');
    }
}
