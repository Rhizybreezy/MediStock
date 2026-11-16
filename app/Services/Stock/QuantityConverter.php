<?php
declare(strict_types=1);

namespace App\Services\Stock;

use App\Exceptions\BusinessRuleException;
use App\Repositories\ProductRepository;

/**
 * Converts a quantity entered in any of a product's units (base unit or a pack
 * such as BOX = 100 TAB) into base units, and validates whole-number rules.
 */
final class QuantityConverter
{
    public function __construct(private ProductRepository $products)
    {
    }

    /** @return array{0: float, 1: float} [base quantity, factor] */
    public function toBase(int $productId, float $quantity, ?int $unitId, string $label = 'Quantity'): array
    {
        if ($quantity <= 0) {
            throw new BusinessRuleException("$label must be greater than zero.");
        }
        $choices = $this->products->unitChoices($productId);
        if (!$choices) {
            throw new BusinessRuleException('Product not found.');
        }
        $choice = $choices[0];
        if ($unitId !== null) {
            $match = array_values(array_filter($choices, fn ($c) => $c['unit_id'] === $unitId));
            if (!$match) {
                throw new BusinessRuleException("$label: the selected unit is not configured for this product.");
            }
            $choice = $match[0];
        }
        $base = round($quantity * $choice['factor'], 3);
        $product = $this->products->find($productId);
        if ((int) $product['allows_decimal'] === 0 && abs($base - round($base)) > 1e-9) {
            throw new BusinessRuleException(sprintf('%s for %s must be a whole number of %s.', $label, $product['name'], $product['unit_code']));
        }
        return [$base, $choice['factor']];
    }
}
