<?php

declare(strict_types=1);

namespace Poland\Laravel\Support;

use Illuminate\Support\Facades\DB;
use Poland\Inventory\MovementType;
use Poland\Inventory\StockPosition;
use Poland\Inventory\StockQuantity;
use Poland\Laravel\Models\InventoryCountModel;
use Poland\Laravel\Models\InventoryMovementModel;
use Poland\Laravel\Models\ProductModel;
use RuntimeException;

/**
 * What can honestly be said about a tracked product's stock, and how a count
 * is recorded. Nothing here decrements stock per sale: sales are monthly
 * totals, and consumption is only ever implied by a count.
 */
final class InventoryService
{
    public function __construct(private readonly AuditRecorder $audit)
    {
    }

    public function position(ProductModel $product): StockPosition
    {
        $unit = $product->stockUnit();

        $opening = InventoryCountModel::query()
            ->where('product_id', $product->getKey())
            ->where('is_opening', true)
            ->orderByDesc('counted_on')->orderByDesc('id')
            ->first();

        $purchasesQuery = InventoryMovementModel::query()
            ->where('product_id', $product->getKey())
            ->whereIn('movement_type', [MovementType::Purchase->value, MovementType::Reversal->value, MovementType::ManualAdjustment->value]);

        $latestCount = null;
        if ($opening !== null) {
            $purchasesQuery->where('occurred_on', '>=', $opening->counted_on->format('Y-m-d'));
            $latestCount = InventoryCountModel::query()
                ->where('product_id', $product->getKey())
                ->where('is_opening', false)
                ->where('counted_on', '>=', $opening->counted_on->format('Y-m-d'))
                ->orderByDesc('counted_on')->orderByDesc('id')
                ->first();
        }

        $purchases = StockQuantity::zero($unit);
        foreach ($purchasesQuery->get() as $movement) {
            $purchases = $purchases->plus(StockQuantity::of((int) $movement->quantity_thousandths / 1000, $unit));
        }

        return new StockPosition(
            (string) $product->name,
            $unit,
            $opening !== null ? StockQuantity::of((int) $opening->quantity_thousandths / 1000, $unit) : null,
            $purchases,
            $latestCount !== null ? StockQuantity::of((int) $latestCount->quantity_thousandths / 1000, $unit) : null,
            $latestCount !== null ? \DateTimeImmutable::createFromInterface($latestCount->counted_on) : null,
        );
    }

    /** Record a physical count. Compared to the book at that moment; the book quantity is stored so the arithmetic replays. */
    public function recordCount(
        ProductModel $product,
        StockQuantity $quantity,
        \DateTimeInterface $countedOn,
        string $actor,
        ?string $note = null,
        bool $isOpening = false,
    ): InventoryCountModel {
        if (! $product->inventory_tracked) {
            throw new RuntimeException(sprintf('Produkt %s nie jest śledzony — najpierw włącz śledzenie.', $product->name));
        }
        if ($quantity->unit !== $product->stockUnit()) {
            throw new RuntimeException('Ilość musi być w jednostce produktu ('.$product->stockUnit()->label().').');
        }
        if ($quantity->isNegative()) {
            throw new RuntimeException('Stan policzony nie może być ujemny.');
        }

        return DB::transaction(function () use ($product, $quantity, $countedOn, $actor, $note, $isOpening): InventoryCountModel {
            $book = $isOpening ? null : $this->position($product)->bookQuantity();

            $count = InventoryCountModel::create([
                'tax_profile_id' => $product->tax_profile_id,
                'product_id' => $product->getKey(),
                'counted_on' => $countedOn->format('Y-m-d'),
                'quantity_thousandths' => $quantity->thousandths,
                'unit' => $quantity->unit->value,
                'is_opening' => $isOpening,
                'book_quantity_thousandths' => $book?->thousandths,
                'counted_by' => $actor,
                'note' => $note,
            ]);

            $this->audit->record(
                AuditRecorder::INVENTORY_COUNT_RECORDED,
                (int) $product->tax_profile_id,
                $count,
                null,
                $book !== null ? ['book_quantity' => $book->jsonSerialize()] : null,
                ['product' => $product->code, 'counted' => $quantity->jsonSerialize(), 'is_opening' => $isOpening, 'by' => $actor, 'note' => $note],
            );

            return $count;
        });
    }
}
