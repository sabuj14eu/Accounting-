<?php

declare(strict_types=1);

namespace Poland\Tests\Unit\Ksef;

use PHPUnit\Framework\TestCase;
use Poland\Ksef\Transport\FakeKsefTransport;
use Poland\Ksef\Upo\UpoParser;
use Poland\Tests\Support\KsefFixtures;

/** §24: the UPO is preserved verbatim and read against the pinned schema; never manufactured. */
final class KsefUpoTest extends TestCase
{
    public function test_the_ministrys_invoice_upo_sample_is_read(): void
    {
        $xml = (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/ksef/upo-faktura-official-sample.xml');
        $upo = (new UpoParser())->parse($xml, KsefFixtures::now());
        self::assertSame($xml, $upo->xml, 'verbatim');
        self::assertSame(hash('sha256', $xml), $upo->xmlHash);
        self::assertTrue($upo->schemaValid);
        self::assertStringContainsString('dopisek środowiska', $upo->schemaErrors[0] ?? '', 'the TEST-environment name deviation is recorded, not hidden');
        self::assertSame('5265877635', $upo->contextNip);
        self::assertSame('FA (3)', $upo->formCode);
        self::assertSame('Schemat_FA(3)_v1-0E.xsd', $upo->logicalStructure);
        self::assertCount(1, $upo->documents);
        $doc = $upo->documentFor('5265877635-20250916-0200A0D6723E-C2');
        self::assertNotNull($doc);
        self::assertSame('FA/XVQUD-9997622510/04/2027', $doc['invoice_number']);
        self::assertSame('2025-09-16', $doc['issue_date']);
        self::assertSame('Online', $doc['mode']);
        self::assertSame('GZMGNVzs3krF6URKgvaw77OOeG3nJ+WGziT5xguliQ8=', $doc['document_hash']);
    }

    public function test_the_ministrys_session_upo_sample_lists_every_document(): void
    {
        $xml = (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/ksef/upo-sesja-official-sample.xml');
        $upo = (new UpoParser())->parse($xml);
        self::assertTrue($upo->schemaValid);
        self::assertGreaterThanOrEqual(2, count($upo->documents));
        self::assertNull($upo->documentFor('0000000000-20250101-000000000000-00'));
    }

    public function test_a_upo_that_breaks_the_schema_is_kept_but_marked(): void
    {
        $xml = str_replace('<NumerKSeFDokumentu>', '<NumerKSeFDokumentu>BAD-', (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/ksef/upo-faktura-official-sample.xml'));
        $upo = (new UpoParser())->parse($xml);
        self::assertFalse($upo->schemaValid);
        self::assertNotEmpty($upo->schemaErrors);
        self::assertSame($xml, $upo->xml);
        self::assertStringStartsWith('BAD-', (string) $upo->documents[0]['ksef_number']);
    }

    public function test_the_fake_transports_upo_is_recognisably_fake_and_still_schema_shaped(): void
    {
        $xml = FakeKsefTransport::upoXml('20260908-SO-0000000001-0000001EEF-01', KsefFixtures::ksefNumber(1, null, KsefFixtures::SELLER_NIP), KsefFixtures::now());
        $upo = (new UpoParser())->parse($xml);
        self::assertStringContainsString('ATRAPA', $upo->receivingEntity);
        self::assertTrue($upo->schemaValid);
    }

    public function test_something_that_is_not_xml_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        (new UpoParser())->parse('not xml at all');
    }
}
