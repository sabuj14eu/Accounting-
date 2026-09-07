<?php

declare(strict_types=1);

namespace Shop\Ai;

use Shop\Truth\EvidenceType;
use Shop\Truth\Figure;
use Shop\Truth\Money;

/**
 * A model's proposal, held outside the data until a person accepts it — §22.
 *
 * The suggestion is a first-class record: it is stored, it is shown, it can be
 * rejected with a reason, and it never becomes a Figure by itself. Acceptance
 * requires a named human, a timestamp, AND the evidence type the human is
 * standing behind — because the point of acceptance is that a person looked at
 * the underlying document, and the resulting figure is theirs, not the model's.
 */
final class PendingSuggestion implements \JsonSerializable
{
    public const PENDING = 'PENDING';
    public const ACCEPTED = 'ACCEPTED';
    public const REJECTED = 'REJECTED';

    private string $state = self::PENDING;
    private ?string $decidedBy = null;
    private ?string $decidedAt = null;
    private ?string $decisionReason = null;
    private ?Figure $result = null;

    public function __construct(
        public readonly string $id,
        public readonly AiAction $action,
        public readonly string $subject,
        public readonly Money $amount,
        public readonly float $confidence,
        public readonly string $rationale,
        public readonly string $model,
    ) {
        AiBoundary::assertPermitted($action, "suggestion {$id} on {$subject}");

        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new \InvalidArgumentException('Confidence is a fraction between 0 and 1.');
        }

        if (trim($rationale) === '') {
            throw new \InvalidArgumentException(
                'A suggestion with no stated reasoning cannot be reviewed, only obeyed.'
            );
        }
    }

    public function state(): string
    {
        return $this->state;
    }

    /** Always AI_SUGGESTION while pending — that is the whole point. */
    public function asFigureWhilePending(): Figure
    {
        return new Figure($this->amount, EvidenceType::AI_SUGGESTED, $this->subject, $this->id);
    }

    public function accept(EvidenceType $evidence, string $by, string $at): Figure
    {
        if ($this->state !== self::PENDING) {
            throw new \LogicException("Suggestion {$this->id} has already been {$this->state}.");
        }

        if ($evidence === EvidenceType::AI_SUGGESTED) {
            throw new \InvalidArgumentException(
                'Accepting a suggestion means a person vouched for the evidence behind it. '
                .'"The model said so" is not evidence a person can vouch for.'
            );
        }

        if (trim($by) === '') {
            throw new \InvalidArgumentException('Acceptance must name the person accepting.');
        }

        $this->state = self::ACCEPTED;
        $this->decidedBy = $by;
        $this->decidedAt = $at;
        $this->result = new Figure($this->amount, $evidence, $this->subject, $this->id, $at);

        return $this->result;
    }

    public function reject(string $by, string $at, string $reason): self
    {
        if ($this->state !== self::PENDING) {
            throw new \LogicException("Suggestion {$this->id} has already been {$this->state}.");
        }

        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A rejection records why, so the model can be judged later.');
        }

        $this->state = self::REJECTED;
        $this->decidedBy = $by;
        $this->decidedAt = $at;
        $this->decisionReason = $reason;

        return $this;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action->value,
            'subject' => $this->subject,
            'amount' => $this->amount->grosze,
            'confidence' => $this->confidence,
            'rationale' => $this->rationale,
            'model' => $this->model,
            'state' => $this->state,
            'decided_by' => $this->decidedBy,
            'decided_at' => $this->decidedAt,
            'decision_reason' => $this->decisionReason,
            'result' => $this->result,
        ];
    }
}
