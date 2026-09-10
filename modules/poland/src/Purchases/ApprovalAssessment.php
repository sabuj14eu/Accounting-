<?php

declare(strict_types=1);

namespace Poland\Purchases;

/** The gate's answer: may this invoice be approved, and if not, exactly why. */
final class ApprovalAssessment implements \JsonSerializable
{
    /**
     * @param list<string> $blockers each one names what clears it
     * @param list<string> $notes things worth knowing that do not block
     */
    public function __construct(
        public readonly array $blockers,
        public readonly array $notes,
    ) {
    }

    public function canApprove(): bool
    {
        return $this->blockers === [];
    }

    public function jsonSerialize(): array
    {
        return [
            'can_approve' => $this->canApprove(),
            'blockers' => $this->blockers,
            'notes' => $this->notes,
        ];
    }
}
