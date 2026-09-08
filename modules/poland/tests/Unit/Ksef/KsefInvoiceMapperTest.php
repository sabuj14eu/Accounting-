<?php

declare(strict_types=1);

namespace Poland\Tests\Unit\Ksef;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Poland\Ksef\Fa3\ErpInvoiceMapper;
use Poland\Ksef\Fa3\Fa3BuyerIdentifier;
use Poland\Ksef\Fa3\Fa3VatRate;
use Poland\Ksef\Fa3\Fa3XmlGenerator;
use Poland\Ksef\Fa3\MappingRefused;
use Poland\Tests\Support\KsefFixtures;

/** §14: the mapper is deterministic and refuses rather than fills in. */
final class KsefInvoiceMapperTest extends TestCase
{
    public function test_a_complete_snapshot_maps_to_an_fa3_invoice_with_the_booked_figures(): void
    {
        $invoice = (new ErpInvoiceMapper())->map(KsefFixtures::snapshot());
        self::assertSame('INV-000042', $invoice->number);
        self::assertSame(KsefFixtures::SELLER_NIP, $invoice->seller->nip);
        self::assertSame(Fa3BuyerIdentifier::NIP, $invoice->buyer->identifier->kind);
        self::assertCount(2, $invoice->lines);
        self::assertSame(Fa3VatRate::Rate23, $invoice->lines[0]->rate);
        self::assertSame(Fa3VatRate::Rate8, $invoice->lines[1]->rate);
        self::assertSame('1', $invoice->lines[0]->quantity);
        self::assertSame('1000', $invoice->lines[0]->unitNetPrice);
        $totals = $invoice->totals();
        self::assertSame('1 200,00 zł', str_replace("\u{00A0}", ' ', $totals->net->format()));
        self::assertSame('246,00 zł', $totals->vat->format());
        self::assertSame('1 446,00 zł', str_replace("\u{00A0}", ' ', $totals->gross->format()));
        self::assertSame('PL61109010140000071219812874', $invoice->payment?->bankAccount, 'spaces stripped from the account');
    }

    public function test_the_xml_is_byte_identical_for_the_same_input_and_timestamp(): void
    {
        $invoice = (new ErpInvoiceMapper())->map(KsefFixtures::snapshot());
        $generator = new Fa3XmlGenerator('SignalMesh Accounts');
        $a = $generator->generate($invoice, KsefFixtures::now());
        $b = $generator->generate((new ErpInvoiceMapper())->map(KsefFixtures::snapshot()), KsefFixtures::now());
        self::assertSame($a, $b);
        self::assertSame(hash('sha256', $a), hash('sha256', $b));

        $later = $generator->generate($invoice, KsefFixtures::now()->modify('+1 hour'));
        self::assertNotSame($a, $later);
        $changed = array_values(array_diff(explode("\n", $later), explode("\n", $a)));
        self::assertCount(1, $changed, 'only the creation timestamp differs');
        self::assertStringContainsString('DataWytworzeniaFa', $changed[0]);
    }

    public function test_the_generated_xml_carries_the_mandatory_markers_and_no_invented_values(): void
    {
        $xml = (new Fa3XmlGenerator())->generate((new ErpInvoiceMapper())->map(KsefFixtures::snapshot()), KsefFixtures::now());
        self::assertStringContainsString('<KodFormularza kodSystemowy="FA (3)" wersjaSchemy="1-0E">FA</KodFormularza>', $xml);
        self::assertStringContainsString('<WariantFormularza>3</WariantFormularza>', $xml);
        self::assertStringContainsString('<JST>2</JST>', $xml);
        self::assertStringContainsString('<GV>2</GV>', $xml);
        self::assertStringContainsString('<P_13_1>1000.00</P_13_1>', $xml);
        self::assertStringContainsString('<P_14_1>230.00</P_14_1>', $xml);
        self::assertStringContainsString('<P_13_2>200.00</P_13_2>', $xml);
        self::assertStringContainsString('<P_14_2>16.00</P_14_2>', $xml);
        self::assertStringContainsString('<P_15>1446.00</P_15>', $xml);
        self::assertStringContainsString('<P_19N>1</P_19N>', $xml, 'no exemption when no exempt lines');
        self::assertStringNotContainsString('<P_13_7>', $xml);
        self::assertStringNotContainsString('<Zaplacono>', $xml, 'paid was not stated, so it is not emitted');
        self::assertStringNotContainsString('<!DOCTYPE', $xml);
        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
    }

    public function test_a_buyer_without_a_confirmed_identifier_is_refused_not_guessed(): void
    {
        try {
            (new ErpInvoiceMapper())->map(KsefFixtures::snapshot(['buyer' => ['name' => 'X', 'identifier_type' => null]]));
            self::fail('expected refusal');
        } catch (MappingRefused $e) {
            self::assertCount(1, $e->reasons);
            self::assertStringContainsString('nie ustalono identyfikatora', $e->reasons[0]);
            self::assertStringContainsString('System nie zgaduje', $e->reasons[0]);
        }
    }

    public function test_a_consumer_buyer_is_an_explicit_brak_id(): void
    {
        $invoice = (new ErpInvoiceMapper())->map(KsefFixtures::snapshot(['buyer' => ['name' => 'Jan Nowak', 'identifier_type' => 'none']]));
        $xml = (new Fa3XmlGenerator())->generate($invoice, KsefFixtures::now());
        self::assertStringContainsString('<BrakID>1</BrakID>', $xml);
    }

    public function test_a_line_without_a_vat_rate_is_refused_for_a_vat_payer_and_exempt_for_an_exempt_taxpayer(): void
    {
        $lines = [['description' => 'Usługa', 'quantity' => 1, 'unit_price' => '100.00', 'amount' => '100.00', 'tax_amount' => '0', 'tax_rate' => null]];
        try {
            (new ErpInvoiceMapper())->map(KsefFixtures::snapshot(['lines' => $lines]));
            self::fail('expected refusal');
        } catch (MappingRefused $e) {
            self::assertStringContainsString('brak stawki VAT', $e->reasons[0]);
        }

        $invoice = (new ErpInvoiceMapper())->map(KsefFixtures::snapshot(['lines' => $lines, 'vatExemptTaxpayer' => true, 'exemptionLegalBasis' => 'art. 113 ust. 1 ustawy o VAT']));
        self::assertSame(Fa3VatRate::Exempt, $invoice->lines[0]->rate);
        $xml = (new Fa3XmlGenerator())->generate($invoice, KsefFixtures::now());
        self::assertStringContainsString('<P_13_7>100.00</P_13_7>', $xml);
        self::assertStringContainsString('<P_19A>art. 113 ust. 1 ustawy o VAT</P_19A>', $xml);
        self::assertStringContainsString('<P_12>zw</P_12>', $xml);
    }

    public function test_a_rate_the_schema_does_not_know_is_refused(): void
    {
        $lines = [['description' => 'Usługa', 'quantity' => 1, 'unit_price' => '100.00', 'amount' => '100.00', 'tax_amount' => '19.00', 'tax_rate' => 19]];
        try {
            (new ErpInvoiceMapper())->map(KsefFixtures::snapshot(['lines' => $lines]));
            self::fail('expected refusal');
        } catch (MappingRefused $e) {
            self::assertStringContainsString('19%', $e->reasons[0]);
        }
    }

    public function test_every_reason_is_reported_at_once(): void
    {
        try {
            (new ErpInvoiceMapper())->map(KsefFixtures::snapshot([
                'seller' => ['nip' => '12', 'name' => '', 'country' => 'PL', 'line1' => 'x'],
                'buyer' => ['name' => 'X', 'identifier_type' => null],
                'lines' => [],
            ]));
            self::fail('expected refusal');
        } catch (MappingRefused $e) {
            self::assertGreaterThanOrEqual(3, count($e->reasons));
        }
    }

    public function test_a_correction_invoice_references_the_corrected_one(): void
    {
        $invoice = (new ErpInvoiceMapper())->map(KsefFixtures::snapshot([
            'type' => 'KOR',
            'correction' => ['reason' => 'Błędna ilość', 'corrected_number' => 'INV-000041', 'corrected_issue_date' => '2026-08-20', 'corrected_ksef_number' => KsefFixtures::ksefNumber(41, new DateTimeImmutable('2026-08-20'), KsefFixtures::SELLER_NIP)],
        ]));
        $xml = (new Fa3XmlGenerator())->generate($invoice, KsefFixtures::now());
        self::assertStringContainsString('<RodzajFaktury>KOR</RodzajFaktury>', $xml);
        self::assertStringContainsString('<PrzyczynaKorekty>Błędna ilość</PrzyczynaKorekty>', $xml);
        self::assertStringContainsString('<NrKSeF>1</NrKSeF>', $xml);
        self::assertStringContainsString('<NrFaKorygowanej>INV-000041</NrFaKorygowanej>', $xml);
    }
}
