<?php

declare(strict_types=1);

namespace Poland\Purchases;

use Poland\Domain\Period;
use Poland\Domain\PurchaseRegister;

/**
 * Which purchase register a month uses, and why.
 *
 * Two sources can describe the same month: the sum of APPROVED postings, and
 * the manual monthly total the owner typed before postings existed. They are
 * never added together. Both present is a CONFLICT the owner resolves by
 * superseding the manual figure; until then the month has NO register, which
 * the engine reports as an upper bound rather than silently picking a side.
 */
final class RegisterResolution implements \JsonSerializable
{
    public const POSTINGS = 'postings';

    public const MANUAL = 'manual';

    public const NONE = 'none';

    public const CONFLICT = 'conflict';

    private function __construct(
        public readonly Period $period,
        public readonly string $source,
        public readonly ?PurchaseRegister $register,
        public readonly string $reason,
    ) {
    }

    public static function resolve(Period $period, ?PurchaseRegister $fromPostings, ?PurchaseRegister $manual): self
    {
        if ($fromPostings !== null && $manual !== null) {
            return new self($period, self::CONFLICT, null, sprintf(
                'Miesiąc %s ma DWA źródła rejestru zakupów: %d zaksięgowanych faktur (netto %s, VAT %s) '
                .'oraz ręcznie wpisaną sumę (netto %s, VAT %s). Nie są sumowane. Oznacz ręczną sumę '
                .'jako zastąpioną albo cofnij księgowania — do tego czasu miesiąc liczy się BEZ rejestru.',
                $period->toString(),
                $fromPostings->documentCount,
                $fromPostings->deductibleCostsNet->format(),
                $fromPostings->deductibleInputVat->format(),
                $manual->deductibleCostsNet->format(),
                $manual->deductibleInputVat->format(),
            ));
        }

        if ($fromPostings !== null) {
            return new self($period, self::POSTINGS, $fromPostings, sprintf(
                'Rejestr zakupów za %s z %d zaksięgowanych faktur.',
                $period->toString(),
                $fromPostings->documentCount,
            ));
        }

        if ($manual !== null) {
            return new self($period, self::MANUAL, $manual, sprintf(
                'Rejestr zakupów za %s z ręcznie wpisanej sumy miesięcznej.',
                $period->toString(),
            ));
        }

        return new self($period, self::NONE, null, sprintf(
            'Brak rejestru zakupów za %s — koszty nieznane, nie zerowe.',
            $period->toString(),
        ));
    }

    public function isConflict(): bool
    {
        return $this->source === self::CONFLICT;
    }

    public function jsonSerialize(): array
    {
        return [
            'period' => $this->period->toString(),
            'source' => $this->source,
            'register' => $this->register,
            'reason' => $this->reason,
        ];
    }
}
