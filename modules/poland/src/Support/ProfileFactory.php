<?php

declare(strict_types=1);

namespace Poland\Support;

use InvalidArgumentException;
use Poland\Domain\Enums\ContributionDeductionBasis;
use Poland\Domain\Enums\PitRegime;
use Poland\Domain\Enums\VatSettlementFrequency;
use Poland\Domain\Enums\VatStatus;
use Poland\Domain\Enums\ZusScheme;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Domain\TaxProfile;

/**
 * Builds a TaxProfile from stored configuration (JSON file, database row,
 * request payload) with the same validation everywhere.
 *
 * Unknown keys are an error rather than being ignored: a misspelled
 * "lump_sum_rate" that silently falls back to a default would change the tax
 * owed and leave no trace.
 */
final class ProfileFactory
{
    private const KEYS = [
        'name', 'nip', 'pit_regime', 'lump_sum_rate', 'vat_status', 'vat_settlement',
        'zus_scheme', 'sickness_insurance', 'business_started_at', 'business_started_on_day',
        'maly_zus_plus_base', 'accident_rate', 'deduction_basis', 'health_band_from_previous_year',
        'previous_year_revenue', 'reduce_health_band_by_social', 'cash_register_letters',
    ];

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): TaxProfile
    {
        $unknown = array_diff(array_keys($data), self::KEYS);
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unknown profile keys: %s. Known keys: %s.',
                implode(', ', $unknown),
                implode(', ', self::KEYS),
            ));
        }

        foreach (['name', 'pit_regime', 'vat_status', 'zus_scheme', 'business_started_at'] as $required) {
            if (! isset($data[$required])) {
                throw new InvalidArgumentException(sprintf('Profile key "%s" is required.', $required));
            }
        }

        return new TaxProfile(
            name: (string) $data['name'],
            pitRegime: self::enum(PitRegime::class, (string) $data['pit_regime'], 'pit_regime'),
            vatStatus: self::enum(VatStatus::class, (string) $data['vat_status'], 'vat_status'),
            zusScheme: self::enum(ZusScheme::class, (string) $data['zus_scheme'], 'zus_scheme'),
            businessStartedAt: Period::parse((string) $data['business_started_at']),
            businessStartedOnDay: (int) ($data['business_started_on_day'] ?? 1),
            nip: isset($data['nip']) ? (string) $data['nip'] : null,
            lumpSumRate: isset($data['lump_sum_rate']) ? (float) $data['lump_sum_rate'] : null,
            vatSettlement: isset($data['vat_settlement'])
                ? self::enum(VatSettlementFrequency::class, (string) $data['vat_settlement'], 'vat_settlement')
                : VatSettlementFrequency::Monthly,
            sicknessInsurance: (bool) ($data['sickness_insurance'] ?? true),
            malyZusPlusBase: isset($data['maly_zus_plus_base']) ? Money::parse((string) $data['maly_zus_plus_base']) : null,
            accidentRate: isset($data['accident_rate']) ? (float) $data['accident_rate'] : null,
            deductionBasis: isset($data['deduction_basis'])
                ? self::enum(ContributionDeductionBasis::class, (string) $data['deduction_basis'], 'deduction_basis')
                : ContributionDeductionBasis::AccruedForMonth,
            healthBandFromPreviousYear: (bool) ($data['health_band_from_previous_year'] ?? false),
            previousYearRevenue: isset($data['previous_year_revenue'])
                ? Money::parse((string) $data['previous_year_revenue'])
                : null,
            reduceHealthBandBySocial: (bool) ($data['reduce_health_band_by_social'] ?? true),
            cashRegisterLetters: (array) ($data['cash_register_letters'] ?? []),
        );
    }

    public static function fromJsonFile(string $path): TaxProfile
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Profile file not found: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException("Profile file is not a JSON object: {$path}");
        }

        return self::fromArray($decoded);
    }

    /**
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @return T
     */
    private static function enum(string $enum, string $value, string $key): \BackedEnum
    {
        $case = $enum::tryFrom($value);
        if ($case === null) {
            throw new InvalidArgumentException(sprintf(
                'Invalid value "%s" for "%s". Allowed: %s.',
                $value,
                $key,
                implode(', ', array_map(static fn ($c): string => $c->value, $enum::cases())),
            ));
        }

        return $case;
    }
}
