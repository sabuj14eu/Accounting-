<?php

declare(strict_types=1);

namespace Poland\Purchases;

use Poland\Inventory\StockUnit;

/**
 * How an invoice line is understood by this shop: which product it is, in
 * what unit that product is counted, whether it is stock-tracked at all, and
 * how many stock units one invoice unit holds ("1 op. = 24 szt").
 *
 * `tracked` false is the default for every product. An untracked mapping still
 * matters: it carries the cost category.
 */
final class ProductMapping implements \JsonSerializable
{
    public function __construct(
        public readonly ?int $productId,
        public readonly string $code,
        public readonly string $name,
        public readonly StockUnit $stockUnit,
        public readonly bool $tracked,
        public readonly CostCategory $costCategory,
        /** Stock units per ONE invoice unit for this supplier's spelling; null = not known yet. */
        public readonly ?float $packSize = null,
        /** The invoice unit text the pack size refers to (as the supplier writes it). */
        public readonly ?string $packUnit = null,
    ) {
        if ($packSize !== null && $packSize <= 0) {
            throw new \InvalidArgumentException('Pack size must be positive.');
        }
    }

    public function jsonSerialize(): array
    {
        return [
            'product_id' => $this->productId,
            'code' => $this->code,
            'name' => $this->name,
            'stock_unit' => $this->stockUnit->value,
            'tracked' => $this->tracked,
            'cost_category' => $this->costCategory->value,
            'pack_size' => $this->packSize,
            'pack_unit' => $this->packUnit,
        ];
    }
}
