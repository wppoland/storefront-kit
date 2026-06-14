<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Wishlist;

/**
 * Namespace-neutral wishlist engine for guests and logged-in customers.
 *
 * Mirrors {@see \WPPoland\StorefrontKit\Waitlist\WaitlistEngine}: every
 * text-domain string, option key, asset handle/URL and template name is
 * constructor-injected via closures and arrays, so nothing is hard-coded here.
 * The engine owns the guest cookie + user-id ownership resolution, the
 * add/remove AJAX toggle, the loop/single add-to-wishlist buttons, the My
 * Account page + shortcode body, and guest->user transfer on login. Storage is
 * delegated to a host-supplied {@see WishlistRepository}; the button/account
 * markup ships in the consuming plugin via the injected `renderTemplate` closure.
 */
final class WishlistEngine
{
    /**
     * @param \Closure(): bool $isEnabled
     * @param \Closure(): array<string, mixed> $settings Resolved settings array.
     * @param \Closure(string, array<string, mixed>): void $renderTemplate
     *        Echoes a template (loop / single button).
     * @param \Closure(string, array<string, mixed>): string $renderAccount
     *        Returns the account / shortcode wishlist HTML.
     * @param array<string, string> $labels Fallback strings keyed by
     *        `add`, `remove`, `account`, `login_required`, `not_found`.
     */
    public function __construct(
        private readonly WishlistRepository $repository,
        private readonly string $ajaxAction,
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
        private readonly string $accountTemplate,
        private readonly array $labels,
        private readonly \Closure $isEnabled,
        private readonly \Closure $settings,
        private readonly \Closure $renderTemplate,
        private readonly \Closure $renderAccount,
    ) {
    }

    public function registerHooks(): void
    {
        add_action('init', [$this, 'registerEndpoint']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('woocommerce_single_product_summary', [$this, 'renderSingleButton'], 33);
        add_action('woocommerce_after_shop_loop_item', [$this, 'renderLoopButton'], 19);
        add_action('wp_ajax_' . $this->ajaxAction, [$this, 'handleToggle']);
        add_action('wp_ajax_nopriv_' . $this->ajaxAction, [$this, 'handleToggle']);
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
            'nonce' => wp_create_nonce($this->nonceAction),
            'loginUrl' => wc_get_page_permalink('myaccount'),
            'allowGuests' => (bool) ($this->getSettings()['allow_guests'] ?? true),
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

        if ($product->is_type('variable')) {
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

        if ($product->is_type('variable')) {
            wp_send_json_error(['message' => $this->message('variation_required_text', 'variation_required')], 422);
        }

        [$userId, $sessionId] = $this->context(true);

        if ($this->repository->exists($productId, $userId, $sessionId)) {
            $this->repository->remove($productId, $userId, $sessionId);

            wp_send_json_success([
                'in_wishlist' => false,
                'count' => $this->getCount(),
                'button_text' => $this->message('button_add_text', 'add'),
            ]);
        }

        $this->repository->add($productId, $userId, $sessionId);

        wp_send_json_success([
            'in_wishlist' => true,
            'count' => $this->getCount(),
            'button_text' => $this->message('button_remove_text', 'remove'),
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
        echo $this->renderWishlist();
    }

    public function renderWishlist(): string
    {
        return ($this->renderAccount)($this->accountTemplate, [
            'products' => $this->getProducts(),
            'settings' => $this->getSettings(),
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

    public function isInWishlist(int $productId): bool
    {
        [$userId, $sessionId] = $this->context(false);

        return $this->repository->exists($productId, $userId, $sessionId);
    }

    /**
     * @return array{product_id:int,in_wishlist:bool,label:string,requires_variation:bool}
     */
    public function getButtonData(\WC_Product $product): array
    {
        $requiresVariation = $product->is_type('variable');
        $productId = $product->get_id();
        $inWishlist = $requiresVariation ? false : $this->isInWishlist($productId);

        return [
            'product_id' => $productId,
            'in_wishlist' => $inWishlist,
            'label' => $inWishlist
                ? $this->message('button_remove_text', 'remove')
                : $this->message('button_add_text', 'add'),
            'requires_variation' => $requiresVariation,
        ];
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

        return is_shop() || is_product() || is_product_taxonomy() || is_account_page() || $this->isWishlistPage();
    }

    private function isWishlistPage(): bool
    {
        $pageId = (int) ($this->getSettings()['wishlist_page_id'] ?? 0);

        return $pageId > 0 && is_page($pageId);
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
