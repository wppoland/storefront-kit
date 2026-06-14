<?php

declare(strict_types=1);

/**
 * Minimal WooCommerce class doubles for the unit suite.
 *
 * The real WooCommerce stubs (php-stubs/woocommerce-stubs) are PHPStan-only
 * declarations and are not safe to load at runtime. The engines only type-hint
 * a handful of WC_* classes; here we declare just enough of each so the engines
 * can be exercised and so Mockery can build partial/typed mocks of them.
 *
 * These are loaded once from the bootstrap, guarded so a real WC (if ever
 * present) wins.
 */

if (! class_exists('WC_DateTime')) {
    class WC_DateTime extends DateTime
    {
    }
}

if (! class_exists('WC_Product')) {
    class WC_Product
    {
        public function get_id(): int
        {
            return 0;
        }

        public function get_regular_price(string $context = 'view'): string
        {
            return '';
        }

        public function get_sale_price(string $context = 'view'): string
        {
            return '';
        }

        /** @return mixed */
        public function get_price(string $context = 'view')
        {
            return '';
        }

        public function set_price(string $price): void
        {
        }

        public function is_on_sale(string $context = 'view'): bool
        {
            return false;
        }

        public function is_in_stock(): bool
        {
            return true;
        }

        public function is_purchasable(): bool
        {
            return true;
        }

        public function is_type(string $type): bool
        {
            return false;
        }

        public function managing_stock(): bool
        {
            return false;
        }

        /** @return int|null */
        public function get_stock_quantity(string $context = 'view')
        {
            return null;
        }

        public function get_total_sales(): int
        {
            return 0;
        }

        public function get_shipping_class(): string
        {
            return '';
        }

        /** @return WC_DateTime|null */
        public function get_date_created(string $context = 'view')
        {
            return null;
        }

        public function get_price_html(string $deprecated = ''): string
        {
            return '';
        }

        public function get_sku(string $context = 'view'): string
        {
            return '';
        }

        public function get_short_description(string $context = 'view'): string
        {
            return '';
        }

        public function get_permalink(): string
        {
            return '';
        }

        /** @return array<string, mixed> */
        public function get_attributes(string $context = 'view'): array
        {
            return [];
        }
    }
}

if (! class_exists('WC_Product_Attribute')) {
    class WC_Product_Attribute
    {
        public function get_name(): string
        {
            return '';
        }

        public function is_taxonomy(): bool
        {
            return false;
        }

        /** @return array<int, string> */
        public function get_options(): array
        {
            return [];
        }
    }
}

if (! class_exists('WC_Cart')) {
    class WC_Cart
    {
        /** @return array<string, mixed> */
        public function get_cart(): array
        {
            return [];
        }

        public function get_subtotal(): float
        {
            return 0.0;
        }

        public function get_subtotal_tax(): float
        {
            return 0.0;
        }

        public function add_fee(string $name, float $amount, bool $taxable = false): void
        {
        }
    }
}

if (! class_exists('WC_Order_Item_Product')) {
    class WC_Order_Item_Product
    {
        /** @return WC_Product|null */
        public function get_product()
        {
            return null;
        }

        public function get_quantity(): int
        {
            return 1;
        }
    }
}

if (! class_exists('WC_Order')) {
    class WC_Order
    {
        public function get_id(): int
        {
            return 0;
        }

        /** @return array<int, mixed> */
        public function get_items(string $types = 'line_item'): array
        {
            return [];
        }

        /** @return array<int, mixed> */
        public function get_fees(): array
        {
            return [];
        }

        /** @return mixed */
        public function get_meta(string $key, bool $single = true)
        {
            return '';
        }

        public function update_meta_data(string $key, mixed $value): void
        {
        }

        public function save(): int
        {
            return 0;
        }
    }
}
