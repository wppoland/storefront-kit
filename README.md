# storefront-kit

Shared, namespace-neutral storefront engines for the WPPoland family of single-purpose
WooCommerce plugins (Polski, Sieve, Restock, …). Logic lives here once; each plugin ships a
**thin adapter** that instantiates an engine with its own text-domain, option prefix and asset
URLs. Fixes (CWV, accessibility, bugs) land once and propagate to every consumer.

- Namespace: `WPPoland\StorefrontKit\`
- License: GPL-2.0-or-later
- Requires: PHP >= 8.1 (WordPress + WooCommerce provided by the host plugin at runtime)

## What's inside

| Class | Purpose |
|---|---|
| `Waitlist\WaitlistEngine` | Back-in-stock / waitlist: OOS form render, AJAX subscribe, restock email on `woocommerce_product_set_stock_status`. All config (AJAX action, nonce, asset handles/URLs, messages, `isEnabled`/`settings`/`renderTemplate` closures) is constructor-injected — no hard-coded constants. |
| `Waitlist\WaitlistRepository` | Interface the host plugin implements (`subscribe`, `findPendingByProduct`, `markNotified`). |
| `Pricing\DynamicPricingEngine` | Quantity/volume tiered pricing: applies the best-matching tier to each cart line on `woocommerce_before_calculate_totals` (idempotent, recomputed from regular price) and renders a server-side price table on the single product page. All config (`templateName`, `labels`, `isEnabled`/`tiers`/`renderTemplate` closures) is constructor-injected — no hard-coded constants. |
| `Pricing\PriceTier` | Plain value object for one tier (`minQuantity`, `discountPercent`); `fromArray()` normalises a saved option row, `appliesTo()` tests a line quantity. |
| `Support\Formatter` | `interpolate()` — `{token}` → value substitution for message templates. |

## Consuming it (the standard pattern)

In a plugin's `composer.json`:

```jsonc
"repositories": [
  { "type": "vcs", "url": "https://github.com/wppoland/storefront-kit" }
],
"require": { "php": ">=8.1", "wppoland/storefront-kit": "^1.0" }
```

`composer install --no-dev` vendors a real (copied, non-symlinked) `vendor/wppoland/storefront-kit/`
that ships inside the plugin's wp.org zip.

### Local atomic dev (edit kit + adapter together, no publish/bump cycle)

Check the kit out as a sibling and add a **git-ignored** path override so Composer prefers the
local copy. Create `composer.local.json` (or add to a local-only `auth`/override) — example using a
path repository that takes priority:

```jsonc
// composer.json of the consumer, dev-only override (do NOT commit if it points at a machine path)
"repositories": [
  { "type": "path", "url": "../storefront-kit", "options": { "symlink": true } },
  { "type": "vcs", "url": "https://github.com/wppoland/storefront-kit" }
]
```

Composer uses the local path when present; CI/release checkouts (no sibling dir) fall back to the
tagged VCS version, so the shipped artifact is always a reproducible tagged release.

## Versioning

SemVer via git tags (`v1.0.0`, `v1.1.0`, …). Consumers pin `^1.0` and pick up compatible fixes on
their next `composer update` / release. Breaking changes → major bump + a deliberate consumer
migration.

## Quality

```bash
composer install
composer analyse   # PHPStan level 6 + WordPress/WooCommerce stubs
```
