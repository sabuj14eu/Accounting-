<?php

declare(strict_types=1);

namespace Poland\Ksef;

use DateTimeImmutable;
use Poland\Ksef\Contracts\KsefClient;
use Poland\Ksef\Contracts\KsefInvoicePage;
use Poland\Ksef\Contracts\KsefSession;
use Poland\Ksef\Parsing\FaInvoiceParser;
use RuntimeException;

/**
 * An in-memory KSeF for tests and for KSEF_TRANSPORT=fake outside production.
 *
 * It behaves like the real thing where the ingest pipeline can tell the
 * difference — paging, a session that must be open, a fetch that fails for an
 * unknown number — and is honest about what it is everywhere else: every
 * session reference starts with "FAKE-", and {@see TransportGate} refuses to
 * let production bind it at all.
 */
final class FakeKsefClient implements KsefClient
{
    /** @var array<string,array{metadata: KsefInvoiceMetadata, xml: string}> keyed by KSeF number */
    private array $invoices = [];

    /** @var list<string> */
    private array $openSessions = [];

    /** @var list<array{method:string, args: array<int,mixed>}> */
    private array $calls = [];

    private ?\Throwable $failNextQueryWith = null;

    public function __construct(
        private readonly int $pageSize = 2,
        private readonly bool $configured = true,
    ) {
    }

    /**
     * Build one from FA XML files, deriving metadata from the invoice itself so
     * the fixtures already in tests/Fixtures can stand in for a KSeF inbox.
     *
     * @param list<string> $paths
     */
    public static function fromXmlFiles(array $paths, FaInvoiceParser $parser, int $pageSize = 2): self
    {
        $fake = new self($pageSize);
        foreach ($paths as $index => $path) {
            $xml = (string) file_get_contents($path);
            $probe = $parser->parse($xml, 'PROBE', new DateTimeImmutable('2000-01-01'));
            $date = $probe->metadata->invoiceDate ?? new DateTimeImmutable('2026-01-01');
            $number = KsefNumber::synthetic($probe->metadata->sellerNip ?? '1111111111', $date, basename($path).$index);
            $fake->add(new KsefInvoiceMetadata(
                ksefNumber: $number,
                retrievedAt: new DateTimeImmutable(),
                invoiceDate: $date,
                permanentStorageDate: $date->modify('+1 day'),
                invoiceNumber: $probe->metadata->invoiceNumber,
                sellerNip: $probe->metadata->sellerNip,
                sellerName: $probe->metadata->sellerName,
                buyerNip: $probe->metadata->buyerNip,
                buyerName: $probe->metadata->buyerName,
                net: $probe->metadata->net,
                vat: $probe->metadata->vat,
                gross: $probe->metadata->gross,
                currency: $probe->metadata->currency,
                invoiceType: $probe->metadata->invoiceType,
            ), $xml);
        }

        return $fake;
    }

    public function add(KsefInvoiceMetadata $metadata, string $xml): self
    {
        $this->invoices[$metadata->ksefNumber] = ['metadata' => $metadata, 'xml' => $xml];

        return $this;
    }

    public function failNextQueryWith(\Throwable $e): void
    {
        $this->failNextQueryWith = $e;
    }

    public function scope(): KsefScope
    {
        return KsefScope::InvoiceRead;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function openSession(): KsefSession
    {
        $this->calls[] = ['method' => 'openSession', 'args' => []];
        if (! $this->configured) {
            throw new RuntimeException('Atrapa KSeF oznaczona jako nieskonfigurowana.');
        }
        $reference = 'FAKE-'.str_pad((string) (count($this->openSessions) + 1), 4, '0', STR_PAD_LEFT);
        $this->openSessions[] = $reference;

        return new KsefSession('fake-access-token-'.$reference, $reference, new DateTimeImmutable());
    }

    public function queryInvoices(
        KsefSession $session,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $cursor = null,
    ): KsefInvoicePage {
        $this->calls[] = ['method' => 'queryInvoices', 'args' => [$from, $to, $cursor]];
        $this->assertOpen($session);

        if ($this->failNextQueryWith !== null) {
            $e = $this->failNextQueryWith;
            $this->failNextQueryWith = null;
            throw $e;
        }

        $matching = array_values(array_filter(
            array_map(static fn (array $row): KsefInvoiceMetadata => $row['metadata'], $this->invoices),
            static function (KsefInvoiceMetadata $m) use ($from, $to): bool {
                $at = $m->permanentStorageDate ?? $m->invoiceDate;

                return $at !== null && $at >= $from && $at <= $to;
            },
        ));
        usort($matching, static fn (KsefInvoiceMetadata $a, KsefInvoiceMetadata $b): int => ($a->permanentStorageDate ?? $a->invoiceDate) <=> ($b->permanentStorageDate ?? $b->invoiceDate));

        $offset = $cursor === null ? 0 : (int) $cursor;
        $page = array_slice($matching, $offset, $this->pageSize);
        $next = $offset + $this->pageSize < count($matching) ? (string) ($offset + $this->pageSize) : null;

        return new KsefInvoicePage($page, $next, count($matching));
    }

    public function fetchInvoiceXml(KsefSession $session, string $ksefNumber): string
    {
        $this->calls[] = ['method' => 'fetchInvoiceXml', 'args' => [$ksefNumber]];
        $this->assertOpen($session);

        if (! isset($this->invoices[$ksefNumber])) {
            throw new RuntimeException('Atrapa KSeF nie zna faktury o podanym numerze.');
        }

        return $this->invoices[$ksefNumber]['xml'];
    }

    public function closeSession(KsefSession $session): void
    {
        $this->calls[] = ['method' => 'closeSession', 'args' => []];
        $this->openSessions = array_values(array_filter(
            $this->openSessions,
            static fn (string $ref): bool => $ref !== $session->reference,
        ));
    }

    /** @return list<array{method:string, args: array<int,mixed>}> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function count(): int
    {
        return count($this->invoices);
    }

    private function assertOpen(KsefSession $session): void
    {
        if (! in_array($session->reference, $this->openSessions, true)) {
            throw new RuntimeException('Sesja atrapy KSeF nie jest otwarta.');
        }
    }
}
