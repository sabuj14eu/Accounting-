<?php

declare(strict_types=1);

namespace Shop\Reporting;

use Shop\Profit\ProfitStatement;

/** The standing note that must accompany the management profit wherever it is shown. */
final class ProfitStatementNote
{
    public static function text(): string
    {
        return wordwrap(ProfitStatement::NOT_TAX_PROFIT, 78);
    }
}
