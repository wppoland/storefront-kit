<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Checkout;

/**
 * Namespace-neutral "Buy Now" / direct-checkout engine (powers the Swift –
 * Quick Buy for WooCommerce plugin).
 *
 * Adds a "Buy Now" button on the single-product page and/or shop loop that adds
 * the product to the cart and redirects straight to the checkout (or cart). The
 * engine is stateless — no DB, no product meta — and every text-domain string,
 * option key, asset handle/URL and template name is constructor-injected via
 * plain values and closures, exactly like
 * {@see \WPPoland\StorefrontKit\Waitlist\WaitlistEngine} and
 * {@see \WPPoland\StorefrontKit\Compare\CompareEngine}. Do NOT hard-code
 * text-domains, option keys or asset handles here.
 *
 * Flow: the button is a plain submit that posts the product id under
 * `$requestKey`; on `template_redirect` the engine intercepts the request,
 * empties the cart (single-item buy-now), re-adds the product and redirects to
 * the configured target. The button markup ships in the consuming plugin via
 * the injected `renderTemplate` closure.
 */
final class DirectCheckoutEngine
{
    /**
     * @param \Closure(): bool $isEnabled
     * @param \Closure(): array<string, mixed> $settings Resolved settings:
     *        `button_text`, `show_on_single`, `show_on_loop`,
     *        `redirect_target` (`checkout`|`cart`), `clear_cart`.
     * @param \Closure(string, array<string, mixed>): void $renderTemplate
     *        Echoes the button template.
     * @param array<string, string> $labels Fallback strings keyed by
     *        `button`, `add_failed`.
     */
    public function __construct(
        private readonly string $requestKey,
        private readonly string $nonceAction,
        private readonly string $buttonTemplate,
        private readonly array $labels,
        private readonly \Closure $isEnabled,
        private readonly \Closure $settings,
        private readonly \Closure $renderTemplate,
    ) {
    }

    public function registerHooks(): void
    {
        add_action('woocommerce_after_add_to_cart_button', [$this, 'renderSingleButton'], 15);
        add_action('woocommerce_after_shop_loop_item', [$this, 'renderLoopButton'], 15);
        add_action('template_redirect', [$this, 'handleBuyNow'], 5);
    }

    public function renderSingleButton(): void
    {
        global $product;

        if (! $product instanceof \WC_Product || ! $this->isEnabled() || ! ($this->getSettings()['show_on_single'] ?? true)) {
            return;
        }

        if (! $product->is_purchasable() || ! $product->is_in_stock()) {
            return;
        }

        $this->renderButton($product, 'single');
    }

    public function renderLoopButton(): void
    {
        global $product;

        if (! $product instanceof \WC_Product || ! $this->isEnabled() || ! ($this->getSettings()['show_on_loop'] ?? false)) {
            return;
        }

        if (! $product->is_purchasable() || ! $product->is_in_stock() || $product->is_type('variable')) {
            return;
        }

        $this->renderButton($product, 'loop');
    }

    public function handleBuyNow(): void
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

        if (! $product instanceof \WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock()) {
            return;
        }

        $quantity = isset($_REQUEST['quantity']) ? max(1, absint(wp_unslash($_REQUEST['quantity']))) : 1;
        $variationId = isset($_REQUEST['variation_id']) ? absint(wp_unslash($_REQUEST['variation_id'])) : 0;

        if (! WC()->cart instanceof \WC_Cart) {
            return;
        }

        if ((bool) ($this->getSettings()['clear_cart'] ?? true)) {
            WC()->cart->empty_cart();
        }

        $added = WC()->cart->add_to_cart($productId, $quantity, $variationId);

        if ($added === false) {
            wc_add_notice($this->message('add_failed_text', 'add_failed'), 'error');

            return;
        }

        $redirectUrl = $this->getRedirectUrl();
        $redirectUrl = (string) apply_filters('wppoland_direct_checkout_redirect_url', $redirectUrl, $product, $this->requestKey);
        wp_safe_redirect($redirectUrl);
        exit;
    }

    public function getRedirectUrl(): string
    {
        $target = (string) ($this->getSettings()['redirect_target'] ?? 'checkout');

        if ($target === 'cart') {
            return wc_get_cart_url();
        }

        return wc_get_checkout_url();
    }

    /**
     * @return array{product_id: int, action_url: string, nonce_field: string, label: string}
     */
    public function getButtonData(\WC_Product $product): array
    {
        return [
            'product_id' => $product->get_id(),
            'action_url' => $this->getActionUrl($product),
            'nonce_field' => wp_create_nonce($this->nonceAction),
            'label' => $this->message('button_text', 'button'),
        ];
    }

    private function renderButton(\WC_Product $product, string $context): void
    {
        ($this->renderTemplate)($this->buttonTemplate, [
            'product' => $product,
            'context' => $context,
            'settings' => $this->getSettings(),
            'button' => $this->getButtonData($product),
            'request_key' => $this->requestKey,
        ]);
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

    /**
     * Resolve a string: prefer the settings value at `$settingsKey`, fall back
     * to the injected label at `$labelKey`.
     */
    private function message(string $settingsKey, string $labelKey): string
    {
        $value = $this->getSettings()[$settingsKey] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return $this->labels[$labelKey] ?? '';
    }
}
