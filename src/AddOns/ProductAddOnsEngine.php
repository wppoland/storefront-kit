<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\AddOns;

/**
 * Namespace-neutral per-product add-ons engine (powers the Add-Ons – Product
 * Options for WooCommerce plugin).
 *
 * An admin defines a list of add-on fields per product (label, type
 * text/checkbox/select, optional per-option price delta); the engine renders
 * them under the product form, validates and captures the customer's choices
 * into the cart line item, adjusts the line price by the summed deltas, and
 * exposes the selections for cart / order display. Add-ons are stored as product
 * meta — the host owns the meta key and read/write, injected via the
 * `productMeta` closure (no custom table). Everything WooCommerce/
 * text-domain/option/meta specific is constructor-injected, mirroring
 * {@see \WPPoland\StorefrontKit\Badge\BadgeEngine} and
 * {@see \WPPoland\StorefrontKit\Pricing\DynamicPricingEngine}.
 *
 * The add-on field markup ships in the consuming plugin via the injected
 * `renderTemplate` closure.
 */
final class ProductAddOnsEngine
{
    private const SUPPORTED_TYPES = ['text', 'checkbox', 'select'];

    /**
     * @param \Closure(): bool $isEnabled
     * @param \Closure(): array<string, mixed> $settings Resolved settings.
     * @param \Closure(\WC_Product): mixed $productMeta Reads the raw add-on
     *        definition for a product (returns the stored array or null).
     * @param \Closure(string, array<string, mixed>): void $renderTemplate
     *        Echoes the add-on fields template under the product form.
     * @param string $cartKey Cart-item data key the selections are stored under.
     * @param string $fieldPrefix Request-field name prefix for posted add-ons.
     * @param array<string, string> $labels Fallback strings keyed by
     *        `required_error`, `group_title`.
     */
    public function __construct(
        private readonly string $cartKey,
        private readonly string $fieldPrefix,
        private readonly string $fieldsTemplate,
        private readonly array $labels,
        private readonly \Closure $isEnabled,
        private readonly \Closure $settings,
        private readonly \Closure $productMeta,
        private readonly \Closure $renderTemplate,
    ) {
    }

    public function registerHooks(): void
    {
        add_action('woocommerce_before_add_to_cart_button', [$this, 'renderFields'], 10);
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate'], 10, 3);
        add_filter('woocommerce_add_cart_item_data', [$this, 'captureCartItemData'], 10, 2);
        add_filter('woocommerce_get_item_data', [$this, 'displayCartItemData'], 10, 2);
        add_action('woocommerce_before_calculate_totals', [$this, 'adjustCartPrices'], 20);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'addOrderLineItemMeta'], 10, 4);
    }

    public function renderFields(): void
    {
        global $product;

        if (! $product instanceof \WC_Product || ! $this->isEnabled()) {
            return;
        }

        $addOns = $this->getAddOns($product);

        if ($addOns === []) {
            return;
        }

        ($this->renderTemplate)($this->fieldsTemplate, [
            'product' => $product,
            'add_ons' => $addOns,
            'field_prefix' => $this->fieldPrefix,
            'settings' => $this->getSettings(),
            'group_title' => $this->message('group_title'),
        ]);
    }

    /**
     * @param bool $passed
     * @param int $productId
     * @param int $quantity
     */
    public function validate($passed, $productId, $quantity): bool
    {
        if (! $this->isEnabled() || ! $passed) {
            return (bool) $passed;
        }

        $product = wc_get_product($productId);

        if (! $product instanceof \WC_Product) {
            return true;
        }

        foreach ($this->getAddOns($product) as $index => $addOn) {
            if (! $addOn['required']) {
                continue;
            }

            $value = $this->postedValue($index);

            if ($value === '') {
                wc_add_notice(
                    \WPPoland\StorefrontKit\Support\Formatter::interpolate(
                        $this->message('required_error'),
                        ['label' => $addOn['label']],
                    ),
                    'error',
                );

                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $cartItemData
     * @param int $productId
     * @return array<string, mixed>
     */
    public function captureCartItemData($cartItemData, $productId): array
    {
        if (! $this->isEnabled()) {
            return $cartItemData;
        }

        $product = wc_get_product($productId);

        if (! $product instanceof \WC_Product) {
            return $cartItemData;
        }

        $selections = [];

        foreach ($this->getAddOns($product) as $index => $addOn) {
            $value = $this->postedValue($index);

            if ($value === '') {
                continue;
            }

            $selections[] = [
                'label' => $addOn['label'],
                'value' => $value,
                'price' => $this->resolvePrice($addOn, $value),
            ];
        }

        if ($selections !== []) {
            $cartItemData[$this->cartKey] = $selections;
        }

        return $cartItemData;
    }

    /**
     * @param array<int, array{key: string, value: string}> $itemData
     * @param array<string, mixed> $cartItem
     * @return array<int, array{key: string, value: string}>
     */
    public function displayCartItemData($itemData, $cartItem): array
    {
        $selections = $this->selectionsFromCartItem($cartItem);

        foreach ($selections as $selection) {
            $value = $selection['value'];

            if ($selection['price'] > 0) {
                $value .= ' (' . wp_strip_all_tags(wc_price($selection['price'])) . ')';
            }

            $itemData[] = [
                'key' => $selection['label'],
                'value' => $value,
            ];
        }

        return $itemData;
    }

    public function adjustCartPrices(\WC_Cart $cart): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        if (did_action('woocommerce_before_calculate_totals') > 1) {
            return;
        }

        foreach ($cart->get_cart() as $cartItem) {
            if (! isset($cartItem[$this->cartKey]) || ! $cartItem['data'] instanceof \WC_Product) {
                continue;
            }

            $extra = 0.0;

            foreach ($this->selectionsFromCartItem($cartItem) as $selection) {
                $extra += $selection['price'];
            }

            if ($extra !== 0.0) {
                $base = (float) $cartItem['data']->get_price('edit');
                $cartItem['data']->set_price((string) ($base + $extra));
            }
        }
    }

    /**
     * @param \WC_Order_Item_Product $item
     * @param string $cartItemKey
     * @param array<string, mixed> $values
     * @param \WC_Order $order
     */
    public function addOrderLineItemMeta($item, $cartItemKey, $values, $order): void
    {
        foreach ($this->selectionsFromCartItem($values) as $selection) {
            $value = $selection['value'];

            if ($selection['price'] > 0) {
                $value .= ' (' . wp_strip_all_tags(wc_price($selection['price'])) . ')';
            }

            $item->add_meta_data($selection['label'], $value);
        }
    }

    /**
     * Normalise the host-stored add-on definition into a typed list.
     *
     * @return list<array{label: string, type: string, required: bool, price: float, options: array<string, float>}>
     */
    public function getAddOns(\WC_Product $product): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        $raw = ($this->productMeta)($product);

        if (! is_array($raw)) {
            return [];
        }

        $addOns = [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $label = trim((string) ($entry['label'] ?? ''));
            $type = (string) ($entry['type'] ?? 'text');

            if ($label === '' || ! in_array($type, self::SUPPORTED_TYPES, true)) {
                continue;
            }

            $options = [];

            if (isset($entry['options']) && is_array($entry['options'])) {
                foreach ($entry['options'] as $optionLabel => $optionPrice) {
                    $options[(string) $optionLabel] = (float) $optionPrice;
                }
            }

            $addOns[] = [
                'label' => $label,
                'type' => $type,
                'required' => (bool) ($entry['required'] ?? false),
                'price' => (float) ($entry['price'] ?? 0),
                'options' => $options,
            ];
        }

        return $addOns;
    }

    /**
     * @param array{label: string, type: string, required: bool, price: float, options: array<string, float>} $addOn
     */
    private function resolvePrice(array $addOn, string $value): float
    {
        if ($addOn['type'] === 'select' && isset($addOn['options'][$value])) {
            return $addOn['options'][$value];
        }

        return $addOn['price'];
    }

    private function postedValue(int $index): string
    {
        $key = $this->fieldPrefix . $index;

        if (! isset($_REQUEST[$key])) {
            return '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WooCommerce verifies the add-to-cart nonce upstream; this only reads the posted add-on value, which is sanitized here.
        return sanitize_text_field((string) wp_unslash($_REQUEST[$key]));
    }

    /**
     * @param array<string, mixed> $cartItem
     * @return list<array{label: string, value: string, price: float}>
     */
    private function selectionsFromCartItem(array $cartItem): array
    {
        if (! isset($cartItem[$this->cartKey]) || ! is_array($cartItem[$this->cartKey])) {
            return [];
        }

        $selections = [];

        foreach ($cartItem[$this->cartKey] as $selection) {
            if (! is_array($selection)) {
                continue;
            }

            $selections[] = [
                'label' => (string) ($selection['label'] ?? ''),
                'value' => (string) ($selection['value'] ?? ''),
                'price' => (float) ($selection['price'] ?? 0),
            ];
        }

        return $selections;
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
