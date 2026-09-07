<?php

declare(strict_types=1);

namespace Poland\Support;

use DateTimeImmutable;
use Poland\Domain\Period;
use Poland\Rates\RateRepository;

/**
 * Statutory deadlines, shifted off weekends and public holidays.
 *
 * A deadline falling on a Saturday, Sunday or public holiday moves to the next
 * working day (Ordynacja podatkowa art. 12 § 5). The shift can only move a
 * deadline LATER, never earlier.
 *
 * When the holiday table has no entry for the year, the raw statutory date is
 * returned together with a flag saying the shift could not be applied — a date
 * that might be a day early is worth more than a silent guess, but only if the
 * caller is told which one it got.
 */
final class DeadlineCalendar
{
    public function __construct(private readonly RateRepository $rates) {}

    /** @return array{date: DateTimeImmutable, statutory: DateTimeImmutable, shifted: bool, verified: bool, label: string} */
    public function for(string $kind, Period $period): array
    {
        $table = $this->rates->deadlines();
        $day = (int) $table->constant($kind.'.day');
        $label = (string) $table->constant($kind.'.label');

        $due = $period->next();
        $statutory = new DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $due->year, $due->month, $day));

        /** @var array<int,list<string>> $holidays */
        $holidays = (array) $table->constant('public_holidays');
        $verified = isset($holidays[$statutory->format('Y')]) || isset($holidays[(int) $statutory->format('Y')]);

        if (! $verified) {
            return [
                'date' => $statutory,
                'statutory' => $statutory,
                'shifted' => false,
                'verified' => false,
                'label' => $label,
            ];
        }

        $date = $statutory;
        while ($this->isNonWorkingDay($date, $holidays)) {
            $date = $date->modify('+1 day');
        }

        return [
            'date' => $date,
            'statutory' => $statutory,
            'shifted' => $date != $statutory,
            'verified' => true,
            'label' => $label,
        ];
    }

    /** @param array<int|string,list<string>> $holidays */
    private function isNonWorkingDay(DateTimeImmutable $date, array $holidays): bool
    {
        if (in_array($date->format('N'), ['6', '7'], true)) {
            return true;
        }

        $year = (int) $date->format('Y');
        $list = $holidays[$year] ?? [];

        return in_array($date->format('m-d'), $list, true);
    }
}
