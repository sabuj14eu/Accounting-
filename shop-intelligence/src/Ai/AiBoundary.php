<?php

declare(strict_types=1);

namespace Shop\Ai;

/**
 * The one gate every model-driven code path goes through — §22.
 */
final class AiBoundary
{
    public static function assertPermitted(AiAction $action, string $context = ''): void
    {
        if (! $action->isPermitted()) {
            throw new AiBoundaryViolation($action, $context);
        }
    }

    /** @return list<AiAction> */
    public static function permittedActions(): array
    {
        return array_values(array_filter(AiAction::cases(), static fn (AiAction $a) => $a->isPermitted()));
    }

    /** @return list<AiAction> */
    public static function forbiddenActions(): array
    {
        return array_values(array_filter(AiAction::cases(), static fn (AiAction $a) => ! $a->isPermitted()));
    }
}
