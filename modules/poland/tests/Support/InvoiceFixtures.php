<?php

declare(strict_types=1);

namespace Poland\Tests\Support;

use DateTimeImmutable;
use Poland\Inventory\StockUnit;
use Poland\Ksef\Parsing\FaInvoiceParser;
use Poland\Ksef\Parsing\ParsedInvoice;
use Poland\Purchases\CostCategory;
use Poland\Purchases\LineResolution;
use Poland\Purchases\ProductMapping;

/** Shared builders for the stage-B suites. Framework-free, like everything they test. */
final class InvoiceFixtures
{
    public static function xml(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__).'/Fixtures/'.$name);
    }

    public static function parse(string $fixture, string $ksefNumber = '1132316939-20260811-A1B2C3D4E5F6-01'): ParsedInvoice
    {
        return (new FaInvoiceParser())->parse(
            self::xml($fixture),
            $ksefNumber,
            new DateTimeImmutable('2026-08-11 11:00:00'),
        );
    }

    public static function drinks(): ParsedInvoice
    {
        return self::parse('fa2-drinks-invoice.xml');
    }

    public static function cocaCola(bool $tracked = true, ?float $packSize = 24.0): ProductMapping
    {
        return new ProductMapping(1, 'COLA-05', 'Coca-Cola 0,5 l', StockUnit::Piece, $tracked, CostCategory::GoodsForResaleAndMaterials, $packSize, 'op');
    }

    public static function water(bool $tracked = true, ?float $packSize = 12.0): ProductMapping
    {
        return new ProductMapping(2, 'WODA-05', 'Woda 0,5 l', StockUnit::Piece, $tracked, CostCategory::GoodsForResaleAndMaterials, $packSize, 'op');
    }

    public static function onion(bool $tracked = false): ProductMapping
    {
        return new ProductMapping(3, 'CEBULA', 'Cebula', StockUnit::Kilogram, $tracked, CostCategory::GoodsForResaleAndMaterials);
    }

    /**
     * The worked example's mappings: two tracked drinks, one untracked vegetable.
     *
     * @return list<LineResolution>
     */
    public static function drinksResolutions(ParsedInvoice $invoice): array
    {
        $mappings = [1 => self::cocaCola(), 2 => self::water(), 3 => self::onion()];
        $resolutions = [];
        foreach ($invoice->lines as $line) {
            $resolutions[] = isset($mappings[$line->lineNo])
                ? new LineResolution($line, $mappings[$line->lineNo], LineResolution::SOURCE_ALIAS)
                : LineResolution::unmapped($line);
        }

        return $resolutions;
    }

    /** @return list<LineResolution> */
    public static function unmapped(ParsedInvoice $invoice): array
    {
        return array_map(static fn ($line): LineResolution => LineResolution::unmapped($line), $invoice->lines);
    }
}
