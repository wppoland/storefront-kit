<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Badge;

/**
 * A single resolved product badge: the visible {@see $text} and a
 * CSS-style key ({@see $style}, e.g. `accent`, `warning`, `success`,
 * `danger`, `neutral`) the host theme maps to colours. Namespace-neutral
 * plain value object — no WooCommerce or text-domain coupling.
 */
final class Badge
{
    public function __construct(
        public readonly string $text,
        public readonly string $style,
    ) {
    }

    /**
     * Stable de-duplication key (`text|style`) so two rules producing the
     * same visible badge collapse to one.
     */
    public function dedupeKey(): string
    {
        return $this->text . '|' . $this->style;
    }
}
