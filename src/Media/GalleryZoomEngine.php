<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Media;

/**
 * Namespace-neutral gallery hover-zoom + lightbox engine for the single
 * product page.
 *
 * PHP enqueue + markup only: enqueues the (host-supplied) zoom/lightbox JS/CSS
 * with the no-jQuery, deferred, in-footer convention and prints the lightbox
 * shell in the footer through an injected `renderTemplate` closure. The
 * zoom/lightbox JS/CSS itself ships in the consuming (Reel) plugin.
 *
 * All WooCommerce/text-domain/option/asset specifics are constructor-injected
 * via closures and strings — exactly like
 * {@see \WPPoland\StorefrontKit\Waitlist\WaitlistEngine}. Do NOT hard-code
 * text-domains, option keys or asset paths here.
 */
final class GalleryZoomEngine
{
    /**
     * @param \Closure(): bool $isEnabled
     * @param \Closure(): bool $shouldRender Whether to load on the current
     *        request (e.g. single product context).
     * @param \Closure(): array<string, mixed> $settings
     * @param \Closure(string, array<string, mixed>): void $renderTemplate
     * @param array<string, string> $labels Fallback strings keyed by
     *        `trigger` used when a settings value is absent.
     */
    public function __construct(
        private readonly string $scriptObjectName,
        private readonly string $assetHandle,
        private readonly string $styleUrl,
        private readonly string $scriptUrl,
        private readonly string $version,
        private readonly string $lightboxTemplate,
        private readonly array $labels,
        private readonly \Closure $isEnabled,
        private readonly \Closure $shouldRender,
        private readonly \Closure $settings,
        private readonly \Closure $renderTemplate,
    ) {
    }

    public function registerHooks(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_footer', [$this, 'renderLightboxShell']);
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

        wp_localize_script($this->assetHandle, $this->scriptObjectName, [
            'zoomScale' => (float) ($this->getSettings()['zoom_scale'] ?? 1.45),
            'enableZoom' => (bool) ($this->getSettings()['enable_zoom'] ?? true),
            'enableLightbox' => (bool) ($this->getSettings()['enable_lightbox'] ?? true),
            'showBackdropClose' => (bool) ($this->getSettings()['show_backdrop_close'] ?? true),
            'triggerLabel' => (string) ($this->getSettings()['trigger_label'] ?? $this->label('trigger')),
        ]);
    }

    public function renderLightboxShell(): void
    {
        if (! $this->isEnabled() || ! $this->shouldRender() || ! ($this->getSettings()['enable_lightbox'] ?? true)) {
            return;
        }

        ($this->renderTemplate)($this->lightboxTemplate, [
            'settings' => $this->getSettings(),
        ]);
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
