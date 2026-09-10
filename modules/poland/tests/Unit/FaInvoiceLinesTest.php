<?php

declare(strict_types=1);

namespace Poland\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Poland\Ksef\Parsing\FaInvoiceParser;
use Poland\Ksef\Parsing\ParsedInvoice;
use Poland\Tests\Support\InvoiceFixtures;

/**
 * Stage B, section 2.3 of the design: line items, payment terms and the
 * correction reference are read from the document; nothing absent becomes a
 * one, a zero or a blank.
 */
final class FaInvoiceLinesTest extends TestCase
{
    public function test_every_fa_wiersz_becomes_one_line_in_document_order(): void
    {
        $invoice = InvoiceFixtures::drinks();

        self::assertCount(3, $invoice->lines);
        self::assertSame([1, 2, 3], array_map(static fn ($l): int => $l->lineNo, $invoice->lines));
        self::assertSame('Coca-Cola 0,5 l karton 24 szt', $invoice->lines[0]->description);
        self::assertSame('10', $invoice->lines[0]->quantity);
        self::assertSame('op', $invoice->lines[0]->unit);
        self::assertSame('1200.00', $invoice->lines[0]->net?->jsonSerialize());
        self::assertSame('23', $invoice->lines[0]->vatRate);
        self::assertSame('0.23', $invoice->lines[0]->designation());
        self::assertSame('CC05-24', $invoice->lines[0]->supplierIndex);
        self::assertSame('kg', $invoice->lines[2]->unit);
        self::assertSame('0.05', $invoice->lines[2]->designation());
    }

    public function test_a_stated_vat_amount_is_kept_and_an_absent_one_is_derived_and_says_so(): void
    {
        $invoice = InvoiceFixtures::drinks();

        // Line 1 states P_11Vat.
        self::assertSame('276.00', $invoice->lines[0]->vat?->jsonSerialize());
        self::assertFalse($invoice->lines[0]->vatIsDerived);

        // Line 2 does not: 200,00 × 23% = 46,00, and the line says it derived it.
        self::assertSame('46.00', $invoice->lines[1]->vat?->jsonSerialize());
        self::assertTrue($invoice->lines[1]->vatIsDerived);

        // Gross was never stated on any line, so it is derived and labelled.
        self::assertSame('1476.00', $invoice->lines[0]->gross?->jsonSerialize());
        self::assertTrue($invoice->lines[0]->grossIsDerived);
    }

    public function test_line_totals_are_checked_against_header_totals_per_rate(): void
    {
        self::assertTrue(InvoiceFixtures::drinks()->lineTotalsAgree());

        // Change one line's net so the lines no longer add up to P_13_1.
        $xml = str_replace('<P_11>200.00</P_11>', '<P_11>250.00</P_11>', InvoiceFixtures::xml('fa2-drinks-invoice.xml'));
        $invoice = (new FaInvoiceParser())->parse($xml, 'X-1');

        self::assertFalse($invoice->lineTotalsAgree(), 'a mismatch between lines and header must be visible, not repaired');
        // The header is left exactly as declared.
        self::assertSame('1464.00', $invoice->metadata->net?->jsonSerialize());
    }

    public function test_an_invoice_without_lines_has_nothing_to_compare(): void
    {
        $invoice = InvoiceFixtures::parse('fa2-incoming.xml');

        self::assertFalse($invoice->hasLines());
        self::assertNull($invoice->lineTotalsAgree());
        self::assertFalse($invoice->paymentTerms()->isPresent());
    }

    public function test_missing_quantity_or_unit_stays_missing_never_one_or_blank(): void
    {
        $xml = <<<'XML'
            <Faktura><Podmiot1><NIP>1132316939</NIP></Podmiot1><Fa>
              <P_1>2026-08-11</P_1><P_2>A/1</P_2><P_13_1>100.00</P_13_1><P_14_1>23.00</P_14_1><P_15>123.00</P_15>
              <FaWiersz><NrWierszaFa>1</NrWierszaFa><P_7>Usługa</P_7><P_11>100.00</P_11><P_12>23</P_12></FaWiersz>
            </Fa></Faktura>
            XML;
        $line = (new FaInvoiceParser())->parse($xml, 'X-2')->lines[0];

        self::assertNull($line->quantity);
        self::assertNull($line->unit);
        self::assertNull($line->quantityAsFloat());
        self::assertSame(ParsedInvoice::MISSING, $line->jsonSerialize()['quantity']);
        self::assertSame(ParsedInvoice::MISSING, $line->jsonSerialize()['unit']);
    }

    public function test_an_unknown_rate_code_is_reported_not_booked(): void
    {
        $xml = <<<'XML'
            <Faktura><Podmiot1><NIP>1132316939</NIP></Podmiot1><Fa>
              <P_1>2026-08-11</P_1><P_2>A/2</P_2><P_13_4>100.00</P_13_4><P_14_4>0.00</P_14_4><P_15>100.00</P_15>
              <FaWiersz><NrWierszaFa>1</NrWierszaFa><P_7>Złom</P_7><P_8B>1</P_8B><P_11>100.00</P_11><P_12>oo</P_12></FaWiersz>
            </Fa></Faktura>
            XML;
        $line = (new FaInvoiceParser())->parse($xml, 'X-3')->lines[0];

        self::assertSame('oo', $line->vatRate);
        self::assertNull($line->designation());
        self::assertFalse($line->vatRateIsKnown());
        self::assertNull($line->vat, 'reverse charge is not derived as zero VAT — it is a decision');
    }

    public function test_payment_terms_due_date_form_and_account_are_read(): void
    {
        $payment = InvoiceFixtures::drinks()->paymentTerms();

        self::assertTrue($payment->isPresent());
        self::assertSame('2026-08-25', $payment->dueDate?->format('Y-m-d'));
        self::assertSame('6', $payment->paymentForm);
        self::assertSame('przelew', $payment->paymentFormLabel());
        self::assertSame('PL61109010140000071219812874', $payment->supplierAccount);
        self::assertFalse($payment->settledAtIssue());
    }

    public function test_absent_zaplacono_is_null_not_false(): void
    {
        self::assertNull(InvoiceFixtures::drinks()->paymentTerms()->paidOnInvoice);

        $xml = str_replace(
            '<FormaPlatnosci>6</FormaPlatnosci>',
            '<Zaplacono>1</Zaplacono><DataZaplaty>2026-08-11</DataZaplaty><FormaPlatnosci>1</FormaPlatnosci>',
            InvoiceFixtures::xml('fa2-drinks-invoice.xml'),
        );
        $payment = (new FaInvoiceParser())->parse($xml, 'X-4')->paymentTerms();

        self::assertTrue($payment->paidOnInvoice);
        self::assertSame('gotówka', $payment->paymentFormLabel());
        self::assertTrue($payment->settledAtIssue());
        self::assertSame('2026-08-11', $payment->paymentDate?->format('Y-m-d'));
    }

    public function test_a_correction_links_to_its_original_by_ksef_number_then_by_number_and_nip(): void
    {
        $correction = InvoiceFixtures::parse('fa2-correction-linked.xml', 'KOR-1')->correction;

        self::assertNotNull($correction);
        self::assertSame('1132316939-20260811-A1B2C3D4E5F6-01', $correction->originalKsefNumber);
        self::assertSame('FV/2026/08/417', $correction->originalInvoiceNumber);
        self::assertSame('2026-08-11', $correction->originalInvoiceDate?->format('Y-m-d'));
        self::assertSame('Zwrot 5 kartonów Coca-Cola', $correction->reason);
        self::assertSame('2', $correction->type);
        self::assertTrue($correction->canLinkAutomatically());

        // The old fixture: a KOR with no DaneFaKorygowanej at all.
        $bare = InvoiceFixtures::parse('fa2-correction.xml', 'KOR-2')->correction;
        self::assertNotNull($bare, 'a KOR always carries a correction object, even an empty one');
        self::assertFalse($bare->canLinkAutomatically());

        // A plain VAT invoice has none.
        self::assertNull(InvoiceFixtures::drinks()->correction);
    }

    public function test_a_schema_version_with_unknown_line_elements_reports_them(): void
    {
        $xml = str_replace(
            '<P_12>5</P_12>',
            '<P_12>5</P_12><NowePoleFA4>x</NowePoleFA4>',
            InvoiceFixtures::xml('fa2-drinks-invoice.xml'),
        );
        $invoice = (new FaInvoiceParser())->parse($xml, 'X-5');

        self::assertContains('NowePoleFA4', $invoice->unknownElements);
        self::assertArrayHasKey('NowePoleFA4', $invoice->lines[2]->raw, 'the row keeps every child verbatim');
        // The known line, payment and correction elements are NOT reported as unknown.
        foreach (['FaWiersz', 'P_7', 'P_8B', 'P_11Vat', 'Termin', 'NrRB', 'FormaPlatnosci'] as $known) {
            self::assertNotContains($known, $invoice->unknownElements);
        }
    }

    public function test_the_service_period_is_read_when_the_invoice_covers_a_period(): void
    {
        $xml = str_replace(
            '<P_6>2026-08-11</P_6>',
            '<OkresFa><P_6_Od>2026-08-01</P_6_Od><P_6_Do>2026-08-31</P_6_Do></OkresFa>',
            InvoiceFixtures::xml('fa2-drinks-invoice.xml'),
        );
        $invoice = (new FaInvoiceParser())->parse($xml, 'X-6', new DateTimeImmutable('2026-09-01'));

        self::assertSame('2026-08-01', $invoice->servicePeriodFrom?->format('Y-m-d'));
        self::assertSame('2026-08-31', $invoice->servicePeriodTo?->format('Y-m-d'));
        self::assertNotContains('service_period_from', $invoice->missing);
    }
}
