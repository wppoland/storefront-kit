<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Bundle;

/**
 * Namespace-neutral "frequently bought together" bundle engine (powers the
 * Bundle – Product Bundles for WooCommerce plugin).
 *
 * An admin links N product ids to a product plus an optional bundle discount %.
 * The engine renders a bundle box on the product page, adds all linked items
 * (plus the main product) to the cart in one action, and applies the discount —
 * either as a single cart fee or as a per-item price adjustment. The bundle
 * definition is stored as product meta — the host owns the meta key and
 * read access, injected via the `productMeta` closure (no custom table).
 *
 * Everything WooCommerce / text-domain / option / meta specific is
 * constructor-injected, mirroring {@see \WPPoland\StorefrontKit\Badge\BadgeEngine}.
 * The bundle box markup ships in the consuming plugin via the injected
 * `renderTemplate` closure.
 */
final class ProductBundleEngine
{
    /**
     * Per-request memo of resolved bundle discount percent, keyed by parent
     * product id, so repeated total recalculations don't reload the product and
     * re-read its meta for every cart line.
     *
     * @var array<int, float>
     */
    private array $discountPercentCache = [];

    /**
     * @param \Closure(): bool $isEnabled
     * @param \Closure(): array<string, mixed> $settings Resolved settings:
     *        `discount_mode` (`fee`|`per_item`), `show_on_single`.
     * @param \Closure(\WC_Product): mixed $productMeta Reads the raw bundle
     *        definition for a product (returns the stored array or null).
     * @param \Closure(string, array<string, mixed>): void $renderTemplate
     *        Echoes the bundle box template under the product summary.
     * @param array<string, string> $labels Fallback strings keyed by
     *        `box_title`, `add_bundle`, `fee_label`, `add_failed`.
     */
    public function __construct(
        private readonly string $requestKey,
        private readonly string $nonceAction,
        private readonly string $cartFlag,
        private readonly string $boxTemplate,
        private readonly array $labels,
        private readonly \Closure $isEnabled,
        private readonly \Closure $settings,
        private readonly \Closure $productMeta,
        private readonly \Closure $renderTemplate,
    ) {
    }

    public function registerHooks(): void
    {
        add_action('woocommerce_after_single_product_summary', [$this, 'renderBox'], 15);
        add_action('template_redirect', [$this, 'handleAddBundle'], 5);
        add_action('woocommerce_cart_calculate_fees', [$this, 'applyBundleFee'], 20);
        add_action('woocommerce_before_calculate_totals', [$this, 'applyPerItemDiscount'], 25);
    }

    public function renderBox(): void
    {
        global $product;

        if (! $product instanceof \WC_Product || ! $this->isEnabled() || ! ($this->getSettings()['show_on_single'] ?? true)) {
            return;
        }

        $bundle = $this->getBundle($product);

        if ($bundle['items'] === []) {
            return;
        }

        ($this->renderTemplate)($this->boxTemplate, [
            'product' => $product,
            'bundle' => $bundle,
            'action_url' => $this->getActionUrl($product),
            'nonce_field' => wp_create_nonce($this->nonceAction),
            'request_key' => $this->requestKey,
            'box_title' => $this->message('box_title'),
            'add_label' => $this->message('add_bundle'),
            'settings' => $this->getSettings(),
        ]);
    }

    public function handleAddBundle(): void
    {
        if (! $this->isEnabled() || ! isset($_REQUEST[$this->requestKey])) {
            return;
        }

        $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field((string) wp_unslash($_REQUEST['_wpnonce'])) : '';

        if (! wp_verify_nonce($nonce, $this->nonceAction)) {
            return;
        }

        $productId = absint(wp_unslash($_REQUEST[$this->requestKey]));
        $product = wc_get_product($productId);

        if (! $product instanceof \WC_Product || ! WC()->cart instanceof \WC_Cart) {
            return;
        }

        $bundle = $this->getBundle($product);

        if ($bundle['items'] === []) {
            return;
        }

        $allAdded = true;

        foreach ($this->bundleProductIds($product) as $bundleProductId) {
            $linked = wc_get_product($bundleProductId);

            if (! $linked instanceof \WC_Product || ! $linked->is_purchasable() || ! $linked->is_in_stock()) {
                $allAdded = false;

                continue;
            }

            $added = WC()->cart->add_to_cart(
                $bundleProductId,
                1,
                0,
                [],
                [$this->cartFlag => $productId],
            );

            $allAdded = $allAdded && $added !== false;
        }

        if (! $allAdded) {
            wc_add_notice($this->message('add_failed'), 'error');
        }

        wp_safe_redirect(wc_get_cart_url());
        exit;
    }

    public function applyBundleFee(\WC_Cart $cart): void
    {
        if (! $this->isEnabled() || (string) ($this->getSettings()['discount_mode'] ?? 'fee') !== 'fee') {
            return;
        }

        $discount = $this->calculateBundleDiscount($cart);

        if ($discount > 0) {
            $cart->add_fee($this->message('fee_label'), -$discount);
        }
    }

    public function applyPerItemDiscount(\WC_Cart $cart): void
    {
        if (! $this->isEnabled() || (string) ($this->getSettings()['discount_mode'] ?? 'fee') !== 'per_item') {
            return;
        }

        if (did_action('woocommerce_before_calculate_totals') > 1) {
            return;
        }

        foreach ($cart->get_cart() as $cartItem) {
            $bundleParentId = $this->bundleParentId($cartItem);

            if ($bundleParentId === 0 || ! $cartItem['data'] instanceof \WC_Product) {
                continue;
            }

            $percent = $this->discountPercentFor($bundleParentId);

            if ($percent <= 0.0) {
                continue;
            }

            $base = (float) $cartItem['data']->get_price('edit');
            $cartItem['data']->set_price((string) ($base * (1 - $percent / 100)));
        }
    }

    /**
     * Normalise the host-stored bundle definition.
     *
     * @return array{items: list<int>, discount_percent: float}
     */
    public function getBundle(\WC_Product $product): array
    {
        if (! $this->isEnabled()) {
            return ['items' => [], 'discount_percent' => 0.0];
        }

        $raw = ($this->productMeta)($product);

        if (! is_array($raw)) {
            return ['items' => [], 'discount_percent' => 0.0];
        }

        $items = [];

        foreach ((array) ($raw['items'] ?? []) as $itemId) {
            $itemId = absint($itemId);

            if ($itemId > 0 && $itemId !== $product->get_id() && ! in_array($itemId, $items, true)) {
                $items[] = $itemId;
            }
        }

        return [
            'items' => $items,
            'discount_percent' => max(0.0, min(100.0, (float) ($raw['discount_percent'] ?? 0))),
        ];
    }

    /**
     * Product ids to add for the bundle: the main product plus its linked items.
     *
     * @return list<int>
     */
    public function bundleProductIds(\WC_Product $product): array
    {
        return array_values(array_unique(array_merge([$product->get_id()], $this->getBundle($product)['items'])));
    }

    private function calculateBundleDiscount(\WC_Cart $cart): float
    {
        $discount = 0.0;

        foreach ($cart->get_cart() as $cartItem) {
            $bundleParentId = $this->bundleParentId($cartItem);

            if ($bundleParentId === 0 || ! $cartItem['data'] instanceof \WC_Product) {
                continue;
            }

            $percent = $this->discountPercentFor($bundleParentId);

            if ($percent <= 0.0) {
                continue;
            }

            $lineTotal = (float) $cartItem['data']->get_price('edit') * (int) $cartItem['quantity'];
            $discount += $lineTotal * ($percent / 100);
        }

        return round($discount, wc_get_price_decimals());
    }

    private function discountPercentFor(int $parentProductId): float
    {
        if (isset($this->discountPercentCache[$parentProductId])) {
            return $this->discountPercentCache[$parentProductId];
        }

        $product = wc_get_product($parentProductId);
        $percent = $product instanceof \WC_Product ? $this->getBundle($product)['discount_percent'] : 0.0;

        return $this->discountPercentCache[$parentProductId] = $percent;
    }

    /**
     * @param array<string, mixed> $cartItem
     */
    private function bundleParentId(array $cartItem): int
    {
        return isset($cartItem[$this->cartFlag]) ? absint($cartItem[$this->cartFlag]) : 0;
    }

    private function getActionUrl(\WC_Product $product): string
    {
        return add_query_arg(
            [
                $this->requestKey => $product->get_id(),
                '_wpnonce' => wp_create_nonce($this->nonceAction),
            ],
            $product->get_permalink(),
        );
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

    private function message(string $labelKey): string
    {
        return $this->labels[$labelKey] ?? '';
    }
}
