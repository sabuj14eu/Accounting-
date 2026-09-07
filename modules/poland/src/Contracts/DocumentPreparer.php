<?php

declare(strict_types=1);

namespace Poland\Contracts;

use Poland\Domain\Period;
use Poland\Domain\TaxProfile;

/**
 * Builds a filing document for one channel. The PREPARATION stage.
 *
 * A port, not an implementation. The accounting core depends on this interface
 * and never on a provider, so KSeF, JPK and any future channel can be replaced
 * without the ledger or the calculators knowing.
 *
 * @param-note Implementations must never submit anything.
 */
interface DocumentPreparer
{
    public function channel(): \Poland\Reporting\FilingChannel;

    /** Whether this preparer can build a document for the given period at all. */
    public function supports(TaxProfile $profile, Period $period): bool;

    /** @param array<string,mixed> $context settlement data and rule versions */
    public function prepare(TaxProfile $profile, Period $period, array $context): PreparedDocument;
}
