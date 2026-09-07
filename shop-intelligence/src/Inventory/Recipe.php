<?php

declare(strict_types=1);

namespace Shop\Inventory;

/**
 * What one sold product is supposed to consume — §8.
 *
 * A recipe is a MODEL of the kitchen, not a record of it. Everything derived
 * from it is ESTIMATED, and the code says so by construction: theoretical
 * consumption is only ever handed back as an estimate, never as a movement.
 */
final class Recipe implements \JsonSerializable
{
    /** @param array<string, Quantity> $components ingredient code => quantity per portion */
    public function __construct(
        public readonly string $productCode,
        public readonly string $productName,
        public readonly array $components,
        public readonly ?string $version = null,
    ) {
        if ($components === []) {
            throw new \InvalidArgumentException("Recipe {$productCode} lists no ingredients.");
        }

        foreach ($components as $code => $quantity) {
            if (! is_string($code) || trim($code) === '') {
                throw new \InvalidArgumentException("Recipe {$productCode} has an unnamed ingredient.");
            }

            if (! $quantity instanceof Quantity) {
                throw new \InvalidArgumentException("Recipe {$productCode}: {$code} is not a Quantity.");
            }

            if ($quantity->isNegative()) {
                throw new \InvalidArgumentException("Recipe {$productCode}: {$code} is negative.");
            }
        }
    }

    /** @return array<string, Quantity> */
    public function forPortions(int $portions): array
    {
        if ($portions < 0) {
            throw new \InvalidArgumentException('Portions cannot be negative.');
        }

        $consumption = [];
        foreach ($this->components as $code => $quantity) {
            $consumption[$code] = $quantity->times($portions);
        }

        return $consumption;
    }

    public function jsonSerialize(): array
    {
        return [
            'product_code' => $this->productCode,
            'product_name' => $this->productName,
            'version' => $this->version,
            'components' => $this->components,
        ];
    }
}
