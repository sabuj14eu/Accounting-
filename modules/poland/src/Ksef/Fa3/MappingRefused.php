<?php

declare(strict_types=1);

namespace Poland\Ksef\Fa3;

/** The snapshot cannot become a lawful FA(3); every reason is listed. The invoice is BLOCKED, not guessed. */
final class MappingRefused extends \RuntimeException
{
    /** @param list<string> $reasons */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct('Faktura nie może zostać przygotowana do KSeF: '.implode(' | ', $reasons));
    }
}
