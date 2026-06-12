<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Waitlist;

use WPPoland\StorefrontKit\Support\Formatter;

final class WaitlistEngine
{
    /**
     * @param callable(): bool $isEnabled
     * @param callable(): array<string, mixed> $settings
     * @param callable(string, array<string, mixed>): void $renderTemplate
     * @param array<string, string> $defaultMessages
     */
    public function __construct(
        private readonly WaitlistRepository $repository,
        private readonly string $ajaxAction,
        private readonly string $nonceAction,
        private readonly string $scriptObjectName,
        private readonly string $assetHandle,
        private readonly string $styleUrl,
        private readonly string $scriptUrl,
        private readonly string $version,
        private readonly string $templateName,
        private readonly array $defaultMessages,
        private readonly \Closure $isEnabled,
        private readonly \Closure $settings,
        private readonly \Closure $renderTemplate,
    ) {
    }

    public function registerHooks(): void
    {
        add_action('woocommerce_single_product_summary', [$this, 'renderForm'], 32);
        add_action('wp_ajax_' . $this->ajaxAction, [$this, 'handleSubscribe']);
        add_action('wp_ajax_nopriv_' . $this->ajaxAction, [$this, 'handleSubscribe']);
        add_action('woocommerce_product_set_stock_status', [$this, 'notifySubscribers'], 10, 3);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function enqueueAssets(): void
    {
        if (! $this->isEnabled() || ! is_product()) {
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
            'errorText' => $this->defaultMessage('generic_error'),
        ]);
    }

    public function renderForm(): void
    {
        global $product;

        if (! $product instanceof \WC_Product || ! $this->shouldRenderForProduct($product)) {
            return;
        }

        ($this->renderTemplate)($this->templateName, [
            'product' => $product,
            'settings' => $this->getSettings(),
            'email' => is_user_logged_in() ? wp_get_current_user()->user_email : '',
        ]);
    }

    public function handleSubscribe(): void
    {
        check_ajax_referer($this->nonceAction, 'nonce');

        $productId = isset($_POST['product_id']) ? absint(wp_unslash($_POST['product_id'])) : 0;
        $email = isset($_POST['email']) ? sanitize_email((string) wp_unslash($_POST['email'])) : '';
        $privacy = isset($_POST['privacy']) ? sanitize_text_field((string) wp_unslash($_POST['privacy'])) : '';
        $product = wc_get_product($productId);

        if (! $product instanceof \WC_Product) {
            wp_send_json_error(['message' => (string) ($this->getSettings()['product_not_found_text'] ?? $this->defaultMessage('product_not_found'))], 404);
        }

        if (! $this->shouldRenderForProduct($product)) {
            wp_send_json_error(['message' => (string) ($this->getSettings()['disabled_text'] ?? $this->defaultMessage('disabled'))], 400);
        }

        if ($email === '' || ! is_email($email)) {
            wp_send_json_error(['message' => (string) ($this->getSettings()['invalid_email_text'] ?? $this->defaultMessage('invalid_email'))], 422);
        }

        if ($privacy !== '1') {
            wp_send_json_error(['message' => (string) ($this->getSettings()['privacy_error_text'] ?? $this->defaultMessage('privacy_error'))], 422);
        }

        if (! ($this->getSettings()['allow_guests'] ?? true) && ! is_user_logged_in()) {
            wp_send_json_error(['message' => (string) ($this->getSettings()['login_required_text'] ?? $this->defaultMessage('login_required'))], 403);
        }

        $this->repository->subscribe($productId, $email, get_current_user_id() ?: null);

        wp_send_json_success([
            'message' => (string) ($this->getSettings()['success_text'] ?? $this->defaultMessage('success')),
        ]);
    }

    public function notifySubscribers(int $productId, string $stockStatus, \WC_Product $product): void
    {
        if (! $this->isEnabled() || $stockStatus !== 'instock') {
            return;
        }

        $targetProductIds = [$productId];
        $parentId = $product->get_parent_id();

        if ($parentId > 0 && ! in_array($parentId, $targetProductIds, true)) {
            $targetProductIds[] = $parentId;
        }

        $processedSubscriptions = [];

        foreach ($targetProductIds as $targetProductId) {
            foreach ($this->repository->findPendingByProduct($targetProductId) as $subscription) {
                if (isset($processedSubscriptions[$subscription->id])) {
                    continue;
                }

                $processedSubscriptions[$subscription->id] = true;

                $subject = Formatter::interpolate(
                    (string) ($this->getSettings()['notify_subject'] ?? $this->defaultMessage('notify_subject')),
                    ['product_name' => $product->get_name()],
                );

                $message = sprintf(
                    "%s\n\n%s\n%s",
                    str_replace('{product_name}', $product->get_name(), (string) ($this->getSettings()['notify_intro_text'] ?? $this->defaultMessage('notify_intro'))),
                    get_permalink($targetProductId),
                    (string) ($this->getSettings()['notify_outro_text'] ?? $this->defaultMessage('notify_outro')),
                );

                if (wp_mail($subscription->email, $subject, $message)) {
                    $this->repository->markNotified($subscription->id);
                }
            }
        }
    }

    private function shouldRenderForProduct(\WC_Product $product): bool
    {
        if (! $this->isEnabled() || ! ($this->getSettings()['show_on_single'] ?? true)) {
            return false;
        }

        return ! $product->is_in_stock() || $product->get_stock_status() === 'onbackorder';
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

    private function defaultMessage(string $key): string
    {
        return $this->defaultMessages[$key] ?? '';
    }
}
