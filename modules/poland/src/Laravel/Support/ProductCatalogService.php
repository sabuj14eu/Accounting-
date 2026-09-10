<?php

declare(strict_types=1);

namespace Poland\Laravel\Support;

use Illuminate\Support\Facades\DB;
use Poland\Inventory\StockQuantity;
use Poland\Inventory\StockUnit;
use Poland\Ksef\Parsing\ParsedInvoice;
use Poland\Laravel\Models\InventoryCountModel;
use Poland\Laravel\Models\KsefDocumentModel;
use Poland\Laravel\Models\ProductAliasModel;
use Poland\Laravel\Models\ProductModel;
use Poland\Laravel\Models\PurchaseInvoiceLineModel;
use Poland\Laravel\Models\TaxProfileModel;
use Poland\Purchases\CostCategory;
use Poland\Purchases\LineResolution;
use RuntimeException;

/**
 * Products, how suppliers spell them, and whether they are stock-tracked.
 *
 * A mapping is made once by the owner and found automatically afterwards
 * through the alias table. Tracking is off by default and every change of it
 * is audited with old → new.
 */
final class ProductCatalogService
{
    public function __construct(private readonly AuditRecorder $audit)
    {
    }

    /** "Coca-Cola  0,5 l  KARTON 24szt." and "coca-cola 0,5 l karton 24szt" are one spelling. */
    public static function normalise(?string $description): string
    {
        $text = mb_strtolower(trim((string) $description));
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /**
     * Resolve every line of a document to what this shop knows it to be.
     *
     * Order of authority: a mapping the owner set on THIS line, then the alias
     * for (supplier, spelling, index). Neither → unmapped, accounting only.
     *
     * @return list<LineResolution>
     */
    public function resolveLines(KsefDocumentModel $document, ParsedInvoice $parsed): array
    {
        $rows = $document->lines()->get()->keyBy('line_no');
        $sellerNip = preg_replace('/\D/', '', (string) $document->seller_nip) ?? '';

        $resolutions = [];
        foreach ($parsed->lines as $line) {
            $row = $rows->get($line->lineNo);

            if ($row !== null && $row->product_id !== null) {
                $product = ProductModel::query()->find($row->product_id);
                if ($product !== null) {
                    $alias = $this->aliasFor($document, $sellerNip, $line->description, $line->supplierIndex, (int) $product->getKey());
                    $resolutions[] = new LineResolution($line, $product->toMapping($alias), LineResolution::SOURCE_OWNER);

                    continue;
                }
            }

            $alias = $this->aliasFor($document, $sellerNip, $line->description, $line->supplierIndex);
            if ($alias !== null && $alias->product !== null && $alias->product->active) {
                $resolutions[] = new LineResolution($line, $alias->product->toMapping($alias), LineResolution::SOURCE_ALIAS);

                continue;
            }

            $resolutions[] = LineResolution::unmapped($line);
        }

        return $resolutions;
    }

    private function aliasFor(
        KsefDocumentModel $document,
        string $sellerNip,
        ?string $description,
        ?string $supplierIndex,
        ?int $productId = null,
    ): ?ProductAliasModel {
        $query = ProductAliasModel::query()
            ->with('product')
            ->where('tax_profile_id', $document->tax_profile_id)
            ->where('supplier_nip', $sellerNip)
            ->where('normalised_description', self::normalise($description))
            ->where('supplier_index', (string) ($supplierIndex ?? ''));

        if ($productId !== null) {
            $query->where('product_id', $productId);
        }

        return $query->first();
    }

    public function createProduct(
        TaxProfileModel $profile,
        string $code,
        string $name,
        StockUnit $unit,
        CostCategory $costCategory,
        ?string $category,
        bool $tracked,
        string $actor,
    ): ProductModel {
        $code = strtoupper(trim($code));
        if ($code === '' || trim($name) === '') {
            throw new RuntimeException('Produkt wymaga kodu i nazwy.');
        }

        $product = ProductModel::create([
            'tax_profile_id' => $profile->getKey(),
            'code' => $code,
            'name' => trim($name),
            'category' => $category !== null && trim($category) !== '' ? trim($category) : null,
            'stock_unit' => $unit->value,
            'inventory_tracked' => $tracked,
            'tracking_changed_at' => $tracked ? now() : null,
            'default_cost_category' => $costCategory->value,
            'active' => true,
        ]);

        $this->audit->record(
            AuditRecorder::PRODUCT_CREATED,
            (int) $profile->getKey(),
            $product,
            null,
            null,
            ['code' => $code, 'name' => $name, 'unit' => $unit->value, 'tracked' => $tracked, 'cost_category' => $costCategory->value, 'by' => $actor],
        );

        return $product;
    }

    /**
     * Turn tracking on or off. Takes effect for invoices approved AFTER the
     * change; earlier purchases are not back-filled because nobody knows what
     * was on the shelf then. An opening count may be given at the same time.
     */
    public function setTracking(
        ProductModel $product,
        bool $tracked,
        string $actor,
        ?StockQuantity $openingCount = null,
        ?\DateTimeInterface $countedOn = null,
    ): ProductModel {
        return DB::transaction(function () use ($product, $tracked, $actor, $openingCount, $countedOn): ProductModel {
            $old = (bool) $product->inventory_tracked;

            $product->forceFill([
                'inventory_tracked' => $tracked,
                'tracking_changed_at' => now(),
            ])->save();

            $count = null;
            if ($tracked && $openingCount !== null) {
                if ($openingCount->unit !== $product->stockUnit()) {
                    throw new RuntimeException('Stan początkowy musi być w jednostce produktu ('.$product->stockUnit()->label().').');
                }
                $count = InventoryCountModel::create([
                    'tax_profile_id' => $product->tax_profile_id,
                    'product_id' => $product->getKey(),
                    'counted_on' => ($countedOn ?? now())->format('Y-m-d'),
                    'quantity_thousandths' => $openingCount->thousandths,
                    'unit' => $openingCount->unit->value,
                    'is_opening' => true,
                    'book_quantity_thousandths' => null,
                    'counted_by' => $actor,
                    'note' => 'stan początkowy przy włączeniu śledzenia',
                ]);
            }

            $this->audit->record(
                AuditRecorder::INVENTORY_TRACKING_CHANGED,
                (int) $product->tax_profile_id,
                $product,
                null,
                ['inventory_tracked' => $old],
                [
                    'inventory_tracked' => $tracked,
                    'opening_count' => $openingCount?->jsonSerialize(),
                    'opening_count_id' => $count?->getKey(),
                    'by' => $actor,
                ],
            );

            return $product;
        });
    }

    /**
     * The owner says: this supplier's line IS this product, and one invoice
     * unit holds N stock units. Remembered as an alias, so the next invoice
     * from the same supplier resolves by itself.
     */
    public function mapLine(
        KsefDocumentModel $document,
        int $lineNo,
        ProductModel $product,
        ?float $packSize,
        string $actor,
    ): ProductAliasModel {
        if ($packSize !== null && $packSize <= 0) {
            throw new RuntimeException('Wielkość opakowania musi być dodatnia.');
        }

        return DB::transaction(function () use ($document, $lineNo, $product, $packSize, $actor): ProductAliasModel {
            $row = PurchaseInvoiceLineModel::query()
                ->where('ksef_document_id', $document->getKey())
                ->where('line_no', $lineNo)
                ->firstOrFail();

            $sellerNip = preg_replace('/\D/', '', (string) $document->seller_nip) ?? '';

            $alias = ProductAliasModel::updateOrCreate(
                [
                    'tax_profile_id' => $document->tax_profile_id,
                    'supplier_nip' => $sellerNip,
                    'normalised_description' => self::normalise($row->description),
                    'supplier_index' => (string) ($row->supplier_index ?? ''),
                ],
                [
                    'product_id' => $product->getKey(),
                    'pack_size' => $packSize,
                    'pack_unit' => $row->unit,
                    'created_by' => $actor,
                ],
            );

            $row->forceFill(['product_id' => $product->getKey(), 'mapping_source' => LineResolution::SOURCE_OWNER])->save();

            $this->audit->record(
                AuditRecorder::PRODUCT_MAPPED,
                (int) $document->tax_profile_id,
                $alias,
                $document->period,
                null,
                [
                    'ksef_number' => $document->ksef_number,
                    'line_no' => $lineNo,
                    'description' => $row->description,
                    'product' => $product->code,
                    'pack_size' => $packSize,
                    'pack_unit' => $row->unit,
                    'by' => $actor,
                ],
            );

            return $alias;
        });
    }
}
