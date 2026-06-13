<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Pricing;

/**
 * A single quantity/volume pricing tier: when a product line reaches
 * {@see $minQuantity}, a {@see $discountPercent} percentage is taken off the
 * regular line price. Tiers are namespace-neutral plain value objects — the
 * host plugin builds them from its own option storage.
 */
final class PriceTier
{
    public function __construct(
        public readonly int $minQuantity,
        public readonly float $discountPercent,
    ) {
    }

    /**
     * Build a tier from a loosely-typed settings row (e.g. a saved option),
     * normalising missing/invalid values. Returns null when the row cannot
     * form a usable tier.
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): ?self
    {
        $minQuantity = (int) ($row['min_qty'] ?? 0);
        $discountPercent = (float) ($row['discount_percent'] ?? 0);

        if ($minQuantity <= 0 || $discountPercent <= 0) {
            return null;
        }

        return new self($minQuantity, $discountPercent);
    }

    /**
     * Whether this tier applies to the given line quantity.
     */
    public function appliesTo(int $quantity): bool
    {
        return $quantity >= $this->minQuantity;
    }
}
