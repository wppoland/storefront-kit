<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Media;

/**
 * Namespace-neutral product featured-video engine for the single product
 * media area.
 *
 * PHP enqueue + markup only: enqueues the (host-supplied) CSS, resolves the
 * per-product video URL/title from injected product meta, builds the embed or
 * self-hosted `<video>` markup, and prints it at the configured position
 * (`after_gallery` or `before_summary`) through an injected `renderTemplate`
 * closure. The video JS/CSS itself ships in the consuming (Reel) plugin.
 *
 * All WooCommerce/text-domain/option/meta/asset specifics are
 * constructor-injected via closures, strings and arrays — exactly like
 * {@see \WPPoland\StorefrontKit\Badge\BadgeEngine}. Do NOT hard-code
 * text-domains, option keys, meta keys or asset paths here.
 */
final class FeaturedVideoEngine
{
    /**
     * @param \Closure(): bool $isEnabled
     * @param \Closure(): bool $shouldRender Whether to load on the current
     *        request (e.g. single product context).
     * @param \Closure(): array<string, mixed> $settings
     * @param \Closure(\WC_Product, string): mixed $productMeta Reads a product
     *        meta value by key — keeps meta-key naming in the host plugin.
     * @param \Closure(string, array<string, mixed>): void $renderTemplate
     * @param array<string, string> $metaKeys Product meta keys (`url`, `title`).
     * @param array<string, string> $labels Fallback strings keyed by
     *        `title` used when neither meta nor settings provide one.
     */
    public function __construct(
        private readonly string $assetHandle,
        private readonly string $styleUrl,
        private readonly string $version,
        private readonly string $videoTemplate,
        private readonly array $metaKeys,
        private readonly array $labels,
        private readonly \Closure $isEnabled,
        private readonly \Closure $shouldRender,
        private readonly \Closure $settings,
        private readonly \Closure $productMeta,
        private readonly \Closure $renderTemplate,
    ) {
    }

    public function registerHooks(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('woocommerce_product_thumbnails', [$this, 'renderAfterGallery'], 25);
        add_action('woocommerce_before_single_product_summary', [$this, 'renderBeforeSummary'], 19);
    }

    public function enqueueAssets(): void
    {
        if (! $this->isEnabled() || ! $this->shouldRender()) {
            return;
        }

        wp_enqueue_style($this->assetHandle, $this->styleUrl, [], $this->version);
    }

    public function renderAfterGallery(): void
    {
        if (($this->getSettings()['position'] ?? 'after_gallery') !== 'after_gallery') {
            return;
        }

        $this->renderCurrentProductVideo();
    }

    public function renderBeforeSummary(): void
    {
        if (($this->getSettings()['position'] ?? 'after_gallery') !== 'before_summary') {
            return;
        }

        $this->renderCurrentProductVideo();
    }

    public function getVideoHtml(\WC_Product $product): string
    {
        $url = trim((string) $this->meta($product, 'url'));

        if ($url === '') {
            return '';
        }

        $autoplay = (bool) ($this->getSettings()['autoplay'] ?? false);

        if (preg_match('/\.(mp4|m4v|webm|ogv)(\?.*)?$/i', $url) === 1) {
            $shortcode = wp_video_shortcode([
                'src' => $url,
                'autoplay' => $autoplay ? 'on' : '',
                'preload' => 'metadata',
            ]);

            return is_string($shortcode) ? $shortcode : '';
        }

        if ($autoplay) {
            $url = (string) add_query_arg('autoplay', '1', $url);
        }

        $embed = wp_oembed_get($url);

        return is_string($embed) ? $embed : '';
    }

    private function renderCurrentProductVideo(): void
    {
        global $product;

        if (! $product instanceof \WC_Product || ! $this->isEnabled() || ! ($this->getSettings()['show_on_single'] ?? true)) {
            return;
        }

        $videoHtml = $this->getVideoHtml($product);

        if ($videoHtml === '') {
            return;
        }

        $title = trim((string) $this->meta($product, 'title'));

        if ($title === '') {
            $title = (string) ($this->getSettings()['title'] ?? $this->label('title'));
        }

        ($this->renderTemplate)($this->videoTemplate, [
            'video_html' => $videoHtml,
            'title' => $title,
            'intro_text' => (string) ($this->getSettings()['intro_text'] ?? ''),
            'show_title' => (bool) ($this->getSettings()['show_title'] ?? true),
            'show_intro' => (bool) ($this->getSettings()['show_intro'] ?? false),
            'product' => $product,
        ]);
    }

    private function meta(\WC_Product $product, string $key): mixed
    {
        return ($this->productMeta)($product, $this->metaKeys[$key] ?? $key);
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
