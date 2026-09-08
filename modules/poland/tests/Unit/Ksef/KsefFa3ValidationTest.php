<?php

declare(strict_types=1);

namespace Poland\Tests\Unit\Ksef;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Poland\Domain\Money;
use Poland\Ksef\Fa3\Fa3Address;
use Poland\Ksef\Fa3\Fa3Buyer;
use Poland\Ksef\Fa3\Fa3BuyerIdentifier;
use Poland\Ksef\Fa3\Fa3Invoice;
use Poland\Ksef\Fa3\Fa3Line;
use Poland\Ksef\Fa3\Fa3Schema;
use Poland\Ksef\Fa3\Fa3SchemaValidator;
use Poland\Ksef\Fa3\Fa3Seller;
use Poland\Ksef\Fa3\Fa3SemanticChecks;
use Poland\Ksef\Fa3\Fa3VatRate;
use Poland\Ksef\Fa3\Fa3XmlGenerator;
use Poland\Tests\Support\KsefFixtures;

/** §15, §38: official XSD validation, offline, with the parser locked down. */
final class KsefFa3ValidationTest extends TestCase
{
    public function test_the_pinned_xsd_still_matches_what_the_code_emits(): void
    {
        $xsd = (string) file_get_contents(Fa3Schema::xsdPath());
        self::assertStringContainsString('targetNamespace="'.Fa3Schema::NAMESPACE.'"', $xsd);
        self::assertStringContainsString('fixed="'.Fa3Schema::SYSTEM_CODE.'"', $xsd);
        self::assertStringContainsString('fixed="'.Fa3Schema::SCHEMA_VERSION.'"', $xsd);
        self::assertStringContainsString('<xsd:enumeration value="3"/>', $xsd);
        foreach (Fa3Schema::localSchemaMap() as $path) {
            self::assertFileExists($path);
        }
        $sums = (string) file_get_contents(dirname(Fa3Schema::xsdDirectory(), 2).'/SHA256SUMS');
        self::assertStringContainsString(hash_file('sha256', Fa3Schema::xsdPath()), $sums, 'the XSD on disk is the pinned one');
    }

    public function test_the_ministrys_own_sample_invoice_validates(): void
    {
        $sample = (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/ksef/fa3-official-sample-template.xml');
        $sample = str_replace(['#nip#', '#invoicing_date#', '#invoice_number#'], [KsefFixtures::SELLER_NIP, '2026-09-01', '1234'], $sample);
        $result = Fa3SchemaValidator::forFa3()->validate($sample, KsefFixtures::now());
        self::assertTrue($result->valid, implode("\n", $result->errors));
        self::assertSame('xsd', $result->stage);
    }

    public function test_a_generated_invoice_validates_against_the_official_schema(): void
    {
        $result = Fa3SchemaValidator::forFa3()->validate(KsefFixtures::xml(), KsefFixtures::now());
        self::assertTrue($result->valid, implode("\n", $result->errors));
        self::assertSame(Fa3Schema::XSD_FILE, $result->schema);
    }

    public function test_a_schema_violation_is_reported_with_the_element_and_line(): void
    {
        $xml = str_replace('<P_15>1280.00</P_15>', '', KsefFixtures::xml());
        $result = Fa3SchemaValidator::forFa3()->validate($xml, KsefFixtures::now());
        self::assertFalse($result->valid);
        self::assertSame('xsd', $result->stage);
        self::assertStringContainsString('P_15', implode(' ', $result->errors));
    }

    public function test_bom_processing_instructions_doctype_and_forbidden_characters_are_refused(): void
    {
        $validator = Fa3SchemaValidator::forFa3();
        $xml = KsefFixtures::xml();
        self::assertSame('encoding', $validator->validate("\xEF\xBB\xBF".$xml)->stage);
        self::assertSame('structure', $validator->validate(str_replace('<Faktura', '<?php-pi x?><Faktura', $xml))->stage);
        self::assertSame('structure', $validator->validate('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><Faktura xmlns="'.Fa3Schema::NAMESPACE.'">&e;</Faktura>')->stage);
        self::assertSame('characters', $validator->validate(str_replace('Warszawa', "War\u{0086}szawa", $xml))->stage);
        self::assertSame('encoding', $validator->validate(str_replace('encoding="UTF-8"', 'encoding="ISO-8859-2"', $xml))->stage);
        self::assertSame('size', $validator->validate('')->stage);
        self::assertSame('size', $validator->validate(str_repeat('x', Fa3Schema::MAX_BYTES + 1))->stage);
        self::assertSame('root', $validator->validate('<?xml version="1.0"?><Other xmlns="'.Fa3Schema::NAMESPACE.'"/>')->stage);
        self::assertSame('well-formed', $validator->validate('<?xml version="1.0"?><Faktura xmlns="'.Fa3Schema::NAMESPACE.'"><open></Faktura>')->stage);
    }

    public function test_an_external_entity_reference_never_reaches_the_network_or_the_filesystem(): void
    {
        $xml = '<?xml version="1.0"?><Faktura xmlns="'.Fa3Schema::NAMESPACE.'" xmlns:xi="http://www.w3.org/2001/XInclude"><xi:include href="file:///etc/hostname"/></Faktura>';
        $result = Fa3SchemaValidator::forFa3()->validate($xml);
        self::assertFalse($result->valid);
        self::assertStringNotContainsString((string) @file_get_contents('/etc/hostname'), implode(' ', $result->errors) ?: 'x');
    }

    public function test_semantic_checks_refuse_what_the_schema_cannot_see(): void
    {
        $today = new DateTimeImmutable('2026-09-08');
        self::assertSame([], Fa3SemanticChecks::check(KsefFixtures::invoice(), $today));

        $badNip = new Fa3Invoice(
            new Fa3Seller('1234567890', 'X', new Fa3Address('PL', 'ul. A 1')),
            new Fa3Buyer(Fa3BuyerIdentifier::none(), 'Konsument'),
            'PLN',
            new DateTimeImmutable('2026-09-09'),
            'FV/1',
            [new Fa3Line(1, 'Usługa', Fa3VatRate::Rate23, Money::parse('100.00'), Money::parse('20.00'), '2', 'szt.', '100.00')],
        );
        $problems = Fa3SemanticChecks::check($badNip, $today);
        self::assertCount(4, $problems, implode("\n", $problems));
        self::assertStringContainsString('sumę kontrolną', $problems[0]);
        self::assertStringContainsString('przyszł', $problems[1]);
        self::assertStringContainsString('ilość × cena', $problems[2]);
        self::assertStringContainsString('VAT zaksięgowany', $problems[3]);
        self::assertStringContainsString('System nie poprawia kwot', $problems[3]);
    }

    public function test_an_exempt_line_without_its_legal_basis_is_refused(): void
    {
        $invoice = new Fa3Invoice(
            new Fa3Seller(KsefFixtures::SELLER_NIP, 'X', new Fa3Address('PL', 'ul. A 1')),
            new Fa3Buyer(Fa3BuyerIdentifier::none(), 'Konsument'),
            'PLN',
            new DateTimeImmutable('2026-09-01'),
            'FV/1',
            [new Fa3Line(1, 'Usługa', Fa3VatRate::Exempt, Money::parse('100.00'), Money::zero())],
        );
        $problems = Fa3SemanticChecks::check($invoice, new DateTimeImmutable('2026-09-08'));
        self::assertCount(1, $problems);
        self::assertStringContainsString('P_19A', $problems[0]);
    }

    public function test_the_nip_checksum_is_the_statutory_one(): void
    {
        self::assertTrue(Fa3SemanticChecks::nipChecksum('5265877635'));
        self::assertTrue(Fa3SemanticChecks::nipChecksum('3861610227'));
        self::assertFalse(Fa3SemanticChecks::nipChecksum('5265877636'));
        self::assertFalse(Fa3SemanticChecks::nipChecksum('123'));
    }

    public function test_the_generator_refuses_a_system_info_that_the_schema_would_refuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Fa3XmlGenerator(str_repeat('x', 257));
    }
}
