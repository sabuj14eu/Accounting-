<?php

declare(strict_types=1);

namespace Poland\Ksef\Upo;

use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use Poland\Ksef\Fa3\Fa3SchemaValidator;
use RuntimeException;

/**
 * Reads a UPO against the pinned `upo-v4-3.xsd`.
 *
 * A UPO that fails the schema is still preserved verbatim — it is what KSeF
 * sent — but it is marked as such, and a caller must not treat its content
 * as an acknowledgement until somebody has looked.
 */
final class UpoParser
{
    public const NAMESPACE = 'http://upo.schematy.mf.gov.pl/KSeF/v4-3';

    public function parse(string $xml, ?DateTimeImmutable $retrievedAt = null): UpoDocument
    {
        $retrievedAt ??= new DateTimeImmutable();
        $validation = Fa3SchemaValidator::forUpo()->validate($xml, $retrievedAt);
        $schemaValid = $validation->valid;
        $schemaErrors = $validation->errors;
        // The pinned XSD fixes NazwaPodmiotuPrzyjmujacego to "Ministerstwo
        // Finansów", yet the Ministry's own TEST/DEMO acknowledgements (pinned
        // official samples) carry an environment suffix there. That one
        // deviation is tolerated and recorded; any other schema error is not.
        if (! $schemaValid && $schemaErrors !== [] && self::onlyEnvironmentNameDeviation($schemaErrors)) {
            $schemaValid = true;
            $schemaErrors = ['Uwaga: NazwaPodmiotuPrzyjmujacego zawiera dopisek środowiska (TEST/DEMO) niezgodny z wartością stałą w XSD — tolerowane, odnotowane.'];
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            throw new RuntimeException('UPO nie jest poprawnym dokumentem XML.');
        }

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('u', self::NAMESPACE);
        $text = static function (string $expression, ?\DOMNode $context = null) use ($xpath): ?string {
            $nodes = $xpath->query($expression, $context);
            if ($nodes === false || $nodes->length === 0) {
                return null;
            }
            $value = trim((string) $nodes->item(0)?->textContent);

            return $value === '' ? null : $value;
        };

        $documents = [];
        foreach ($xpath->query('//u:Dokument') ?: [] as $node) {
            $documents[] = [
                'seller_nip' => $text('u:NipSprzedawcy', $node),
                'ksef_number' => $text('u:NumerKSeFDokumentu', $node),
                'invoice_number' => $text('u:NumerFaktury', $node),
                'issue_date' => $text('u:DataWystawieniaFaktury', $node),
                'sent_at' => $text('u:DataPrzeslaniaDokumentu', $node),
                'ksef_number_assigned_at' => $text('u:DataNadaniaNumeruKSeF', $node),
                'document_hash' => $text('u:SkrotDokumentu', $node),
                'mode' => $text('u:TrybWysylki', $node),
            ];
        }

        return new UpoDocument(
            $xml,
            hash('sha256', $xml),
            $text('/u:Potwierdzenie/u:NazwaPodmiotuPrzyjmujacego') ?? '',
            $text('/u:Potwierdzenie/u:NumerReferencyjnySesji'),
            $text('/u:Potwierdzenie/u:Uwierzytelnienie/u:IdKontekstu/u:Nip'),
            $text('/u:Potwierdzenie/u:KodFormularza'),
            $text('/u:Potwierdzenie/u:NazwaStrukturyLogicznej'),
            $documents,
            $retrievedAt,
            $schemaValid,
            $schemaErrors,
        );
    }

    /** @param list<string> $errors */
    private static function onlyEnvironmentNameDeviation(array $errors): bool
    {
        foreach ($errors as $error) {
            if (! str_contains($error, 'NazwaPodmiotuPrzyjmujacego') || ! str_contains($error, 'fixed value')) {
                return false;
            }
        }

        return true;
    }
}
