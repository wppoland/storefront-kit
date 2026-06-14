# storefront-kit

A PHP library of reusable WooCommerce storefront features (waitlist / back-in-stock,
dynamic pricing, product badges, quick view, compare, wishlist, gallery zoom, featured
video, direct checkout, product add-ons, bundles, gift cards). Each feature is a
self-contained class with no hard-coded constants — all configuration (text domain,
option keys, asset URLs, labels, templates) is passed in by the host application.

- Namespace: `WPPoland\StorefrontKit\`
- Requires: PHP >= 8.1 (WordPress and WooCommerce are provided by the host at runtime)
- License: GPL-2.0-or-later

## Install

```bash
composer require wppoland/storefront-kit
```

```jsonc
"require": { "php": ">=8.1", "wppoland/storefront-kit": "^1.0" }
```

## Development

```bash
composer install
composer analyse   # PHPStan
composer test      # PHPUnit
```

## License

GPL-2.0-or-later.
