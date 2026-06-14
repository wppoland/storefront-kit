<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Pricing;

/**
 * Namespace-neutral quantity/volume tiered pricing engine.
 *
 * Applies the best-matching {@see PriceTier} to each cart line on
 * `woocommerce_before_calculate_totals` and renders a server-side price
 * table on the single product page. Recomputed idempotently from the
 * regular price each calculation, so it is safe across WooCommerce's
 * repeated total calculations.
 *
 * All WooCommerce/text-domain/option specifics are constructor-injected via
 * closures — exactly like {@see \WPPoland\StorefrontKit\Waitlist\WaitlistEngine}.
 * Do NOT hard-code text-domains or option keys here.
 */
final class DynamicPricingEngine
{
    /**
     * @param \Closure(): bool $isEnabled
     * @param \Closure(): list<PriceTier> $tiers
     * @param \Closure(string, array<string, mixed>): void $renderTemplate
     * @param array<string, string> $labels Localised column labels:
     *                                       `quantity`, `discount`, `price`, `heading`.
     */
    public function __construct(
        private readonly string $templateName,
        private readonly array $labels,
        private readonly \Closure $isEnabled,
        private readonly \Closure $tiers,
        private readonly \Closure $renderTemplate,
    ) {
    }

    public function registerHooks(): void
    {
        add_action('woocommerce_before_calculate_totals', [$this, 'applyTiers'], 25);
        add_action('woocommerce_single_product_summary', [$this, 'renderTable'], 25);
    }

    /**
     * Apply the best-matching tier to each cart line, based on the regular
     * price so repeated calculations stay idempotent.
     */
    public function applyTiers(\WC_Cart $cart): void
    {
        if (is_admin() && ! wp_doing_ajax()) {
            return;
        }

        if (! $this->isEnabled()) {
            return;
        }

        $tiers = $this->getTiers();

        if ($tiers === []) {
            return;
        }

        foreach ($cart->get_cart() as $item) {
            if (! is_array($item)) {
                continue;
            }

            $quantity = (int) ($item['quantity'] ?? 0);
            $product = $item['data'] ?? null;

            if ($quantity <= 0 || ! $product instanceof \WC_Product) {
                continue;
            }

            $tier = $this->bestTierFor($quantity, $tiers);

            if (! $tier instanceof PriceTier) {
                continue;
            }

            $regular = (float) $product->get_regular_price();

            if ($regular <= 0) {
                $regular = (float) $product->get_price();
            }

            if ($regular <= 0) {
                continue;
            }

            $product->set_price((string) $this->discountedPrice($regular, $tier->discountPercent));
        }
    }

    /**
     * Render the server-side price table for the current single product.
     */
    public function renderTable(): void
    {
        global $product;

        if (! $this->isEnabled() || ! $product instanceof \WC_Product) {
            return;
        }

        $tiers = $this->getTiers();

        if ($tiers === []) {
            return;
        }

        $regular = (float) $product->get_regular_price();

        if ($regular <= 0) {
            $regular = (float) $product->get_price();
        }

        if ($regular <= 0) {
            return;
        }

        ($this->renderTemplate)($this->templateName, [
            'product' => $product,
            'labels' => $this->labels,
            'rows' => $this->buildRows($regular, $tiers),
        ]);
    }

    /**
     * Build the price-table rows for a regular price, sorted by ascending
     * quantity threshold. Each row carries the raw discounted price plus a
     * WooCommerce-formatted HTML price for direct rendering.
     *
     * @param list<PriceTier> $tiers
     * @return list<array{min_quantity:int,discount_percent:float,price:float,price_html:string}>
     */
    public function buildRows(float $regularPrice, array $tiers): array
    {
        $sorted = $tiers;
        usort($sorted, static fn (PriceTier $a, PriceTier $b): int => $a->minQuantity <=> $b->minQuantity);

        $rows = [];

        foreach ($sorted as $tier) {
            $price = $this->discountedPrice($regularPrice, $tier->discountPercent);

            $rows[] = [
                'min_quantity' => $tier->minQuantity,
                'discount_percent' => $tier->discountPercent,
                'price' => $price,
                'price_html' => wc_price($price),
            ];
        }

        return $rows;
    }

    /**
     * Pick the highest-threshold tier that applies to the quantity, so larger
     * orders always get the deepest configured discount.
     *
     * @param list<PriceTier> $tiers
     */
    private function bestTierFor(int $quantity, array $tiers): ?PriceTier
    {
        $best = null;

        foreach ($tiers as $tier) {
            if (! $tier->appliesTo($quantity)) {
                continue;
            }

            if (! $best instanceof PriceTier || $tier->minQuantity > $best->minQuantity) {
                $best = $tier;
            }
        }

        return $best;
    }

    private function discountedPrice(float $regularPrice, float $discountPercent): float
    {
        // Clamp defensively: a host-supplied tier could carry a negative or
        // out-of-range percent, which would otherwise inflate or invert the price.
        $discountPercent = max(0.0, min(100.0, $discountPercent));

        return round($regularPrice * (1 - $discountPercent / 100), wc_get_price_decimals());
    }

    private function isEnabled(): bool
    {
        return (bool) ($this->isEnabled)();
    }

    /**
     * @return list<PriceTier>
     */
    private function getTiers(): array
    {
        $tiers = ($this->tiers)();

        if (! is_array($tiers)) {
            return [];
        }

        return array_values(array_filter(
            $tiers,
            static fn (mixed $tier): bool => $tier instanceof PriceTier,
        ));
    }
}
