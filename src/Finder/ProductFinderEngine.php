<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Finder;

/**
 * Namespace-neutral guided product-finder quiz engine.
 *
 * Mirrors {@see \WPPoland\StorefrontKit\Compare\CompareEngine}: every
 * text-domain string, option key, asset handle/URL and template name is
 * constructor-injected via closures and arrays, so nothing WooCommerce-,
 * option- or text-domain-specific is hard-coded here. The engine owns the
 * shortcode registration, the front-end asset enqueue with a localised config
 * object, and a lightweight REST endpoint that maps a set of single-choice
 * answers to one product recommendation. All markup ships in the consuming
 * plugin via the injected `renderTemplate` / `renderToString` closures, and the
 * recommended product's presentation data is produced by the injected
 * `resolveProduct` closure.
 *
 * The result is served over REST (not admin-ajax) so it stays cheap on TTFB and
 * is never touched by page caches; the handler also emits no-store headers and
 * reads the product live, so price/stock are always current.
 */
final class ProductFinderEngine
{
    /**
     * @param array<string, string> $labels Fallback strings keyed by
     *        `no_match`, `not_available`, `error`.
     * @param \Closure(): bool $isEnabled
     * @param \Closure(): array<string, mixed> $settings Resolved settings array
     *        (`steps`, `results`, `fallback`, `title`, `intro`, `accent_color`).
     * @param \Closure(string, array<string, mixed>): string $renderToString
     *        Returns a template's HTML (the quiz widget / recommendation card).
     * @param \Closure(int): (array<string, mixed>|null) $resolveProduct
     *        Returns presentation data for a product id, or null if it cannot be
     *        recommended (missing / not purchasable / out of stock).
     */
    public function __construct(
        private readonly string $restNamespace,
        private readonly string $restRoute,
        private readonly string $nonceAction,
        private readonly string $assetHandle,
        private readonly string $styleUrl,
        private readonly string $scriptUrl,
        private readonly string $version,
        private readonly string $shortcodeTag,
        private readonly string $widgetTemplate,
        private readonly string $resultTemplate,
        private readonly array $labels,
        private readonly \Closure $isEnabled,
        private readonly \Closure $settings,
        private readonly \Closure $renderToString,
        private readonly \Closure $resolveProduct,
    ) {
    }

    public function registerHooks(): void
    {
        add_shortcode($this->shortcodeTag, [$this, 'renderWidget']);
        add_action('wp_enqueue_scripts', [$this, 'registerAssets']);
        add_action('rest_api_init', [$this, 'registerRestRoute']);
    }

    /**
     * Register (not enqueue) the front assets so the shortcode can enqueue them
     * on demand, keeps every other page free of Finder's CSS/JS (CWV hygiene).
     */
    public function registerAssets(): void
    {
        wp_register_style($this->assetHandle, $this->styleUrl, [], $this->version);
        wp_register_script($this->assetHandle, $this->scriptUrl, [], $this->version, [
            'in_footer' => true,
            'strategy' => 'defer',
        ]);
    }

    public function registerRestRoute(): void
    {
        register_rest_route(
            $this->restNamespace,
            '/' . ltrim($this->restRoute, '/'),
            [
                'methods' => 'GET',
                'callback' => [$this, 'handleMatch'],
                'permission_callback' => '__return_true',
                'args' => [
                    'answers' => [
                        'type' => 'string',
                        'required' => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );
    }

    /**
     * Render the quiz widget. Returns HTML (shortcode contract). Enqueues the
     * assets and passes the config to the template, which prints it as an inline
     * JSON island inside the widget. The front script reads that per-root, so it
     * works regardless of script-enqueue timing / page caching, and supports
     * multiple finders on one page.
     *
     * @return string
     */
    public function renderWidget(): string
    {
        if (! $this->isEnabled()) {
            return '';
        }

        $settings = $this->getSettings();
        $steps    = $this->steps($settings);

        if ($steps === []) {
            return '';
        }

        wp_enqueue_style($this->assetHandle);
        wp_enqueue_script($this->assetHandle);

        $config = [
            'restUrl' => rest_url($this->restNamespace . '/' . ltrim($this->restRoute, '/')),
            'restNonce' => wp_create_nonce($this->nonceAction),
            'steps' => $this->publicSteps($steps),
            'i18n' => $this->labels,
        ];

        return ($this->renderToString)($this->widgetTemplate, [
            'title' => (string) ($settings['title'] ?? ''),
            'intro' => (string) ($settings['intro'] ?? ''),
            'accent_color' => $this->accentColor($settings),
            'steps' => $steps,
            'labels' => $this->labels,
            'config' => $config,
        ]);
    }

    /**
     * REST: map answers to a recommendation and return the rendered card HTML.
     */
    public function handleMatch(\WP_REST_Request $request): \WP_REST_Response
    {
        nocache_headers();

        $answers  = $this->parseAnswers((string) $request->get_param('answers'));
        $settings = $this->getSettings();
        $result   = $this->matchResult($answers, $settings);

        $productId = (int) ($result['product_id'] ?? 0);
        $product   = $productId > 0 ? ($this->resolveProduct)($productId) : null;

        if (! is_array($product)) {
            $response = new \WP_REST_Response([
                'ok' => false,
                'message' => $this->label('not_available'),
            ], 200);
            $response->header('Cache-Control', 'no-store, max-age=0');

            return $response;
        }

        $html = ($this->renderToString)($this->resultTemplate, [
            'product' => $product,
            'headline' => (string) ($result['headline'] ?? ''),
            'blurb' => (string) ($result['blurb'] ?? ''),
            'cta_label' => (string) ($result['cta_label'] ?? ''),
            'accent_color' => $this->accentColor($settings),
            'new_tab' => ! empty($settings['new_tab']),
            'labels' => $this->labels,
        ]);

        $response = new \WP_REST_Response(['ok' => true, 'html' => $html], 200);
        $response->header('Cache-Control', 'no-store, max-age=0');

        return $response;
    }

    /**
     * Find the results row whose ordered `match` values equal the given answers.
     * Falls back to the configured `fallback` row when nothing matches.
     *
     * @param list<string>         $answers
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    public function matchResult(array $answers, array $settings): array
    {
        $results = isset($settings['results']) && is_array($settings['results']) ? $settings['results'] : [];
        $needle  = implode('|', $answers);

        foreach ($results as $row) {
            if (! is_array($row)) {
                continue;
            }

            $match = isset($row['match']) && is_array($row['match']) ? array_values($row['match']) : [];

            if (implode('|', array_map('strval', $match)) === $needle && $needle !== '') {
                return $row;
            }
        }

        $fallback = isset($settings['fallback']) && is_array($settings['fallback']) ? $settings['fallback'] : [];

        return $fallback;
    }

    /**
     * Split the comma-separated answers param into a clean list of option slugs.
     *
     * @return list<string>
     */
    private function parseAnswers(string $raw): array
    {
        $parts = array_map('trim', explode(',', $raw));
        $parts = array_filter($parts, static fn (string $v): bool => $v !== '');

        return array_values(array_map(static fn (string $v): string => sanitize_key($v), $parts));
    }

    /**
     * Normalised list of steps from settings (drops malformed rows).
     *
     * @param array<string, mixed> $settings
     *
     * @return list<array<string, mixed>>
     */
    private function steps(array $settings): array
    {
        $steps = isset($settings['steps']) && is_array($settings['steps']) ? $settings['steps'] : [];
        $out   = [];

        foreach ($steps as $step) {
            if (! is_array($step) || empty($step['options']) || ! is_array($step['options'])) {
                continue;
            }

            $options = [];

            foreach ($step['options'] as $option) {
                if (! is_array($option) || empty($option['value']) || empty($option['label'])) {
                    continue;
                }

                $options[] = [
                    'label' => (string) $option['label'],
                    'value' => sanitize_key((string) $option['value']),
                ];
            }

            if ($options === []) {
                continue;
            }

            $out[] = [
                'question' => (string) ($step['question'] ?? ''),
                'help' => (string) ($step['help'] ?? ''),
                'options' => $options,
            ];
        }

        return $out;
    }

    /**
     * Steps reduced to what the front script needs: the ordered option values
     * per step, used client-side to validate restored URL/session state before
     * it ever touches the DOM (XSS-safe allow-list).
     *
     * @param list<array<string, mixed>> $steps
     *
     * @return list<list<string>>
     */
    private function publicSteps(array $steps): array
    {
        $out = [];

        foreach ($steps as $step) {
            $values = [];

            foreach ($step['options'] as $option) {
                $values[] = (string) $option['value'];
            }

            $out[] = $values;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function accentColor(array $settings): string
    {
        $color = isset($settings['accent_color']) ? (string) $settings['accent_color'] : '';

        return $color !== '' ? $color : '#d97706';
    }

    private function label(string $key): string
    {
        return (string) ($this->labels[$key] ?? $key);
    }

    /**
     * @return array<string, mixed>
     */
    private function getSettings(): array
    {
        $settings = ($this->settings)();

        return is_array($settings) ? $settings : [];
    }

    private function isEnabled(): bool
    {
        return (bool) ($this->isEnabled)();
    }
}
