<?php

declare(strict_types=1);

namespace Poland\Ksef\Parsing;

use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use Poland\Domain\Money;
use Poland\Ksef\KsefInvoiceMetadata;
use RuntimeException;

/**
 * Reads a Polish structured invoice (FA) as KSeF stores it.
 *
 * WHAT THIS PARSER DOES NOT DO: it does not validate against the official XSD,
 * because the schema file is not shipped here. {@see ParsedInvoice::$validated}
 * is therefore false unless a schema was supplied, and an unvalidated document
 * is never treated as a valid one.
 *
 * The parser is deliberately namespace-agnostic and reads by LOCAL element
 * name. The FA namespace URI changes with every schema version, and a parser
 * pinned to one silently returns nothing for the next — returning nothing looks
 * exactly like "the invoice had no seller", which is the failure this avoids.
 *
 * Every field it cannot find is reported in {@see ParsedInvoice::$missing}
 * rather than defaulted. A missing net amount must not become 0,00 zł.
 */
final class FaInvoiceParser
{
    /**
     * Element local-names to try, in order, for each field. Covers FA(1), FA(2)
     * and FA(3) spellings; unknown future spellings surface as a missing field,
     * which is visible, rather than as a wrong value, which is not.
     *
     * @var array<string,list<string>>
     */
    private const PATHS = [
        'invoice_number' => ['P_2', 'P_2A', 'NrFaktury'],
        'invoice_date' => ['P_1', 'DataWytworzeniaFa', 'DataWystawienia'],
        'sale_date' => ['P_6', 'DataSprzedazy'],
        'seller_nip' => ['Podmiot1/DaneIdentyfikacyjne/NIP', 'Podmiot1/NIP', 'Sprzedawca/NIP'],
        'seller_name' => ['Podmiot1/DaneIdentyfikacyjne/Nazwa', 'Podmiot1/Nazwa', 'Sprzedawca/Nazwa'],
        'buyer_nip' => ['Podmiot2/DaneIdentyfikacyjne/NIP', 'Podmiot2/NIP', 'Nabywca/NIP'],
        'buyer_name' => ['Podmiot2/DaneIdentyfikacyjne/Nazwa', 'Podmiot2/Nazwa', 'Nabywca/Nazwa'],
        'gross' => ['P_15'],
        'currency' => ['KodWaluty'],
        'invoice_type' => ['RodzajFaktury'],
        'service_period_from' => ['OkresFa/P_6_Od'],
        'service_period_to' => ['OkresFa/P_6_Do'],
    ];

    /**
     * Local names inside `FaWiersz` (FA(2)/FA(3) spellings). Read by local
     * name like everything else here; a spelling this list does not know
     * surfaces in {@see ParsedInvoice::$unknownElements}.
     */
    private const LINE_FIELDS = [
        'line_no' => 'NrWierszaFa',
        'description' => 'P_7',
        'supplier_index' => 'Indeks',
        'gtin' => 'GTIN',
        'pkwiu' => 'PKWiU',
        'unit' => 'P_8A',
        'quantity' => 'P_8B',
        'unit_net_price' => 'P_9A',
        'net' => 'P_11',
        'gross' => 'P_11A',
        'vat' => 'P_11Vat',
        'vat_rate' => 'P_12',
        'gtu' => 'GTU',
        'procedure' => 'Procedura',
    ];

    private const PAYMENT_FIELDS = ['Zaplacono', 'DataZaplaty', 'FormaPlatnosci', 'TerminPlatnosci', 'Termin',
        'RachunekBankowy', 'NrRB', 'Platnosc'];

    private const CORRECTION_FIELDS = ['DaneFaKorygowanej', 'DataWystFaKorygowanej', 'NrFaKorygowanej',
        'NrKSeF', 'NrKSeFFaKorygowanej', 'PrzyczynaKorekty', 'TypKorekty', 'NrKSeFN'];

    /**
     * Net and VAT totals are split across rate-specific elements. Pairs of
     * (net, vat) element names, summed to totals.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const RATE_TOTALS = [
        ['P_13_1', 'P_14_1', '23%'],
        ['P_13_2', 'P_14_2', '8%'],
        ['P_13_3', 'P_14_3', '5%'],
        ['P_13_4', 'P_14_4', 'inna'],
        ['P_13_5', 'P_14_5', 'inna'],
        ['P_13_6', 'P_14_6', '0%'],
        ['P_13_7', null, 'zw'],
    ];

    public function parse(string $xml, string $ksefNumber, ?DateTimeImmutable $retrievedAt = null): ParsedInvoice
    {
        $document = new DOMDocument();

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        // LIBXML_NONET: never resolve an external entity over the network.
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $errors = array_map(
            static fn (\LibXMLError $e): string => trim($e->message).' (line '.$e->line.')',
            libxml_get_errors(),
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException(sprintf(
                'Nie udało się odczytać XML faktury %s: %s',
                $ksefNumber,
                $errors === [] ? 'nieznany błąd parsera' : implode('; ', $errors),
            ));
        }

        $xpath = new DOMXPath($document);
        $missing = [];

        $value = function (string $field) use ($xpath, &$missing): ?string {
            foreach (self::PATHS[$field] as $path) {
                $found = $this->firstByLocalPath($xpath, $path);
                if ($found !== null && $found !== '') {
                    return $found;
                }
            }
            $missing[] = $field;

            return null;
        };

        $invoiceNumber = $value('invoice_number');
        $invoiceDate = $this->date($value('invoice_date'));
        $saleDate = $this->date($value('sale_date'));
        $sellerNip = $value('seller_nip');
        $sellerName = $value('seller_name');
        $buyerNip = $value('buyer_nip');
        $buyerName = $value('buyer_name');
        $currency = $value('currency');
        $invoiceType = $value('invoice_type');
        $servicePeriodFrom = $this->date($this->firstByLocalPath($xpath, 'OkresFa/P_6_Od'));
        $servicePeriodTo = $this->date($this->firstByLocalPath($xpath, 'OkresFa/P_6_Do'));
        // Period fields are optional by nature; do not report them missing.
        $missing = array_values(array_diff($missing, ['service_period_from', 'service_period_to']));

        [$net, $vat, $byRate] = $this->totals($xpath);
        $gross = $this->money($value('gross'));

        // If the document gave no gross but did give net and VAT, deriving it is
        // arithmetic on values the document itself stated — not a guess.
        if ($gross === null && $net !== null && $vat !== null) {
            $gross = $net->plus($vat);
            $missing = array_values(array_diff($missing, ['gross']));
        }

        if ($net === null) {
            $missing[] = 'net';
        }
        if ($vat === null) {
            $missing[] = 'vat';
        }

        $metadata = new KsefInvoiceMetadata(
            ksefNumber: $ksefNumber,
            retrievedAt: $retrievedAt ?? new DateTimeImmutable(),
            invoiceDate: $invoiceDate,
            permanentStorageDate: null,
            invoiceNumber: $invoiceNumber,
            sellerNip: $sellerNip,
            sellerName: $sellerName,
            buyerNip: $buyerNip,
            buyerName: $buyerName,
            net: $net,
            vat: $vat,
            gross: $gross,
            currency: $currency,
            status: null,
            invoiceType: $invoiceType,
        );

        return new ParsedInvoice(
            metadata: $metadata,
            saleDate: $saleDate,
            netByVatRate: $byRate,
            missing: array_values(array_unique($missing)),
            validated: false,
            validationErrors: [],
            rootElement: $document->documentElement?->localName ?? 'unknown',
            namespace: $document->documentElement?->namespaceURI,
            unknownElements: $this->unknownElements($xpath),
            lines: $this->lines($xpath),
            payment: $this->payment($xpath),
            correction: $this->correction($xpath, $invoiceType),
            servicePeriodFrom: $servicePeriodFrom,
            servicePeriodTo: $servicePeriodTo,
        );
    }

    /** @return list<InvoiceLine> */
    private function lines(DOMXPath $xpath): array
    {
        $rows = $xpath->query('//*[local-name()="FaWiersz"]');
        if ($rows === false || $rows->length === 0) {
            return [];
        }

        $lines = [];
        $position = 0;
        foreach ($rows as $row) {
            $position++;
            $raw = [];
            $get = static function (string $name) use ($row, &$raw): ?string {
                foreach ($row->childNodes as $child) {
                    if ($child instanceof \DOMElement && $child->localName === $name) {
                        $text = trim((string) $child->textContent);
                        $raw[$name] = $text;

                        return $text === '' ? null : $text;
                    }
                }

                return null;
            };

            $lineNo = $get(self::LINE_FIELDS['line_no']);
            $description = $get(self::LINE_FIELDS['description']);
            $supplierIndex = $get(self::LINE_FIELDS['supplier_index']);
            $gtin = $get(self::LINE_FIELDS['gtin']);
            $pkwiu = $get(self::LINE_FIELDS['pkwiu']);
            $unit = $get(self::LINE_FIELDS['unit']);
            $quantity = $get(self::LINE_FIELDS['quantity']);
            $unitNetPrice = $this->money($get(self::LINE_FIELDS['unit_net_price']));
            $net = $this->money($get(self::LINE_FIELDS['net']));
            $grossStated = $this->money($get(self::LINE_FIELDS['gross']));
            $vatStated = $this->money($get(self::LINE_FIELDS['vat']));
            $vatRate = $get(self::LINE_FIELDS['vat_rate']);
            $gtu = $get(self::LINE_FIELDS['gtu']);
            $procedure = $get(self::LINE_FIELDS['procedure']);

            // Anything else on the row is kept, so a future schema field is
            // visible rather than lost.
            foreach ($row->childNodes as $child) {
                if ($child instanceof \DOMElement && ! array_key_exists($child->localName, $raw)) {
                    $raw[$child->localName] = trim((string) $child->textContent);
                }
            }

            $vat = $vatStated;
            $vatIsDerived = false;
            if ($vat === null && $net !== null && $vatRate !== null) {
                $vat = InvoiceLine::deriveVat($net, $vatRate);
                $vatIsDerived = $vat !== null;
            }

            $gross = $grossStated;
            $grossIsDerived = false;
            if ($gross === null && $net !== null && $vat !== null) {
                $gross = $net->plus($vat);
                $grossIsDerived = true;
            }

            $lines[] = new InvoiceLine(
                lineNo: $lineNo !== null && ctype_digit($lineNo) ? (int) $lineNo : $position,
                description: $description,
                quantity: $quantity,
                unit: $unit,
                unitNetPrice: $unitNetPrice,
                net: $net,
                vatRate: $vatRate,
                vat: $vat,
                vatIsDerived: $vatIsDerived,
                gross: $gross,
                grossIsDerived: $grossIsDerived,
                supplierIndex: $supplierIndex,
                gtin: $gtin,
                pkwiu: $pkwiu,
                gtu: $gtu,
                procedure: $procedure,
                raw: $raw,
            );
        }

        return $lines;
    }

    private function payment(DOMXPath $xpath): PaymentTerms
    {
        $node = $xpath->query('//*[local-name()="Platnosc"]');
        if ($node === false || $node->length === 0) {
            return PaymentTerms::none();
        }

        $raw = [];
        $leaves = $xpath->query('.//*[not(*)]', $node->item(0));
        foreach ($leaves ?? [] as $leaf) {
            $raw[$leaf->localName] = trim((string) $leaf->textContent);
        }

        $paid = $this->firstByLocalPath($xpath, 'Platnosc/Zaplacono');
        $paidOnInvoice = match ($paid) {
            null, '' => null,
            '1', 'true' => true,
            default => false,
        };

        return new PaymentTerms(
            dueDate: $this->date($this->firstByLocalPath($xpath, 'Platnosc/TerminPlatnosci/Termin')),
            paymentForm: $this->firstByLocalPath($xpath, 'Platnosc/FormaPlatnosci'),
            paidOnInvoice: $paidOnInvoice,
            paymentDate: $this->date($this->firstByLocalPath($xpath, 'Platnosc/DataZaplaty')),
            supplierAccount: $this->firstByLocalPath($xpath, 'Platnosc/RachunekBankowy/NrRB'),
            raw: $raw,
        );
    }

    private function correction(DOMXPath $xpath, ?string $invoiceType): ?CorrectionReference
    {
        $node = $xpath->query('//*[local-name()="DaneFaKorygowanej"]');
        $isKor = $invoiceType !== null && str_contains(strtoupper($invoiceType), 'KOR');

        if (($node === false || $node->length === 0) && ! $isKor) {
            return null;
        }

        // `NrKSeF` inside DaneFaKorygowanej is a FLAG (1 = the original was in
        // KSeF), not a number. Only NrKSeFFaKorygowanej carries the number.
        $ksef = $this->firstByLocalPath($xpath, 'DaneFaKorygowanej/NrKSeFFaKorygowanej');

        return new CorrectionReference(
            originalKsefNumber: $ksef,
            originalInvoiceNumber: $this->firstByLocalPath($xpath, 'DaneFaKorygowanej/NrFaKorygowanej'),
            originalInvoiceDate: $this->date($this->firstByLocalPath($xpath, 'DaneFaKorygowanej/DataWystFaKorygowanej')),
            reason: $this->firstByLocalPath($xpath, 'PrzyczynaKorekty'),
            type: $this->firstByLocalPath($xpath, 'TypKorekty'),
        );
    }

    /**
     * Leaf elements this parser does not map.
     *
     * The parser is tolerant of schema evolution but must not be silent about
     * it: a field a newer FA version adds shows up here, so somebody can see
     * that the mapping is behind rather than discovering it through a wrong
     * total months later.
     *
     * @return list<string>
     */
    private function unknownElements(DOMXPath $xpath): array
    {
        $known = [];
        foreach (self::PATHS as $paths) {
            foreach ($paths as $path) {
                foreach (explode('/', $path) as $segment) {
                    $known[$segment] = true;
                }
            }
        }
        foreach (self::RATE_TOTALS as [$net, $vat, $label]) {
            $known[$net] = true;
            if ($vat !== null) {
                $known[$vat] = true;
            }
        }
        foreach (self::LINE_FIELDS as $name) {
            $known[$name] = true;
        }
        foreach (array_merge(self::PAYMENT_FIELDS, self::CORRECTION_FIELDS, ['FaWiersz', 'P_6_Od', 'P_6_Do']) as $name) {
            $known[$name] = true;
        }

        $unknown = [];
        $nodes = $xpath->query('//*[not(*)]');

        foreach ($nodes ?? [] as $node) {
            $name = $node->localName;
            if ($name === null || isset($known[$name]) || isset($unknown[$name])) {
                continue;
            }
            if (trim((string) $node->textContent) === '') {
                continue;
            }
            $unknown[$name] = true;
        }

        return array_keys($unknown);
    }

    /**
     * Resolve a slash-separated path of LOCAL element names anywhere in the
     * document, ignoring namespaces.
     */
    private function firstByLocalPath(DOMXPath $xpath, string $path): ?string
    {
        $segments = explode('/', $path);
        $expression = '//*[local-name()="'.array_shift($segments).'"]';
        foreach ($segments as $segment) {
            $expression .= '/*[local-name()="'.$segment.'"]';
        }

        $nodes = $xpath->query($expression);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        return trim((string) $nodes->item(0)?->textContent);
    }

    /** @return array{0: Money|null, 1: Money|null, 2: array<string,array{net: Money, vat: Money}>} */
    private function totals(DOMXPath $xpath): array
    {
        $net = null;
        $vat = null;
        $byRate = [];

        foreach (self::RATE_TOTALS as [$netElement, $vatElement, $label]) {
            $netValue = $this->money($this->firstByLocalPath($xpath, $netElement));
            $vatValue = $vatElement !== null
                ? $this->money($this->firstByLocalPath($xpath, $vatElement))
                : Money::zero();

            if ($netValue === null) {
                continue;
            }

            $vatValue ??= Money::zero();

            $net = $net === null ? $netValue : $net->plus($netValue);
            $vat = $vat === null ? $vatValue : $vat->plus($vatValue);

            $byRate[$label] = isset($byRate[$label])
                ? ['net' => $byRate[$label]['net']->plus($netValue), 'vat' => $byRate[$label]['vat']->plus($vatValue)]
                : ['net' => $netValue, 'vat' => $vatValue];
        }

        return [$net, $vat, $byRate];
    }

    private function money(?string $value): ?Money
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Money::parse(trim($value));
        } catch (\InvalidArgumentException) {
            // An unreadable amount is not a zero amount.
            return null;
        }
    }

    private function date(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        foreach (['Y-m-d', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i:sP', DATE_ATOM] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, trim($value));
            if ($parsed !== false) {
                return $parsed;
            }
        }

        try {
            return new DateTimeImmutable(trim($value));
        } catch (\Exception) {
            return null;
        }
    }
}
