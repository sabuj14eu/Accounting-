<?php

declare(strict_types=1);

namespace Poland\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Poland\Domain\Money;
use Poland\Ksef\Fa3\ErpInvoiceSnapshot;
use Poland\Ksef\Fa3\Fa3Address;
use Poland\Ksef\Fa3\Fa3Buyer;
use Poland\Ksef\Fa3\Fa3BuyerIdentifier;
use Poland\Ksef\Fa3\Fa3Invoice;
use Poland\Ksef\Fa3\Fa3Line;
use Poland\Ksef\Fa3\Fa3Payment;
use Poland\Ksef\Fa3\Fa3Seller;
use Poland\Ksef\Fa3\Fa3VatRate;
use Poland\Ksef\Fa3\Fa3XmlGenerator;
use Poland\Ksef\KsefInvoiceMetadata;
use Poland\Ksef\Transport\Dto\InvoiceMetadataPage;
use Poland\Ksef\Transport\FakeKsefTransport;

/** Shared, valid test data. NIPs are the Ministry's own test NIPs from the pinned documentation. */
final class KsefFixtures
{
    public const SELLER_NIP = '5265877635';

    public const BUYER_NIP = '3861610227';

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-08 10:00:00', new DateTimeZone('Europe/Warsaw'));
    }

    public static function invoice(string $number = 'FV/2026/09/001', bool $withExempt = true): Fa3Invoice
    {
        $lines = [
            new Fa3Line(1, 'Usługa księgowa', Fa3VatRate::Rate23, Money::parse('1000.00'), Money::parse('230.00'), '1', 'szt.', '1000.00'),
        ];
        if ($withExempt) {
            $lines[] = new Fa3Line(2, 'Towar zwolniony', Fa3VatRate::Exempt, Money::parse('50.00'), Money::zero(), '2', 'szt.', '25.00');
        }

        return new Fa3Invoice(
            new Fa3Seller(self::SELLER_NIP, 'Jan Kowalski Sklep', new Fa3Address('PL', 'ul. Długa 5', '00-001 Warszawa'), 'jan@example.com'),
            new Fa3Buyer(Fa3BuyerIdentifier::nip(self::BUYER_NIP), 'ABC Sp. z o.o.', new Fa3Address('PL', 'al. Szewczyk 7247', '84-642 Żelechów')),
            'PLN',
            new DateTimeImmutable('2026-09-05'),
            $number,
            $lines,
            new DateTimeImmutable('2026-09-05'),
            'Warszawa',
            new Fa3Payment(false, null, new DateTimeImmutable('2026-09-19'), '6', 'PL61109010140000071219812874', 'mBank'),
            exemptionLegalBasis: $withExempt ? 'art. 113 ust. 1 ustawy o VAT' : null,
        );
    }

    public static function xml(string $number = 'FV/2026/09/001'): string
    {
        return (new Fa3XmlGenerator('SignalMesh Accounts'))->generate(self::invoice($number), self::now());
    }

    /** @param array<string,mixed> $overrides */
    public static function snapshot(array $overrides = []): ErpInvoiceSnapshot
    {
        $defaults = [
            'invoiceId' => 42,
            'number' => 'INV-000042',
            'issueDate' => '2026-09-05',
            'seller' => ['nip' => self::SELLER_NIP, 'name' => 'Jan Kowalski Sklep', 'country' => 'PL', 'line1' => 'ul. Długa 5', 'line2' => '00-001 Warszawa', 'email' => 'jan@example.com', 'phone' => null],
            'buyer' => ['name' => 'ABC Sp. z o.o.', 'identifier_type' => 'nip', 'identifier_value' => self::BUYER_NIP, 'identifier_country' => null, 'country' => 'PL', 'line1' => 'al. Szewczyk 7247', 'line2' => '84-642 Żelechów', 'email' => null, 'jst' => false, 'gv' => false],
            'lines' => [
                ['description' => 'Usługa księgowa', 'quantity' => 1, 'unit' => 'szt.', 'unit_price' => '1000.00', 'amount' => '1000.00', 'tax_amount' => '230.00', 'tax_rate' => 23],
                ['description' => 'Materiały', 'quantity' => 2, 'unit' => 'szt.', 'unit_price' => '100.00', 'amount' => '200.00', 'tax_amount' => '16.00', 'tax_rate' => 8.0],
            ],
            'currency' => 'PLN',
            'saleDate' => '2026-09-05',
            'dueDate' => '2026-09-19',
            'paid' => null,
            'paidOn' => null,
            'paymentForm' => '6',
            'bankAccount' => 'PL61 1090 1014 0000 0712 1981 2874',
            'bankName' => 'mBank',
            'issuePlace' => 'Warszawa',
            'exemptionLegalBasis' => null,
            'vatExemptTaxpayer' => false,
        ];
        $args = array_merge($defaults, $overrides);

        return new ErpInvoiceSnapshot(...$args);
    }

    public static function metadata(string $ksefNumber, DateTimeImmutable $storedAt, string $sellerNip = '1234567890', string $buyerNip = self::SELLER_NIP, string $type = 'Vat', ?string $xmlHash = null): KsefInvoiceMetadata
    {
        return new KsefInvoiceMetadata(
            ksefNumber: $ksefNumber,
            retrievedAt: self::now(),
            invoiceDate: $storedAt->setTime(0, 0),
            permanentStorageDate: $storedAt,
            invoiceNumber: 'FV-'.substr($ksefNumber, -6),
            sellerNip: $sellerNip,
            sellerName: 'Dostawca '.$sellerNip,
            buyerNip: $buyerNip,
            buyerName: 'Nabywca',
            net: Money::parse('100.00'),
            vat: Money::parse('23.00'),
            gross: Money::parse('123.00'),
            currency: 'PLN',
            invoiceType: $type,
            raw: ['invoiceHash' => $xmlHash, 'acquisitionDate' => $storedAt->format(DATE_ATOM), 'formCode' => ['systemCode' => 'FA (3)', 'schemaVersion' => '1-0E', 'value' => 'FA']],
        );
    }

    /** A small valid-enough FA(3) body for an incoming invoice. */
    public static function incomingXml(string $number, string $sellerNip, string $buyerNip, string $type = 'VAT'): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/"><Naglowek><KodFormularza kodSystemowy="FA (3)" wersjaSchemy="1-0E">FA</KodFormularza><WariantFormularza>3</WariantFormularza><DataWytworzeniaFa>2026-09-01T10:00:00Z</DataWytworzeniaFa></Naglowek>'
            .'<Podmiot1><DaneIdentyfikacyjne><NIP>'.$sellerNip.'</NIP><Nazwa>Dostawca</Nazwa></DaneIdentyfikacyjne><Adres><KodKraju>PL</KodKraju><AdresL1>ul. A 1</AdresL1></Adres></Podmiot1>'
            .'<Podmiot2><DaneIdentyfikacyjne><NIP>'.$buyerNip.'</NIP><Nazwa>Nabywca</Nazwa></DaneIdentyfikacyjne><JST>2</JST><GV>2</GV></Podmiot2>'
            .'<Fa><KodWaluty>PLN</KodWaluty><P_1>2026-09-01</P_1><P_2>'.$number.'</P_2><P_13_1>100.00</P_13_1><P_14_1>23.00</P_14_1><P_15>123.00</P_15>'
            .'<Adnotacje><P_16>2</P_16><P_17>2</P_17><P_18>2</P_18><P_18A>2</P_18A><Zwolnienie><P_19N>1</P_19N></Zwolnienie><NoweSrodkiTransportu><P_22N>1</P_22N></NoweSrodkiTransportu><P_23>2</P_23><PMarzy><P_PMarzyN>1</P_PMarzyN></PMarzy></Adnotacje>'
            .'<RodzajFaktury>'.$type.'</RodzajFaktury></Fa></Faktura>';
    }

    /** @param list<KsefInvoiceMetadata> $items */
    public static function page(array $items, bool $hasMore, DateTimeImmutable $hwm, int $offset = 0, bool $truncated = false): InvoiceMetadataPage
    {
        return new InvoiceMetadataPage($items, $hasMore, $truncated, $hwm, $offset, 100);
    }

    public static function ksefNumber(int $sequence, ?DateTimeImmutable $date = null, string $nip = '1234567890'): string
    {
        return FakeKsefTransport::ksefNumberFor($nip, $date ?? new DateTimeImmutable('2026-09-01'), $sequence);
    }
}
