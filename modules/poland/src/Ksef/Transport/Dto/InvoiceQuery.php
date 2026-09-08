<?php

declare(strict_types=1);

namespace Poland\Ksef\Transport\Dto;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The body of POST /invoices/query/metadata.
 *
 * For incremental synchronisation the pinned guide is explicit: date type
 * `PermanentStorage`, ascending order, `restrictToPermanentStorageHwmDate`
 * so the window's end is the server's own completeness mark.
 */
final class InvoiceQuery
{
    public const SUBJECT_SELLER = 'Subject1';

    public const SUBJECT_BUYER = 'Subject2';

    public const SUBJECT_THIRD = 'Subject3';

    public const SUBJECT_AUTHORIZED = 'SubjectAuthorized';

    public const DATE_ISSUE = 'Issue';

    public const DATE_INVOICING = 'Invoicing';

    public const DATE_PERMANENT_STORAGE = 'PermanentStorage';

    /** @param array<string,mixed> $extraFilters passed through verbatim (ksefNumber, invoiceNumber, ...) */
    public function __construct(
        public readonly string $subjectType,
        public readonly string $dateType,
        public readonly DateTimeImmutable $from,
        public readonly ?DateTimeImmutable $to = null,
        public readonly bool $restrictToPermanentStorageHwmDate = false,
        public readonly array $extraFilters = [],
    ) {
        if (! in_array($subjectType, [self::SUBJECT_SELLER, self::SUBJECT_BUYER, self::SUBJECT_THIRD, self::SUBJECT_AUTHORIZED], true)) {
            throw new \InvalidArgumentException('Unknown subjectType '.$subjectType);
        }
        if (! in_array($dateType, [self::DATE_ISSUE, self::DATE_INVOICING, self::DATE_PERMANENT_STORAGE], true)) {
            throw new \InvalidArgumentException('Unknown dateType '.$dateType);
        }
        if ($to !== null && $to < $from) {
            throw new \InvalidArgumentException('Query window ends before it starts.');
        }
        // The contract caps a window at 100 days in UTC.
        $limit = $from->modify('+100 days');
        if ($to !== null && $to > $limit) {
            throw new \InvalidArgumentException('Query window exceeds the 100-day maximum.');
        }
    }

    public static function incremental(string $subjectType, DateTimeImmutable $from, ?DateTimeImmutable $to = null): self
    {
        return new self($subjectType, self::DATE_PERMANENT_STORAGE, $from, $to, true);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $utc = new DateTimeZone('UTC');
        $range = [
            'dateType' => $this->dateType,
            'from' => $this->from->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
        ];
        if ($this->to !== null) {
            $range['to'] = $this->to->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z');
        }
        if ($this->dateType === self::DATE_PERMANENT_STORAGE) {
            $range['restrictToPermanentStorageHwmDate'] = $this->restrictToPermanentStorageHwmDate;
        }

        return ['subjectType' => $this->subjectType, 'dateRange' => $range] + $this->extraFilters;
    }
}
