<?php

declare(strict_types=1);

namespace Poland\Banking\Contracts;

use Poland\Banking\ParsedStatement;

/**
 * Reads a bank statement in one format into transactions.
 *
 * A port: Polish banks export CSV in mutually incompatible dialects, and MT940
 * and CAMT are separate standards again. Adding a bank must never mean editing
 * the accounting core.
 */
interface StatementParser
{
    public function format(): string;

    /** Cheap check on the content itself, not on the filename. */
    public function supports(string $content, ?string $filename = null): bool;

    public function parse(string $content, ?string $filename = null): ParsedStatement;
}
