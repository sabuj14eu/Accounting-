<?php

declare(strict_types=1);

namespace Poland\Ksef\Outgoing;

/**
 * The identity that makes a submission idempotent.
 *
 * It mirrors KSeF's own duplicate rule (`weryfikacja-faktury.md`: seller NIP,
 * invoice type, invoice number) plus the environment, because the same
 * number legitimately exists once on TEST and once on PRODUCTION. Stored in
 * a UNIQUE column while a submission is live and cleared when it ends
 * rejected or cancelled, so the database — not a check-then-insert — refuses
 * the second live submission of one invoice.
 */
final class SubmissionIdentity
{
    public static function activeKey(string $environment, string $sellerNip, string $invoiceType, string $invoiceNumber): string
    {
        $nip = preg_replace('/\D/', '', $sellerNip) ?? '';

        return hash('sha256', implode("\x1F", [strtolower($environment), $nip, strtoupper($invoiceType), trim($invoiceNumber)]));
    }

    /** A document identity: the exact bytes that were (or will be) sent. */
    public static function documentKey(string $xml): string
    {
        return hash('sha256', $xml);
    }
}
