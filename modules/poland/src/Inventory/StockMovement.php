<?php

declare(strict_types=1);

namespace Poland\Inventory;

/**
 * One planned or recorded change of a tracked product's quantity.
 *
 * Framework-free so a posting plan can be built and shown to the owner before
 * anything is written. The database row that persists it carries the
 * (source_type, source_id, source_line_no) key that makes one invoice line
 * produce one movement, ever.
 */
final class StockMovement implements \JsonSerializable
{
    public function __construct(
        public readonly string $productCode,
        public readonly string $productName,
        public readonly StockQuantity $quantity,
        public readonly MovementType $type,
        public readonly ?int $sourceLineNo = null,
        public readonly ?string $note = null,
    ) {
    }

    public function negated(): self
    {
        return new self(
            $this->productCode,
            $this->productName,
            $this->quantity->negated(),
            MovementType::Reversal,
            $this->sourceLineNo,
            'odwrócenie: '.($this->note ?? $this->type->label()),
        );
    }

    public function describe(): string
    {
        $sign = $this->quantity->isNegative() ? '' : '+';

        return sprintf('%s %s%s', $this->productName, $sign, $this->quantity->format());
    }

    public function jsonSerialize(): array
    {
        return [
            'product_code' => $this->productCode,
            'product_name' => $this->productName,
            'quantity' => $this->quantity,
            'type' => $this->type->value,
            'source_line_no' => $this->sourceLineNo,
            'note' => $this->note,
        ];
    }
}
