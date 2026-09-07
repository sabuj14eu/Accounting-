<?php

declare(strict_types=1);

namespace Poland\Ksef\Contracts;

use DateTimeImmutable;
use Poland\Ksef\KsefScope;

/**
 * Transport to KSeF. A port, so the accounting core never depends on a
 * particular API version or HTTP client.
 *
 * Implementations must:
 *  - hold InvoiceRead only, and refuse anything else;
 *  - never write a token, session key or challenge into a log, an exception
 *    message, or a rendered page;
 *  - be resumable: `queryInvoices` takes a cursor so an interrupted sync
 *    continues rather than restarting or skipping.
 */
interface KsefClient
{
    public function scope(): KsefScope;

    /** Whether this client has everything it needs to actually connect. */
    public function isConfigured(): bool;

    /**
     * Open an authenticated session.
     *
     * @throws \RuntimeException when authentication fails, with a message that
     *         contains no credential material.
     */
    public function openSession(): KsefSession;

    /**
     * Invoice metadata received in a date range, page by page.
     *
     * @param string|null $cursor continuation token from a previous page
     */
    public function queryInvoices(
        KsefSession $session,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $cursor = null,
    ): KsefInvoicePage;

    /** The original XML of one invoice, exactly as KSeF stores it. */
    public function fetchInvoiceXml(KsefSession $session, string $ksefNumber): string;

    public function closeSession(KsefSession $session): void;
}
