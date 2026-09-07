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
    ];

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
        );
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
