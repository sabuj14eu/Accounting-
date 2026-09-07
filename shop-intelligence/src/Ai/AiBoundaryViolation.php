<?php

declare(strict_types=1);

namespace Shop\Ai;

/**
 * Thrown when something asks a model to act rather than to advise.
 *
 * A distinct exception class so it can never be swallowed by a generic catch
 * that was written for a parsing error.
 */
final class AiBoundaryViolation extends \DomainException
{
    public function __construct(public readonly AiAction $action, string $context = '')
    {
        parent::__construct(
            'AI boundary (§22): '.$action->value.' is not permitted. '
            .$action->refusalReason()
            .($context === '' ? '' : ' Context: '.$context)
        );
    }
}
