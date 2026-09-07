<?php

declare(strict_types=1);

namespace Poland\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poland\Ksef\Parsing\FaInvoiceParser;

final class FaInvoiceParserTest extends TestCase
{
    private function parse(string $fixture, string $number = 'KSEF-1')
    {
        return (new FaInvoiceParser())->parse(
            (string) file_get_contents(__DIR__.'/../Fixtures/'.$fixture),
            $number,
        );
    }

    public function test_it_sums_totals_across_vat_rates(): void
    {
        $invoice = $this->parse('fa2-incoming.xml');

        self::assertSame(500000, $invoice->metadata->net->grosze, '4 000 at 23% + 1 000 at 5%');
        self::assertSame(97000, $invoice->metadata->vat->grosze, '920 + 50');
        self::assertSame(597000, $invoice->metadata->gross->grosze);
        self::assertTrue($invoice->totalsAgree());
        self::assertTrue($invoice->isComplete());
    }

    public function test_it_reads_a_schema_version_it_has_never_seen(): void
    {
        // The namespace URI changes with every FA release. A parser pinned to
        // one returns silently empty for the next, which looks exactly like
        // "this invoice had no seller".
        $invoice = $this->parse('fa3-future-namespace.xml', 'KSEF-3');

        self::assertSame('http://crd.gov.pl/wzor/2026/01/01/99999/', $invoice->namespace);
        self::assertTrue($invoice->isComplete());
        self::assertSame(20000, $invoice->metadata->net->grosze);
        self::assertSame('1132316939', $invoice->metadata->sellerNip);
    }

    public function test_a_correction_keeps_its_negative_amounts(): void
    {
        $invoice = $this->parse('fa2-correction.xml', 'KSEF-2');

        self::assertTrue($invoice->metadata->isCorrection());
        self::assertSame(-50000, $invoice->metadata->net->grosze);
        self::assertSame(-61500, $invoice->metadata->gross->grosze);
        self::assertTrue($invoice->totalsAgree());
    }

    public function test_missing_fields_are_reported_never_defaulted(): void
    {
        // A missing net amount must not become 0,00 zł — that would post a
        // zero-cost invoice to the books and nothing downstream could notice.
        $invoice = $this->parse('fa-no-namespace-partial.xml', 'KSEF-4');

        self::assertNull($invoice->metadata->net);
        self::assertNull($invoice->metadata->vat);
        self::assertContains('net', $invoice->missing);
        self::assertContains('invoice_date', $invoice->missing);
        self::assertFalse($invoice->isComplete());
        self::assertNull($invoice->totalsAgree(), 'cannot check totals that are not there');
    }

    public function test_it_identifies_direction_from_the_taxpayer_nip(): void
    {
        $invoice = $this->parse('fa2-incoming.xml');

        self::assertTrue($invoice->metadata->isIncomingFor('5260250274'));
        self::assertFalse($invoice->metadata->isOutgoingFor('5260250274'));
        self::assertTrue($invoice->metadata->isOutgoingFor('113-231-69-39'), 'NIP formatting is ignored');
    }

    public function test_unparseable_xml_throws_rather_than_returning_an_empty_invoice(): void
    {
        $this->expectExceptionMessageMatches('/Nie udało się odczytać XML/u');
        (new FaInvoiceParser())->parse('<not-closed', 'KSEF-BAD');
    }

    public function test_a_document_is_never_marked_validated_without_a_schema(): void
    {
        // No XSD is shipped, so nothing can claim to have been validated.
        $invoice = $this->parse('fa2-incoming.xml');

        self::assertFalse($invoice->validated);
        self::assertSame([], $invoice->validationErrors);
    }
}
