<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Compare;

use WPPoland\StorefrontKit\Support\Formatter;

/**
 * Namespace-neutral product comparison engine for guests and customers.
 *
 * Mirrors {@see \WPPoland\StorefrontKit\Waitlist\WaitlistEngine}: every
 * text-domain string, option key, asset handle/URL and template name is
 * constructor-injected via closures and arrays, so nothing is hard-coded here.
 * The engine owns the guest cookie + user-id ownership resolution, the
 * add/remove and clear AJAX handlers, the loop/single compare buttons, and the
 * comparison-table builder with difference highlighting. Storage is delegated to
 * a host-supplied {@see CompareRepository}; the table/button markup ships in the
 * consuming plugin via the injected `renderTemplate` closure.
 *
 * Standard WooCommerce comparison fields (`price`, `sku`, `availability`,
 * `description`) and product attributes are computed by the engine. Any other
 * field key listed in `$comparisonFields` is delegated to the injected
 * `fieldResolver` closure, so store-specific rows (unit price, delivery time,
 * brand, manufacturer, GTIN, …) stay in the consuming plugin.
 */
final class CompareEngine
{
    /**
     * @param array<string, string> $comparisonFields Ordered map of field key
     *        => row label. Standard keys (`price`, `sku`, `availability`,
     *        `description`) are resolved internally; others go to `fieldResolver`.
     * @param array<string, string> $labels Fallback strings keyed by
     *        `add`, `remove`, `account`, `feature`, `login_required`,
     *        `not_found`, `clear_error`, `limit_notice` (the last supports a
     *        `{limit}` token).
     * @param \Closure(): bool $isEnabled
     * @param \Closure(): array<string, mixed> $settings Resolved settings array.
     * @param \Closure(string, array<string, mixed>): void $renderTemplate
     *        Echoes a template (loop / single button).
     * @param \Closure(string, array<string, mixed>): string $renderTable
     *        Returns the comparison-table HTML for the account page / shortcode.
     * @param \Closure(\WC_Product, string): array{0: string, 1: string} $fieldResolver
     *        Returns `[html, text]` for a non-standard field key.
     */
    public function __construct(
        private readonly CompareRepository $repository,
        private readonly string $ajaxAction,
        private readonly string $clearAjaxAction,
        private readonly string $nonceAction,
        private readonly string $scriptObjectName,
        private readonly string $assetHandle,
        private readonly string $styleUrl,
        private readonly string $scriptUrl,
        private readonly string $version,
        private readonly string $endpoint,
        private readonly string $guestCookie,
        private readonly string $loopButtonTemplate,
        private readonly string $singleButtonTemplate,
        private readonly string $tableTemplate,
        private readonly array $comparisonFields,
        private readonly array $labels,
        private readonly \Closure $isEnabled,
        private readonly \Closure $settings,
        private readonly \Closure $renderTemplate,
        private readonly \Closure $renderTable,
        private readonly \Closure $fieldResolver,
    ) {
    }

    public function registerHooks(): void
    {
        add_action('init', [$this, 'registerEndpoint']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('woocommerce_single_product_summary', [$this, 'renderSingleButton'], 34);
        add_action('woocommerce_after_shop_loop_item', [$this, 'renderLoopButton'], 20);
        add_action('wp_ajax_' . $this->ajaxAction, [$this, 'handleToggle']);
        add_action('wp_ajax_nopriv_' . $this->ajaxAction, [$this, 'handleToggle']);
        add_action('wp_ajax_' . $this->clearAjaxAction, [$this, 'handleClear']);
        add_action('wp_ajax_nopriv_' . $this->clearAjaxAction, [$this, 'handleClear']);
        add_filter('woocommerce_account_menu_items', [$this, 'addAccountMenuItem']);
        add_action('woocommerce_account_' . $this->endpoint . '_endpoint', [$this, 'renderAccountPage']);
        add_action('wp_login', [$this, 'transferGuestToUser'], 10, 2);
    }

    public function registerEndpoint(): void
    {
        add_rewrite_endpoint($this->endpoint, EP_ROOT | EP_PAGES);
    }

    public function enqueueAssets(): void
    {
        if (! $this->isEnabled() || ! $this->shouldEnqueueAssets()) {
            return;
        }

        wp_enqueue_style($this->assetHandle, $this->styleUrl, [], $this->version);
        wp_enqueue_script($this->assetHandle, $this->scriptUrl, [], $this->version, [
            'in_footer' => true,
            'strategy' => 'defer',
        ]);

        wp_localize_script($this->assetHandle, $this->scriptObjectName, [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => $this->ajaxAction,
            'clearAction' => $this->clearAjaxAction,
            'nonce' => wp_create_nonce($this->nonceAction),
            'loginUrl' => wc_get_page_permalink('myaccount'),
            'compareUrl' => $this->getCompareUrl(),
            'allowGuests' => (bool) ($this->getSettings()['allow_guests'] ?? true),
            'showOnlyDifferences' => (bool) ($this->getSettings()['show_only_differences'] ?? false),
        ]);
    }

    public function renderSingleButton(): void
    {
        global $product;

        if (! $product instanceof \WC_Product || ! ($this->getSettings()['show_on_single'] ?? true) || ! $this->canUse()) {
            return;
        }

        ($this->renderTemplate)($this->singleButtonTemplate, [
            'product' => $product,
            'settings' => $this->getSettings(),
            'button' => $this->getButtonData($product),
        ]);
    }

    public function renderLoopButton(): void
    {
        global $product;

        if (! $product instanceof \WC_Product || ! ($this->getSettings()['show_on_loop'] ?? true) || ! $this->canUse()) {
            return;
        }

        ($this->renderTemplate)($this->loopButtonTemplate, [
            'product' => $product,
            'settings' => $this->getSettings(),
            'button' => $this->getButtonData($product),
        ]);
    }

    public function handleToggle(): void
    {
        check_ajax_referer($this->nonceAction, 'nonce');

        if (! $this->canUse()) {
            wp_send_json_error(['message' => $this->message('login_required_text', 'login_required')], 403);
        }

        $productId = isset($_POST['product_id']) ? absint(wp_unslash($_POST['product_id'])) : 0;
        $product = wc_get_product($productId);

        if (! $product instanceof \WC_Product) {
            wp_send_json_error(['message' => $this->message('product_not_found_text', 'not_found')], 404);
        }

        [$userId, $sessionId] = $this->context(true);

        if ($this->repository->exists($productId, $userId, $sessionId)) {
            $this->repository->remove($productId, $userId, $sessionId);

            wp_send_json_success([
                'in_compare' => false,
                'count' => $this->getCount(),
                'button_text' => $this->message('button_add_text', 'add'),
                'compare_url' => $this->getCompareUrl(),
            ]);
        }

        $limit = $this->getMaxItems();
        $wasTrimmed = false;

        if ($this->repository->count($userId, $sessionId) >= $limit) {
            $this->repository->removeOldest($userId, $sessionId);
            $wasTrimmed = true;
        }

        $this->repository->add($productId, $userId, $sessionId);

        $response = [
            'in_compare' => true,
            'count' => $this->getCount(),
            'button_text' => $this->message('button_remove_text', 'remove'),
            'compare_url' => $this->getCompareUrl(),
        ];

        if ($wasTrimmed) {
            $response['message'] = $this->getLimitNoticeText($limit);
        }

        wp_send_json_success($response);
    }

    public function handleClear(): void
    {
        check_ajax_referer($this->nonceAction, 'nonce');

        if (! $this->canUse()) {
            wp_send_json_error(['message' => $this->message('clear_error_text', 'clear_error')], 403);
        }

        [$userId, $sessionId] = $this->context(true);
        $this->repository->clear($userId, $sessionId);

        wp_send_json_success([
            'count' => 0,
            'compare_url' => $this->getCompareUrl(),
        ]);
    }

    /**
     * @param array<string, string> $items
     * @return array<string, string>
     */
    public function addAccountMenuItem(array $items): array
    {
        if (! $this->isEnabled() || ! ($this->getSettings()['show_in_account'] ?? true)) {
            return $items;
        }

        $logout = $items['customer-logout'] ?? null;
        unset($items['customer-logout']);

        $items[$this->endpoint] = $this->message('account_label', 'account');

        if ($logout !== null) {
            $items['customer-logout'] = $logout;
        }

        return $items;
    }

    public function renderAccountPage(): void
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped by the host template.
        echo $this->renderCompareTable();
    }

    public function renderCompareTable(): string
    {
        $products = $this->getProducts();
        $rows = $this->buildRows($products);

        return ($this->renderTable)($this->tableTemplate, [
            'products' => $products,
            'rows' => $rows,
            'differences' => $this->calculateDifferences($rows),
            'settings' => $this->getSettings(),
            'feature_label' => $this->message('feature_label', 'feature'),
        ]);
    }

    public function transferGuestToUser(string $userLogin, \WP_User $user): void
    {
        $guestSessionId = $this->guestSessionId();

        if ($guestSessionId === null || $user->ID <= 0) {
            return;
        }

        $this->repository->transferSessionToUser($guestSessionId, (int) $user->ID);
    }

    /**
     * @return list<\WC_Product>
     */
    public function getProducts(): array
    {
        [$userId, $sessionId] = $this->context(false);
        $products = [];

        foreach ($this->repository->findProductIds($userId, $sessionId) as $productId) {
            $product = wc_get_product($productId);

            if ($product instanceof \WC_Product) {
                $products[] = $product;
            }
        }

        return $products;
    }

    public function getCount(): int
    {
        return count($this->getProducts());
    }

    public function isInCompare(int $productId): bool
    {
        [$userId, $sessionId] = $this->context(false);

        return $this->repository->exists($productId, $userId, $sessionId);
    }

    /**
     * @return array{product_id: int, in_compare: bool, label: string, count: int, compare_url: string}
     */
    public function getButtonData(\WC_Product $product): array
    {
        $inCompare = $this->isInCompare($product->get_id());

        return [
            'product_id' => $product->get_id(),
            'in_compare' => $inCompare,
            'label' => $inCompare
                ? $this->message('button_remove_text', 'remove')
                : $this->message('button_add_text', 'add'),
            'count' => $this->getCount(),
            'compare_url' => $this->getCompareUrl(),
        ];
    }

    public function getCompareUrl(): string
    {
        if (is_user_logged_in() && ($this->getSettings()['show_in_account'] ?? true)) {
            return wc_get_account_endpoint_url($this->endpoint);
        }

        return add_query_arg([
            'post_type' => 'product',
            $this->endpoint => '1',
        ], home_url('/'));
    }

    /**
     * @param list<\WC_Product> $products
     * @return list<array{key: string, label: string, values: array<int, string>, text_values: array<int, string>}>
     */
    public function buildRows(array $products): array
    {
        if ($products === []) {
            return [];
        }

        $rows = [];

        foreach ($this->comparisonFields as $key => $label) {
            $values = [];
            $textValues = [];

            foreach ($products as $product) {
                [$html, $text] = $this->getFieldValue($product, (string) $key);
                $values[] = $html;
                $textValues[] = $text;
            }

            $rows[] = [
                'key' => (string) $key,
                'label' => (string) $label,
                'values' => $values,
                'text_values' => $textValues,
            ];
        }

        if ((bool) ($this->getSettings()['show_attributes'] ?? true)) {
            foreach ($this->getAttributeLabels($products) as $taxonomy => $label) {
                $values = [];
                $textValues = [];

                foreach ($products as $product) {
                    $value = $this->getAttributeValue($product, $taxonomy);
                    $values[] = esc_html($value);
                    $textValues[] = $value;
                }

                $rows[] = [
                    'key' => 'attribute_' . sanitize_key($taxonomy),
                    'label' => $label,
                    'values' => $values,
                    'text_values' => $textValues,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param list<array{key: string, label: string, values: array<int, string>, text_values: array<int, string>}> $rows
     * @return array<string, bool>
     */
    public function calculateDifferences(array $rows): array
    {
        $differences = [];

        foreach ($rows as $row) {
            $normalized = array_filter(array_map(
                static fn (string $value): string => trim(wp_strip_all_tags($value)),
                $row['text_values'],
            ));

            $differences[$row['key']] = count(array_unique($normalized)) > 1;
        }

        return $differences;
    }

    public function getLimitNoticeText(int $limit): string
    {
        return Formatter::interpolate(
            $this->message('limit_notice_text', 'limit_notice'),
            ['limit' => (string) $limit],
        );
    }

    public function canUse(): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        return (bool) ($this->getSettings()['allow_guests'] ?? true) || is_user_logged_in();
    }

    private function shouldEnqueueAssets(): bool
    {
        if (is_admin()) {
            return false;
        }

        if (is_shop() || is_product() || is_product_taxonomy() || is_account_page()) {
            return true;
        }

        return ! empty($this->getSettings()['show_sticky_bar']);
    }

    private function getMaxItems(): int
    {
        return max(2, min(6, (int) ($this->getSettings()['max_items'] ?? 4)));
    }

    /**
     * @return array{0: ?int, 1: ?string}
     */
    private function context(bool $createGuestSession): array
    {
        $userId = get_current_user_id() > 0 ? get_current_user_id() : null;
        $sessionId = $userId === null
            ? ($createGuestSession ? $this->getOrCreateGuestSessionId() : $this->guestSessionId())
            : null;

        return [$userId, $sessionId];
    }

    private function guestSessionId(): ?string
    {
        $cookie = sanitize_text_field((string) wp_unslash($_COOKIE[$this->guestCookie] ?? ''));

        return $cookie !== '' ? $cookie : null;
    }

    private function getOrCreateGuestSessionId(): string
    {
        $existing = $this->guestSessionId();

        if ($existing !== null) {
            return $existing;
        }

        $sessionId = wp_generate_uuid4();

        setcookie(
            $this->guestCookie,
            $sessionId,
            [
                'expires' => time() + MONTH_IN_SECONDS * 6,
                'path' => COOKIEPATH,
                'domain' => COOKIE_DOMAIN ?: '',
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ],
        );

        $_COOKIE[$this->guestCookie] = $sessionId;

        return $sessionId;
    }

    /**
     * @param list<\WC_Product> $products
     * @return array<string, string>
     */
    private function getAttributeLabels(array $products): array
    {
        $labels = [];

        foreach ($products as $product) {
            foreach ($product->get_attributes() as $attribute) {
                if (! $attribute instanceof \WC_Product_Attribute) {
                    continue;
                }

                $name = $attribute->get_name();

                if (! isset($labels[$name])) {
                    $labels[$name] = wc_attribute_label($name, $product);
                }
            }
        }

        return $labels;
    }

    private function getAttributeValue(\WC_Product $product, string $attributeName): string
    {
        $attributes = $product->get_attributes();
        $attribute = $attributes[$attributeName] ?? null;

        if (! $attribute instanceof \WC_Product_Attribute) {
            return '-';
        }

        if ($attribute->is_taxonomy()) {
            $values = wc_get_product_terms($product->get_id(), $attributeName, ['fields' => 'names']);

            return $values !== [] ? implode(', ', $values) : '-';
        }

        $values = $attribute->get_options();

        return $values !== [] ? implode(', ', $values) : '-';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function getFieldValue(\WC_Product $product, string $key): array
    {
        switch ($key) {
            case 'price':
                $priceHtml = $product->get_price_html();

                return [$priceHtml !== '' ? $priceHtml : '-', $priceHtml !== '' ? wp_strip_all_tags($priceHtml) : '-'];

            case 'sku':
                $sku = $product->get_sku();

                return [esc_html($sku !== '' ? $sku : '-'), $sku !== '' ? $sku : '-'];

            case 'availability':
                $html = wc_get_stock_html($product);

                return [$html !== '' ? $html : '-', $html !== '' ? wp_strip_all_tags($html) : '-'];

            case 'description':
                $text = wp_strip_all_tags((string) $product->get_short_description());
                $text = $text !== '' ? $text : '-';

                return [esc_html($text), $text];
        }

        $resolved = ($this->fieldResolver)($product, $key);

        if (! is_array($resolved) || ! isset($resolved[0], $resolved[1])) {
            return ['-', '-'];
        }

        return [(string) $resolved[0], (string) $resolved[1]];
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
