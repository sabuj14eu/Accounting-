<?php

declare(strict_types=1);

namespace Poland\Ksef\Fa3;

use DateTimeImmutable;
use DOMDocument;

/**
 * Validates a document against the pinned official FA(3) XSD, offline, with
 * the parser locked down.
 *
 * Order of checks mirrors `weryfikacja-faktury.md`: size, encoding (UTF-8, no
 * BOM), no processing instructions, no forbidden Unicode characters,
 * well-formedness with entity resolution disabled, the expected root and
 * namespace, then the schema. A DOCTYPE is refused outright — no XML this
 * application accepts has any business declaring one, and it is the vector
 * for entity expansion attacks.
 *
 * The XSD imports its base types by an http:// URL. Those are mapped to the
 * pinned local files; any other external reference resolves to nothing.
 */
final class Fa3SchemaValidator
{
    public const FORBIDDEN_CHARS = '/[\x{7F}-\x{84}\x{86}-\x{9F}\x{FDD0}-\x{FDEF}\x{1FFFE}\x{1FFFF}\x{2FFFE}\x{2FFFF}\x{3FFFE}\x{3FFFF}\x{4FFFE}\x{4FFFF}\x{5FFFE}\x{5FFFF}\x{6FFFE}\x{6FFFF}\x{7FFFE}\x{7FFFF}\x{8FFFE}\x{8FFFF}\x{9FFFE}\x{9FFFF}\x{AFFFE}\x{AFFFF}\x{BFFFE}\x{BFFFF}\x{CFFFE}\x{CFFFF}\x{DFFFE}\x{DFFFF}\x{EFFFE}\x{EFFFF}\x{FFFFE}\x{FFFFF}\x{10FFFE}\x{10FFFF}]/u';

    public function __construct(
        private readonly string $xsdPath = '',
        private readonly array $schemaMap = [],
        private readonly string $expectedRoot = 'Faktura',
        private readonly string $expectedNamespace = Fa3Schema::NAMESPACE,
        private readonly int $maxBytes = Fa3Schema::MAX_BYTES,
    ) {
    }

    public static function forFa3(): self
    {
        return new self(Fa3Schema::xsdPath(), Fa3Schema::localSchemaMap());
    }

    public static function forUpo(): self
    {
        return new self(Fa3Schema::upoXsdPath(), [], 'Potwierdzenie', 'http://upo.schematy.mf.gov.pl/KSeF/v4-3', 20_000_000);
    }

    public function validate(string $xml, ?DateTimeImmutable $now = null): Fa3ValidationResult
    {
        $now ??= new DateTimeImmutable();
        $size = strlen($xml);
        $schema = basename($this->xsdPath);
        $fail = fn (string $stage, array $errors): Fa3ValidationResult => new Fa3ValidationResult(false, $errors, $schema, $size, $now, $stage);

        if ($size === 0) {
            return $fail('size', ['Dokument jest pusty.']);
        }
        if ($size > $this->maxBytes) {
            return $fail('size', [sprintf('Dokument ma %d bajtów; limit to %d.', $size, $this->maxBytes)]);
        }
        if (str_starts_with($xml, "\xEF\xBB\xBF")) {
            return $fail('encoding', ['Dokument zaczyna się znacznikiem BOM — KSeF wymaga UTF-8 bez BOM.']);
        }
        if (! mb_check_encoding($xml, 'UTF-8')) {
            return $fail('encoding', ['Dokument nie jest poprawnym UTF-8.']);
        }
        if (preg_match('/<\?xml[^>]*encoding\s*=\s*["\'](?!utf-8["\'])/i', $xml) === 1) {
            return $fail('encoding', ['Prolog XML deklaruje kodowanie inne niż UTF-8.']);
        }
        if (preg_match('/<\?(?!xml[\s?])/i', $xml) === 1) {
            return $fail('structure', ['Dokument zawiera instrukcję przetwarzania (processing instruction), której KSeF nie dopuszcza.']);
        }
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            return $fail('structure', ['Dokument zawiera deklarację DOCTYPE/ENTITY — odrzucone ze względów bezpieczeństwa.']);
        }
        if (preg_match(self::FORBIDDEN_CHARS, $xml) === 1) {
            return $fail('characters', ['Dokument zawiera znaki Unicode niedopuszczane przez specyfikację XML W3C (§2.2), których KSeF nie przyjmuje.']);
        }
        if (! is_file($this->xsdPath)) {
            return $fail('schema', ['Brak pliku XSD: '.$this->xsdPath.' — walidacja NIEMOŻLIWA, dokument nie jest uznany za poprawny.']);
        }

        $previousErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        // The function returns a bool on PHP < 8.5, so the default loader is
        // restored explicitly afterwards rather than "the previous one".
        libxml_set_external_entity_loader($this->entityLoader());

        try {
            $doc = new DOMDocument();
            $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NOCDATA);
            if (! $loaded) {
                return $fail('well-formed', $this->collect('Dokument nie jest poprawnym XML'));
            }
            $root = $doc->documentElement;
            if ($root === null || $root->localName !== $this->expectedRoot || $root->namespaceURI !== $this->expectedNamespace) {
                return $fail('root', [sprintf(
                    'Element główny to {%s}%s, oczekiwano {%s}%s.',
                    (string) ($root?->namespaceURI ?? ''),
                    (string) ($root?->localName ?? ''),
                    $this->expectedNamespace,
                    $this->expectedRoot,
                )]);
            }
            $valid = $doc->schemaValidate($this->xsdPath, LIBXML_NONET);
            if (! $valid) {
                return $fail('xsd', $this->collect('Dokument nie jest zgodny ze schematem'));
            }

            return new Fa3ValidationResult(true, [], $schema, $size, $now, 'xsd');
        } finally {
            libxml_set_external_entity_loader(null);
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    private function entityLoader(): \Closure
    {
        $map = $this->schemaMap;
        $xsdDir = dirname($this->xsdPath);

        return static function (?string $public, ?string $system, array $context) use ($map, $xsdDir): ?string {
            if ($system === null) {
                return null;
            }
            foreach ($map as $suffix => $path) {
                if (str_ends_with($system, $suffix)) {
                    return $path;
                }
            }
            // The XSD itself and files inside the pinned directory.
            $real = realpath($system) ?: realpath($xsdDir.'/'.basename($system));
            if ($real !== false && str_starts_with($real, realpath($xsdDir) ?: "\0")) {
                return $real;
            }

            // Anything else — an http:// URL, a DTD, a file outside the pinned
            // tree — is refused. Refusing yields a validation error, never a
            // network request.
            return null;
        };
    }

    /** @return list<string> */
    private function collect(string $prefix): array
    {
        $errors = [];
        foreach (libxml_get_errors() as $error) {
            $errors[] = sprintf('%s (linia %d): %s', $prefix, $error->line, trim($error->message));
        }
        libxml_clear_errors();

        return $errors === [] ? [$prefix.'.'] : array_values(array_unique($errors));
    }
}
