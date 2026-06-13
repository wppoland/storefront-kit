<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Badge;

/**
 * Namespace-neutral product badge engine for merchandising / conversion hints.
 *
 * Decides which CSS-class-based badges apply to a {@see \WC_Product} — manual
 * (product meta) plus automatic rules (sale, new, low-stock, bestseller,
 * discount-percent, free-shipping, out-of-stock) — de-duplicates and caps them
 * per render context, then renders CSS-only markup through an injected
 * `renderTemplate` closure. No JavaScript.
 *
 * All WooCommerce/text-domain/option/meta specifics are constructor-injected
 * via closures and arrays — exactly like
 * {@see \WPPoland\StorefrontKit\Waitlist\WaitlistEngine} and
 * {@see \WPPoland\StorefrontKit\Pricing\DynamicPricingEngine}. Do NOT
 * hard-code text-domains, option keys or meta keys here.
 */
final class BadgeEngine
{
    /**
     * @param \Closure(): bool $isEnabled
     * @param \Closure(): array<string, mixed> $settings Resolved badge settings:
     *        per-rule `show_*` booleans, `*_badge_text` labels, thresholds
     *        (`newness_days`, `low_stock_threshold`, `bestseller_threshold`),
     *        `max_badges_single` / `max_badges_loop`, `show_on_single`,
     *        `show_on_loop`, `free_shipping_classes`, plus render hints
     *        (`shape`, `uppercase`).
     * @param \Closure(\WC_Product, string): mixed $productMeta Reads a product
     *        meta value by key — keeps meta-key naming in the host plugin.
     * @param \Closure(string, array<string, mixed>): void $renderTemplate
     * @param array<string, string> $labels Fallback badge labels keyed by rule
     *        (`sale`, `new`, `low_stock`, `bestseller`, `free_shipping`,
     *        `out_of_stock`) used when a settings label is absent.
     * @param array<string, string> $metaKeys Product meta keys for manual
     *        badges (`manual_text`, `manual_style`, `secondary_text`).
     */
    public function __construct(
        private readonly string $templateName,
        private readonly array $labels,
        private readonly array $metaKeys,
        private readonly \Closure $isEnabled,
        private readonly \Closure $settings,
        private readonly \Closure $productMeta,
        private readonly \Closure $renderTemplate,
    ) {
    }

    public function registerHooks(): void
    {
        add_action('woocommerce_before_single_product_summary', [$this, 'renderSingleBadges'], 6);
        add_action('woocommerce_before_shop_loop_item_title', [$this, 'renderLoopBadges'], 9);
    }

    public function renderSingleBadges(): void
    {
        global $product;

        if (! $product instanceof \WC_Product || ! ($this->getSettings()['show_on_single'] ?? true)) {
            return;
        }

        $this->render($product, 'single');
    }

    public function renderLoopBadges(): void
    {
        global $product;

        if (! $product instanceof \WC_Product || ! ($this->getSettings()['show_on_loop'] ?? true)) {
            return;
        }

        $this->render($product, 'loop');
    }

    /**
     * Resolve the badges that apply to a product, in priority order
     * (manual → secondary → automatic rules), de-duplicated and capped for the
     * given render context.
     *
     * @return list<Badge>
     */
    public function getBadges(\WC_Product $product, string $context = 'single'): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        $settings = $this->getSettings();
        $badges = [];

        $manual = trim((string) $this->meta($product, 'manual_text'));
        $manualStyle = sanitize_key((string) $this->meta($product, 'manual_style'));
        $secondary = trim((string) $this->meta($product, 'secondary_text'));

        if ((bool) ($settings['show_manual_badge'] ?? true) && $manual !== '') {
            $badges[] = new Badge($manual, $manualStyle !== '' ? $manualStyle : (string) ($settings['manual_badge_style'] ?? 'accent'));
        }

        if ((bool) ($settings['show_secondary_badge'] ?? true) && $secondary !== '') {
            $badges[] = new Badge($secondary, (string) ($settings['secondary_badge_style'] ?? 'neutral'));
        }

        if ((bool) ($settings['show_sale_badge'] ?? true) && $product->is_on_sale()) {
            $badges[] = new Badge($this->label('sale', $settings, 'sale_badge_text'), 'warning');
        }

        if ((bool) ($settings['show_new_badge'] ?? true) && $this->isNew($product)) {
            $badges[] = new Badge($this->label('new', $settings, 'new_badge_text'), 'success');
        }

        if ((bool) ($settings['show_low_stock_badge'] ?? true) && $this->isLowStock($product)) {
            $badges[] = new Badge($this->label('low_stock', $settings, 'low_stock_badge_text'), 'warning');
        }

        if ((bool) ($settings['show_bestseller_badge'] ?? true) && $this->isBestseller($product)) {
            $badges[] = new Badge($this->label('bestseller', $settings, 'bestseller_badge_text'), 'accent');
        }

        if ((bool) ($settings['show_discount_percent_badge'] ?? false) && $product->is_on_sale()) {
            $discount = $this->getDiscountPercent($product);

            if ($discount > 0) {
                $badges[] = new Badge('-' . $discount . '%', 'danger');
            }
        }

        if ((bool) ($settings['show_free_shipping_badge'] ?? false) && $this->hasFreeShipping($product, $settings)) {
            $badges[] = new Badge($this->label('free_shipping', $settings, 'free_shipping_badge_text'), 'success');
        }

        if ((bool) ($settings['show_out_of_stock_badge'] ?? true) && ! $product->is_in_stock()) {
            $badges[] = new Badge($this->label('out_of_stock', $settings, 'out_of_stock_badge_text'), 'neutral');
        }

        return array_slice($this->dedupe($badges), 0, $this->badgeLimit($context, $settings));
    }

    private function render(\WC_Product $product, string $context): void
    {
        $badges = $this->getBadges($product, $context);

        if ($badges === []) {
            return;
        }

        $settings = $this->getSettings();

        ($this->renderTemplate)($this->templateName, [
            'badges' => $badges,
            'context' => $context,
            'product' => $product,
            'shape' => (string) ($settings['shape'] ?? 'pill'),
            'uppercase' => (bool) ($settings['uppercase'] ?? false),
        ]);
    }

    /**
     * @param list<Badge> $badges
     * @return list<Badge>
     */
    private function dedupe(array $badges): array
    {
        $unique = [];

        foreach ($badges as $badge) {
            $unique[$badge->dedupeKey()] = $badge;
        }

        return array_values($unique);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function badgeLimit(string $context, array $settings): int
    {
        if ($context === 'single') {
            return max(1, (int) ($settings['max_badges_single'] ?? 4));
        }

        return max(1, (int) ($settings['max_badges_loop'] ?? 3));
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function label(string $rule, array $settings, string $settingKey): string
    {
        $configured = isset($settings[$settingKey]) ? (string) $settings[$settingKey] : '';

        return $configured !== '' ? $configured : ($this->labels[$rule] ?? '');
    }

    private function isNew(\WC_Product $product): bool
    {
        $days = max(1, (int) ($this->getSettings()['newness_days'] ?? 30));
        $created = $product->get_date_created();

        if (! $created instanceof \WC_DateTime) {
            return false;
        }

        return $created->getTimestamp() >= strtotime('-' . $days . ' days');
    }

    private function isLowStock(\WC_Product $product): bool
    {
        if (! $product->managing_stock()) {
            return false;
        }

        $quantity = $product->get_stock_quantity();
        $threshold = max(1, (int) ($this->getSettings()['low_stock_threshold'] ?? 3));

        return $quantity !== null && $quantity > 0 && $quantity <= $threshold;
    }

    private function isBestseller(\WC_Product $product): bool
    {
        $threshold = max(1, (int) ($this->getSettings()['bestseller_threshold'] ?? 25));

        return (int) $product->get_total_sales() >= $threshold;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function hasFreeShipping(\WC_Product $product, array $settings): bool
    {
        $configured = (string) ($settings['free_shipping_classes'] ?? 'free-shipping');
        $freeShippingClasses = array_filter(array_map('trim', explode(',', $configured)));

        return in_array($product->get_shipping_class(), $freeShippingClasses, true);
    }

    private function getDiscountPercent(\WC_Product $product): int
    {
        $regular = (float) $product->get_regular_price();
        $sale = (float) $product->get_sale_price();

        if ($regular <= 0 || $sale <= 0 || $sale >= $regular) {
            return 0;
        }

        return (int) round((($regular - $sale) / $regular) * 100);
    }

    private function meta(\WC_Product $product, string $key): mixed
    {
        return ($this->productMeta)($product, $this->metaKeys[$key] ?? $key);
    }

    private function isEnabled(): bool
    {
        return (bool) ($this->isEnabled)();
    }

    /**
     * @return array<string, mixed>
     */
    private function getSettings(): array
    {
        $settings = ($this->settings)();

        return is_array($settings) ? $settings : [];
    }
}
