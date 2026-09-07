<?php

declare(strict_types=1);

namespace Poland\Adapters\Null;

use DateTimeImmutable;
use Poland\Contracts\ExchangeRateProvider;
use Poland\Contracts\ExchangeRateQuote;
use RuntimeException;

/**
 * The default exchange-rate provider: one that refuses.
 *
 * A missing rate is not a rate of 1.0, and it is not yesterday's rate either.
 * Until an NBP adapter is configured, a foreign-currency transaction cannot be
 * converted and the system says so instead of inventing a number that will look
 * plausible on an invoice for years.
 */
final class UnavailableExchangeRateProvider implements ExchangeRateProvider
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function rateFor(string $currency, DateTimeImmutable $transactionDate): ExchangeRateQuote
    {
        throw new RuntimeException(sprintf(
            'Brak skonfigurowanego źródła kursów walut, więc kurs %s na %s nie jest znany. '
            .'Przeliczenie zostało wstrzymane — kurs domyślny byłby liczbą wyglądającą wiarygodnie '
            .'i nieprawdziwą. Skonfiguruj adapter NBP (Faza 2).',
            $currency,
            $transactionDate->format('Y-m-d'),
        ));
    }
}
