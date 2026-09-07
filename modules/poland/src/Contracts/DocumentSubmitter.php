<?php

declare(strict_types=1);

namespace Poland\Contracts;

/**
 * Submits a prepared document. The FILING stage — the only place in the system
 * where anything leaves for an authority.
 *
 * Implementations must be idempotent on PreparedDocument::$idempotencyKey: a
 * retry after a timeout must not file a second copy, because the taxpayer
 * cannot tell the difference and the authority can.
 */
interface DocumentSubmitter
{
    public function channel(): \Poland\Reporting\FilingChannel;

    /** Whether this submitter is configured well enough to actually send. */
    public function isConfigured(): bool;

    public function submit(PreparedDocument $document): FilingResult;

    /** Poll a submission that was accepted for processing but not yet resolved. */
    public function checkStatus(string $reference): FilingResult;
}
