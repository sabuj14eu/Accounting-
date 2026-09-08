<?php

declare(strict_types=1);

namespace Poland\Ksef\Fa3;

/**
 * The single place that knows which FA schema this application emits.
 *
 * Values are the pinned ones (resources/ksef/PINNED.md). Changing any of them
 * is a release, and the test suite asserts the pinned XSD still agrees.
 */
final class Fa3Schema
{
    public const NAMESPACE = 'http://crd.gov.pl/wzor/2025/06/25/13775/';

    public const TYPES_NAMESPACE = 'http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2022/01/05/eD/DefinicjeTypy/';

    public const SYSTEM_CODE = 'FA (3)';

    public const SCHEMA_VERSION = '1-0E';

    public const FORM_VALUE = 'FA';

    public const VARIANT = 3;

    public const XSD_FILE = 'schemat_FA(3)_v1-0E.xsd';

    /** Maximum invoice size without attachments per `weryfikacja-faktury.md`. */
    public const MAX_BYTES = 1_000_000;

    /** @return array{systemCode: string, schemaVersion: string, value: string} */
    public static function formCode(): array
    {
        return ['systemCode' => self::SYSTEM_CODE, 'schemaVersion' => self::SCHEMA_VERSION, 'value' => self::FORM_VALUE];
    }

    public static function identifier(): string
    {
        return self::SYSTEM_CODE.' '.self::SCHEMA_VERSION;
    }

    public static function xsdDirectory(): string
    {
        return dirname(__DIR__, 3).'/resources/ksef/xsd/FA';
    }

    public static function xsdPath(): string
    {
        return self::xsdDirectory().'/'.self::XSD_FILE;
    }

    public static function upoXsdPath(): string
    {
        return dirname(__DIR__, 3).'/resources/ksef/xsd/upo/upo-v4-3.xsd';
    }

    /**
     * Map the remote schemaLocation URLs inside the pinned XSDs to the local
     * copies, and refuse everything else. libxml never touches the network.
     *
     * @return array<string,string> suffix => absolute path
     */
    public static function localSchemaMap(): array
    {
        $base = self::xsdDirectory().'/bazowe';

        return [
            'StrukturyDanych_v10-0E.xsd' => $base.'/StrukturyDanych_v10-0E.xsd',
            'ElementarneTypyDanych_v10-0E.xsd' => $base.'/ElementarneTypyDanych_v10-0E.xsd',
            'KodyKrajow_v10-0E.xsd' => $base.'/KodyKrajow_v10-0E.xsd',
        ];
    }
}
