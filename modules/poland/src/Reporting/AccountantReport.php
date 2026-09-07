<?php

declare(strict_types=1);

namespace Poland\Reporting;

use Poland\Domain\Money;

/**
 * The monthly accounting report, in the shape an accountant reads.
 *
 * Wraps a MonthlyTaxReport and answers the first question first: what do I have
 * to pay this month? Everything else — the financial result, the derivations,
 * the provenance — comes after that answer, not before it.
 */
final class AccountantReport implements \JsonSerializable
{
    /** @param list<PaymentObligation> $obligations */
    public function __construct(
        public readonly MonthlyTaxReport $report,
        public readonly array $obligations,
        public readonly FinancialResult $financials,
        public readonly ?int $version = null,
        public readonly ?\DateTimeImmutable $generatedAt = null,
        public readonly bool $isClosed = false,
    ) {
    }

    public function title(): string
    {
        return 'Raport księgowy — '.$this->report->period->label();
    }

    public function obligation(ObligationKind $kind): ?PaymentObligation
    {
        foreach ($this->obligations as $obligation) {
            if ($obligation->kind === $kind) {
                return $obligation;
            }
        }

        return null;
    }

    /** Sum of what still has to be paid — excluding what is already paid. */
    public function totalOutstanding(): Money
    {
        return Money::sum(array_map(
            static fn (PaymentObligation $o): Money => $o->status->requiresAction()
                ? $o->amount
                : Money::zero(),
            $this->obligations,
        ));
    }

    public function totalDue(): Money
    {
        return Money::sum(array_map(
            static fn (PaymentObligation $o): Money => $o->amount,
            $this->obligations,
        ));
    }

    public function everythingPaid(): bool
    {
        foreach ($this->obligations as $obligation) {
            if ($obligation->status->requiresAction()) {
                return false;
            }
        }

        return true;
    }

    /** @return list<PaymentObligation> */
    public function outstanding(): array
    {
        return array_values(array_filter(
            $this->obligations,
            static fn (PaymentObligation $o): bool => $o->status->requiresAction(),
        ));
    }

    /**
     * The banner. Present on every surface — screen, print, PDF — whenever the
     * rates behind the figures have not been confirmed against the issuing
     * authority.
     */
    public function verificationBanner(): ?string
    {
        if ($this->report->ratesFitForFiling) {
            return null;
        }

        return 'NIEZWERYFIKOWANE — NIE UŻYWAĆ DO RZECZYWISTEJ ZAPŁATY PODATKU '
            .'(NOT VERIFIED — DO NOT USE FOR REAL TAX PAYMENT)';
    }

    public function jsonSerialize(): array
    {
        return [
            'title' => $this->title(),
            'version' => $this->version,
            'generated_at' => $this->generatedAt?->format(DATE_ATOM),
            'is_closed' => $this->isClosed,
            'verification_banner' => $this->verificationBanner(),
            'summary' => [
                'total_due' => $this->totalDue(),
                'total_outstanding' => $this->totalOutstanding(),
                'everything_paid' => $this->everythingPaid(),
            ],
            'obligations' => $this->obligations,
            'financials' => $this->financials,
            'report' => $this->report,
        ];
    }
}
