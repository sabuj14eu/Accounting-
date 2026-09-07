<?php

declare(strict_types=1);

namespace Poland\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Poland\Domain\Enums\PitRegime;
use Poland\Domain\Enums\VatStatus;
use Poland\Domain\Enums\ZusScheme;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Domain\TaxProfile;

final class TaxProfileTest extends TestCase
{
    public function test_lump_sum_without_a_rate_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cannot be inferred/');

        new TaxProfile('X', PitRegime::LumpSum, VatStatus::ExemptBySize, ZusScheme::Full, Period::of(2020, 1));
    }

    public function test_maly_zus_plus_without_a_base_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TaxProfile('X', PitRegime::Scale, VatStatus::ExemptBySize, ZusScheme::MalyZusPlus, Period::of(2020, 1));
    }

    public function test_nip_checksum_is_validated(): void
    {
        // 5260250274 is the Ministry of Finance's own published example NIP.
        self::assertTrue(TaxProfile::isValidNip('5260250274'));
        self::assertTrue(TaxProfile::isValidNip('526-025-02-74'));
        self::assertFalse(TaxProfile::isValidNip('5260250275'));
        self::assertFalse(TaxProfile::isValidNip('1234567890'));
        self::assertFalse(TaxProfile::isValidNip('12345'));
    }

    public function test_a_bad_nip_is_refused_at_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TaxProfile(
            'X', PitRegime::Scale, VatStatus::ExemptBySize, ZusScheme::Full,
            Period::of(2020, 1), nip: '1234567890',
        );
    }

    public function test_time_limited_schemes_report_expiry_without_changing_themselves(): void
    {
        $profile = new TaxProfile(
            'X', PitRegime::Scale, VatStatus::ExemptBySize, ZusScheme::Preferential, Period::of(2024, 1),
        );

        self::assertFalse($profile->zusSchemeExpiredAt(Period::of(2025, 12)), '23 months in');
        self::assertTrue($profile->zusSchemeExpiredAt(Period::of(2026, 1)), '24 months in');
        self::assertSame(ZusScheme::Preferential, $profile->zusScheme, 'The profile is never silently rewritten');
    }

    public function test_insured_days_are_counted_only_for_an_incomplete_first_month(): void
    {
        $profile = new TaxProfile(
            'X', PitRegime::Scale, VatStatus::ExemptBySize, ZusScheme::Full,
            Period::of(2026, 6), businessStartedOnDay: 16,
        );

        self::assertSame(15, $profile->insuredDaysIn(Period::of(2026, 6)));
        self::assertNull($profile->insuredDaysIn(Period::of(2026, 7)), 'Later months are complete');
    }

    public function test_a_first_of_the_month_start_needs_no_proration(): void
    {
        $profile = new TaxProfile(
            'X', PitRegime::Scale, VatStatus::ExemptBySize, ZusScheme::Full, Period::of(2026, 6),
        );

        self::assertNull($profile->insuredDaysIn(Period::of(2026, 6)));
    }
}
