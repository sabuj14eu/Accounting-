<?php

declare(strict_types=1);

namespace Poland\Rates;

use Poland\Domain\Period;
use RuntimeException;

/**
 * Thrown when a settlement is required to use officially-verified rates and
 * one of the tables it needs is not.
 *
 * Production installations turn that requirement on. It is the mechanism that
 * stops a figure sourced from the trade press from being handed to somebody as
 * an amount to pay, without anybody having to remember to check.
 */
final class UnverifiedRateException extends RuntimeException
{
    /** @param array<string,RateProvenance> $offending keyed by table name */
    public function __construct(
        public readonly Period $period,
        public readonly array $offending,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** @param array<string,RateProvenance> $offending */
    public static function for(Period $period, array $offending): self
    {
        $lines = [];
        foreach ($offending as $table => $provenance) {
            $lines[] = sprintf(
                '  - %s: %s (%s)',
                $table,
                $provenance->status->label(),
                $provenance->sourceDocument,
            );
        }

        return new self($period, $offending, sprintf(
            "Rozliczenie %s wymaga stawek zweryfikowanych w źródłach urzędowych, "
            ."a następujące tabele ich nie mają:\n%s\n"
            ."Zweryfikuj je (php artisan poland:rate-provenance --todo), ustaw status "
            ."\"official\" wraz z adresem publikacji, albo wyłącz wymóg "
            ."(poland.require_official_rates) — świadomie i tylko poza produkcją.",
            $period->toString(),
            implode("\n", $lines),
        ));
    }
}
