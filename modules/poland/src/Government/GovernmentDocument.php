<?php

declare(strict_types=1);

namespace Poland\Government;

use DateTimeImmutable;
use Poland\Domain\Money;
use Poland\Interpretation\Suggestion;

/**
 * A letter from an authority, and everything that could be read out of it.
 *
 * Every extracted field is a {@see Suggestion}, not a value: it carries the
 * confidence and the text it was read from. Nothing here becomes an accounting
 * fact without a person accepting it.
 */
final class GovernmentDocument implements \JsonSerializable
{
    /** @param array<string,Suggestion> $extracted */
    public function __construct(
        public readonly string $filename,
        public readonly DateTimeImmutable $receivedAt,
        public readonly GovernmentAuthority $authority,
        public readonly DocumentAction $action,
        public readonly array $extracted = [],
        public readonly ?string $documentType = null,
        public readonly ?DateTimeImmutable $issueDate = null,
        public readonly ?string $caseNumber = null,
        public readonly ?string $taxpayer = null,
        public readonly ?string $taxPeriod = null,
        public readonly ?Money $amount = null,
        public readonly ?DateTimeImmutable $paymentDeadline = null,
        public readonly ?DateTimeImmutable $responseDeadline = null,
        public readonly ?string $requiredAction = null,
        /** True when text could not be read at all. */
        public readonly bool $textUnavailable = false,
        public readonly ?string $extractionNote = null,
    ) {
    }

    /**
     * Whether a person must look at this before anything is done with it.
     *
     * Deliberately broad: unknown classification, unreadable text, or any
     * extracted field below the confidence bar. A letter is cheap to read and
     * expensive to misread.
     */
    public function needsManualReview(): bool
    {
        if ($this->textUnavailable || $this->action === DocumentAction::Unknown) {
            return true;
        }

        foreach ($this->extracted as $suggestion) {
            if (! $suggestion->isConfident()) {
                return true;
            }
        }

        return false;
    }

    /** The nearest deadline of either kind, which is the one that matters. */
    public function nextDeadline(): ?DateTimeImmutable
    {
        $deadlines = array_filter([$this->paymentDeadline, $this->responseDeadline]);
        if ($deadlines === []) {
            return null;
        }

        usort($deadlines, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);

        return $deadlines[0];
    }

    public function daysUntilDeadline(?DateTimeImmutable $now = null): ?int
    {
        $deadline = $this->nextDeadline();
        if ($deadline === null) {
            return null;
        }

        return (int) ($now ?? new DateTimeImmutable())->diff($deadline)->format('%r%a');
    }

    public function jsonSerialize(): array
    {
        return [
            'filename' => $this->filename,
            'received_at' => $this->receivedAt->format(DATE_ATOM),
            'authority' => $this->authority->value,
            'authority_label' => $this->authority->label(),
            'action' => $this->action->value,
            'action_label' => $this->action->label(),
            'action_english' => $this->action->englishLabel(),
            'document_type' => $this->documentType,
            'issue_date' => $this->issueDate?->format('Y-m-d'),
            'case_number' => $this->caseNumber,
            'taxpayer' => $this->taxpayer,
            'tax_period' => $this->taxPeriod,
            'amount' => $this->amount,
            'payment_deadline' => $this->paymentDeadline?->format('Y-m-d'),
            'response_deadline' => $this->responseDeadline?->format('Y-m-d'),
            'required_action' => $this->requiredAction,
            'needs_manual_review' => $this->needsManualReview(),
            'days_until_deadline' => $this->daysUntilDeadline(),
            'text_unavailable' => $this->textUnavailable,
            'extraction_note' => $this->extractionNote,
            'extracted' => $this->extracted,
        ];
    }
}
