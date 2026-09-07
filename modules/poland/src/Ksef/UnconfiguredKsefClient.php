<?php

declare(strict_types=1);

namespace Poland\Ksef;

use DateTimeImmutable;
use Poland\Ksef\Contracts\KsefClient;
use Poland\Ksef\Contracts\KsefInvoicePage;
use Poland\Ksef\Contracts\KsefSession;
use RuntimeException;

/**
 * The default KSeF client: one that refuses.
 *
 * Bound until a real client is configured with verified endpoint URLs and a
 * token. It throws rather than returning an empty page, because an empty page
 * is indistinguishable from "you have no incoming invoices this month" — and
 * that reading would quietly understate somebody's costs.
 */
final class UnconfiguredKsefClient implements KsefClient
{
    public function scope(): KsefScope
    {
        return KsefScope::InvoiceRead;
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function openSession(): KsefSession
    {
        throw new RuntimeException($this->message());
    }

    public function queryInvoices(
        KsefSession $session,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $cursor = null,
    ): KsefInvoicePage {
        throw new RuntimeException($this->message());
    }

    public function fetchInvoiceXml(KsefSession $session, string $ksefNumber): string
    {
        throw new RuntimeException($this->message());
    }

    public function closeSession(KsefSession $session): void
    {
        // Nothing was opened.
    }

    private function message(): string
    {
        return 'Integracja z KSeF nie jest skonfigurowana — nic nie zostało pobrane. '
            .'To NIE oznacza, że nie ma faktur zakupowych: oznacza, że system ich nie widzi. '
            .'Skonfiguruj adres środowiska i token (poland.ksef), potem uruchom synchronizację.';
    }
}
