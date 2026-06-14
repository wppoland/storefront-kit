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
| `Badge\BadgeEngine` | Product badges (merchandising / conversion hints): resolves manual (product meta) + automatic rules (sale, new, low-stock, bestseller, discount-percent, free-shipping, out-of-stock) for a `WC_Product`, de-duplicates and caps per context, and renders CSS-only markup. All config (`templateName`, `labels`, `metaKeys`, `isEnabled`/`settings`/`productMeta`/`renderTemplate` closures) is constructor-injected — no hard-coded constants, no JS. |
| `Badge\Badge` | Plain value object for one resolved badge (`text`, `style` CSS-style key); `dedupeKey()` collapses duplicate badges. |
| `QuickView\QuickViewEngine` | Shop-loop quick view: registers the loop trigger + footer modal shell, enqueues the host's modal JS/CSS (no jQuery, deferred), and serves the product quick-view HTML fragment over `admin-ajax.php`. All config (AJAX action, nonce, asset handles/URLs, labels, `isEnabled`/`shouldRender`/`settings`/`renderTemplate`/`renderFragment` closures) is constructor-injected — no hard-coded constants. The modal JS ships in the consuming plugin. |
| `Compare\CompareEngine` | Product comparison for guests (cookie) + customers (user-id): owns the guest-session resolution, AJAX add/remove + clear handlers, loop/single compare buttons, the My Account view + shortcode body, and a comparison-table builder with per-row difference highlighting. Standard WC rows (`price`, `sku`, `availability`, `description`) and attributes are computed internally; other rows go to the injected `fieldResolver`. All config (AJAX actions, nonce, asset handles/URLs, endpoint, cookie name, `comparisonFields`, labels, `isEnabled`/`settings`/`renderTemplate`/`renderTable`/`fieldResolver` closures) is constructor-injected — no hard-coded constants. The table/button JS/CSS ships in the consuming (Versus) plugin. |
| `Compare\CompareRepository` | Interface the host plugin implements (`add`, `remove`, `exists`, `count`, `removeOldest`, `clear`, `findProductIds`, `transferSessionToUser`). |
| `Wishlist\WishlistEngine` | Wishlist for guests (cookie) + customers (user-id): owns the guest-session resolution, AJAX add/remove toggle, loop/single add-to-wishlist buttons, the My Account view + shortcode body, and guest→user transfer on login. All config (AJAX action, nonce, asset handles/URLs, endpoint, cookie name, labels, `isEnabled`/`settings`/`renderTemplate`/`renderAccount` closures) is constructor-injected — no hard-coded constants. The button/list JS/CSS ships in the consuming plugin. |
| `Wishlist\WishlistRepository` | Interface the host plugin implements (`add`, `remove`, `exists`, `findProductIds`, `transferSessionToUser`). |
| `Media\GalleryZoomEngine` | Single-product gallery hover-zoom + lightbox: PHP enqueue (no jQuery, deferred, in-footer) + footer lightbox shell markup. All config (asset handle/URLs, labels, `isEnabled`/`shouldRender`/`settings`/`renderTemplate` closures) is constructor-injected. The zoom/lightbox JS/CSS ships in the consuming plugin. |
| `Media\FeaturedVideoEngine` | Single-product featured video: resolves the per-product video URL/title from injected product meta, builds self-hosted `<video>` or oEmbed markup, and prints it at the configured position (`after_gallery` / `before_summary`). All config (asset handle/URL, `metaKeys`, labels, `isEnabled`/`shouldRender`/`settings`/`productMeta`/`renderTemplate` closures) is constructor-injected — no hard-coded constants. |
| `Checkout\DirectCheckoutEngine` | "Buy Now" / direct checkout: renders a Buy Now button on single + loop, intercepts the nonce-protected request on `template_redirect`, (optionally) empties the cart, re-adds the product and redirects to checkout or cart. Stateless — no DB. All config (`requestKey`, nonce action, button template, labels, `isEnabled`/`settings`/`renderTemplate` closures) is constructor-injected — no hard-coded constants. The button markup ships in the consuming (Swift) plugin. |
| `AddOns\ProductAddOnsEngine` | Per-product add-on fields (text/checkbox/select with optional price deltas): renders fields under the product form, validates required ones, captures selections into the cart line item, adjusts the line price, and shows them in cart/order. Definitions are read from product meta via the injected `productMeta` closure — no custom table. All config (`cartKey`, `fieldPrefix`, fields template, labels, `isEnabled`/`settings`/`productMeta`/`renderTemplate` closures) is constructor-injected — no hard-coded constants. |
| `Bundle\ProductBundleEngine` | "Frequently bought together" bundle box: admin links N product ids + an optional discount % (read from product meta via `productMeta`); "Add bundle to cart" adds the main product + linked items in one nonce-protected request and applies the discount as a cart fee or per-item price adjustment. No custom table. All config (`requestKey`, nonce action, `cartFlag`, box template, labels, `isEnabled`/`settings`/`productMeta`/`renderTemplate` closures) is constructor-injected — no hard-coded constants. |
| `GiftCard\GiftCardEngine` | Gift cards / store credit: on order completion, generates a unique code per purchased gift-card line (host resolves is-gift-card + amount/recipient), persists `{code, balance, recipient_email, order_id}` via `GiftCardRepository` and emails the recipient. Redemption: a checkout code field applies the remaining balance as a negative cart fee and decrements the balance on completion. All config (`sessionKey`, `fieldName`, nonce action, field template, labels, `isEnabled`/`settings`/`isGiftCard`/`resolveCard`/`renderField` closures) is constructor-injected — no hard-coded constants. |
| `GiftCard\GiftCardRepository` | Interface the host plugin implements against its own custom table (`issue`, `findByCode`, `updateBalance`). The host owns the `$wpdb->prefix` table name and the justified `phpcs:disable WordPress.DB.DirectDatabaseQuery` block — the library stays `$wpdb`-free, mirroring `WaitlistRepository`. |
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
