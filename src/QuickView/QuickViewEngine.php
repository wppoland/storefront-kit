<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\QuickView;

/**
 * Namespace-neutral AJAX quick-view engine for shop archives.
 *
 * Registers the quick-view trigger button on the shop loop, prints a modal
 * shell in the footer, enqueues the (host-supplied) modal JS/CSS, and serves
 * the product quick-view HTML fragment over `admin-ajax.php`. Mirrors the
 * enqueue + nonce + AJAX pattern of
 * {@see \WPPoland\StorefrontKit\Waitlist\WaitlistEngine}: the markup, fragment
 * HTML, enabled-check and resolved settings are all constructor-injected via
 * closures, so no text-domain, option key or asset path is hard-coded here.
 * The modal JS/CSS itself ships in the consuming (Peek) plugin.
 */
final class QuickViewEngine
{
    /**
     * @param \Closure(): bool $isEnabled
     * @param \Closure(): bool $shouldRender Whether the quick-view should load
     *        on the current request (e.g. shop / product archive context).
     * @param \Closure(): array<string, mixed> $settings
     * @param \Closure(string, array<string, mixed>): void $renderTemplate
     *        Echoes a template (loop button + modal shell).
     * @param \Closure(\WC_Product, array<string, mixed>): string $renderFragment
     *        Returns the quick-view HTML fragment for a product.
     * @param array<string, string> $labels Fallback strings keyed by
     *        `loading`, `error`, `not_found` used when a settings value is absent.
     */
    public function __construct(
        private readonly string $ajaxAction,
        private readonly string $nonceAction,
        private readonly string $scriptObjectName,
        private readonly string $assetHandle,
        private readonly string $styleUrl,
        private readonly string $scriptUrl,
        private readonly string $version,
        private readonly string $buttonTemplate,
        private readonly string $modalTemplate,
        private readonly array $labels,
        private readonly \Closure $isEnabled,
        private readonly \Closure $shouldRender,
        private readonly \Closure $settings,
        private readonly \Closure $renderTemplate,
        private readonly \Closure $renderFragment,
    ) {
    }

    public function registerHooks(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_footer', [$this, 'renderModalShell']);
        add_action('wp_ajax_' . $this->ajaxAction, [$this, 'handleModal']);
        add_action('wp_ajax_nopriv_' . $this->ajaxAction, [$this, 'handleModal']);

        $placement = (string) ($this->getSettings()['loop_button_placement'] ?? 'below');

        if ($placement === 'overlay') {
            add_action('woocommerce_before_shop_loop_item', [$this, 'renderLoopButton'], 8);
        } else {
            add_action('woocommerce_after_shop_loop_item', [$this, 'renderLoopButton'], 21);
        }
    }

    public function enqueueAssets(): void
    {
        if (! $this->isEnabled() || ! $this->shouldRender()) {
            return;
        }

        wp_enqueue_style($this->assetHandle, $this->styleUrl, [], $this->version);
        wp_enqueue_script($this->assetHandle, $this->scriptUrl, [], $this->version, [
            'in_footer' => true,
            'strategy' => 'defer',
        ]);

        wp_enqueue_script('wc-add-to-cart-variation');

        wp_localize_script($this->assetHandle, $this->scriptObjectName, [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => $this->ajaxAction,
            'nonce' => wp_create_nonce($this->nonceAction),
            'loadingText' => (string) ($this->getSettings()['loading_text'] ?? $this->label('loading')),
            'errorText' => (string) ($this->getSettings()['error_text'] ?? $this->label('error')),
            'showBackdropClose' => (bool) ($this->getSettings()['show_backdrop_close'] ?? true),
        ]);
    }

    public function renderLoopButton(): void
    {
        global $product;

        if (! $product instanceof \WC_Product || ! $this->isEnabled() || ! ($this->getSettings()['show_on_loop'] ?? true)) {
            return;
        }

        ($this->renderTemplate)($this->buttonTemplate, [
            'product' => $product,
            'settings' => $this->getSettings(),
        ]);
    }

    public function renderModalShell(): void
    {
        if (! $this->isEnabled() || ! $this->shouldRender()) {
            return;
        }

        ($this->renderTemplate)($this->modalTemplate, [
            'settings' => $this->getSettings(),
            'loading_text' => (string) ($this->getSettings()['loading_text'] ?? $this->label('loading')),
            'show_modal_label' => (bool) ($this->getSettings()['show_modal_label'] ?? true),
            'show_close_button' => (bool) ($this->getSettings()['show_close_button'] ?? true),
        ]);
    }

    public function handleModal(): void
    {
        check_ajax_referer($this->nonceAction, 'nonce');

        if (isset($_GET['product_id'])) {
            $productId = absint(wp_unslash($_GET['product_id']));
        } elseif (isset($_POST['product_id'])) {
            $productId = absint(wp_unslash($_POST['product_id']));
        } else {
            $productId = 0;
        }

        $product = wc_get_product($productId);

        if (! $product instanceof \WC_Product || ! $this->isViewable($product)) {
            wp_send_json_error(['message' => (string) ($this->getSettings()['product_not_found_text'] ?? $this->label('not_found'))], 404);
        }

        wp_send_json_success([
            'html' => ($this->renderFragment)($product, $this->getSettings()),
        ]);
    }

    /**
     * Guard against serving a quick-view fragment for products the visitor
     * should not see (drafts, private, pending, trashed). Only publicly
     * published products are exposed over the unauthenticated AJAX endpoint,
     * unless the current user can edit the product.
     */
    private function isViewable(\WC_Product $product): bool
    {
        if (get_post_status($product->get_id()) === 'publish') {
            return true;
        }

        return current_user_can('edit_post', $product->get_id());
    }

    private function isEnabled(): bool
    {
        return (bool) ($this->isEnabled)();
    }

    private function shouldRender(): bool
    {
        return (bool) ($this->shouldRender)();
    }

    private function label(string $key): string
    {
        return $this->labels[$key] ?? '';
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
