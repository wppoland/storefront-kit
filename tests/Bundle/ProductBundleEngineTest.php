<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Tests\Bundle;

use Brain\Monkey\Functions;
use Mockery;
use WC_Cart;
use WC_Product;
use WPPoland\StorefrontKit\Bundle\ProductBundleEngine;
use WPPoland\StorefrontKit\Tests\TestCase;

/**
 * @covers \WPPoland\StorefrontKit\Bundle\ProductBundleEngine
 */
final class ProductBundleEngineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('absint')->alias(static fn ($v): int => abs((int) $v));
        Functions\when('wc_get_price_decimals')->justReturn(2);
    }

    /**
     * @param array<string, mixed> $settings
     * @param \Closure(WC_Product): mixed $productMeta
     */
    private function engine(array $settings, \Closure $productMeta): ProductBundleEngine
    {
        return new ProductBundleEngine(
            'bundle_add',
            'bundle_nonce',
            'sk_bundle_parent',
            'bundle-box',
            ['box_title' => 'Bundle', 'add_bundle' => 'Add', 'fee_label' => 'Bundle saving', 'add_failed' => 'Failed'],
            static fn (): bool => true,
            static fn (): array => $settings,
            $productMeta,
            static function (string $t, array $c): void {
            },
        );
    }

    private function product(int $id): WC_Product
    {
        $product = Mockery::mock(WC_Product::class);
        $product->shouldReceive('get_id')->andReturn($id);

        return $product;
    }

    public function testGetBundleNormalisesItemsDroppingSelfZeroAndDuplicates(): void
    {
        $meta = ['items' => [10, 10, 0, 5, '7'], 'discount_percent' => 15.0];
        $engine = $this->engine([], static fn (WC_Product $p): array => $meta);

        $bundle = $engine->getBundle($this->product(5));

        // self id (5) removed, 0 dropped, duplicate 10 collapsed, '7' cast to 7.
        self::assertSame([10, 7], $bundle['items']);
        self::assertSame(15.0, $bundle['discount_percent']);
    }

    public function testGetBundleClampsDiscountPercent(): void
    {
        $engine = $this->engine([], static fn (WC_Product $p): array => ['items' => [9], 'discount_percent' => 150.0]);
        self::assertSame(100.0, $engine->getBundle($this->product(1))['discount_percent']);

        $engine = $this->engine([], static fn (WC_Product $p): array => ['items' => [9], 'discount_percent' => -20.0]);
        self::assertSame(0.0, $engine->getBundle($this->product(1))['discount_percent']);
    }

    public function testGetBundleReturnsEmptyForNonArrayMeta(): void
    {
        $engine = $this->engine([], static fn (WC_Product $p) => null);
        self::assertSame(['items' => [], 'discount_percent' => 0.0], $engine->getBundle($this->product(1)));
    }

    public function testBundleProductIdsIncludeParentFirstAndAreDeduped(): void
    {
        $engine = $this->engine([], static fn (WC_Product $p): array => ['items' => [20, 30, 20], 'discount_percent' => 0]);

        self::assertSame([5, 20, 30], $engine->bundleProductIds($this->product(5)));
    }

    public function testApplyBundleFeeAddsSinglePercentageFee(): void
    {
        // Fixed-vs-percentage: in 'fee' mode the discount is one cart fee equal
        // to the percentage of the bundled line totals.
        $meta = ['items' => [], 'discount_percent' => 10.0];
        $parent = $this->product(100);
        Functions\when('wc_get_product')->justReturn($parent);

        $line = Mockery::mock(WC_Product::class);
        $line->shouldReceive('get_price')->with('edit')->andReturn(50.0);

        $cart = Mockery::mock(WC_Cart::class);
        $cart->shouldReceive('get_cart')->andReturn([
            'a' => ['data' => $line, 'quantity' => 2, 'sk_bundle_parent' => 100],
        ]);
        // 2 * 50 = 100 line total; 10% = 10.00 fee (negative).
        $cart->shouldReceive('add_fee')->once()->with('Bundle saving', -10.0);

        $engine = $this->engine(['discount_mode' => 'fee'], static fn (WC_Product $p): array => $meta);
        $engine->applyBundleFee($cart);
    }

    public function testApplyBundleFeeIgnoredInPerItemMode(): void
    {
        $cart = Mockery::mock(WC_Cart::class);
        $cart->shouldReceive('get_cart')->never();
        $cart->shouldReceive('add_fee')->never();

        $engine = $this->engine(['discount_mode' => 'per_item'], static fn (WC_Product $p): array => ['items' => [], 'discount_percent' => 10.0]);
        $engine->applyBundleFee($cart);
    }

    public function testApplyPerItemDiscountReducesEachBundledLinePrice(): void
    {
        Functions\when('did_action')->justReturn(1);

        $meta = ['items' => [], 'discount_percent' => 20.0];
        $parent = $this->product(100);
        Functions\when('wc_get_product')->justReturn($parent);

        $line = Mockery::mock(WC_Product::class);
        $line->shouldReceive('get_price')->with('edit')->andReturn(80.0);
        // 80 * (1 - 0.2) = 64.
        $line->shouldReceive('set_price')->once()->with('64');

        $cart = Mockery::mock(WC_Cart::class);
        $cart->shouldReceive('get_cart')->andReturn([
            'a' => ['data' => $line, 'quantity' => 1, 'sk_bundle_parent' => 100],
        ]);

        $engine = $this->engine(['discount_mode' => 'per_item'], static fn (WC_Product $p): array => $meta);
        $engine->applyPerItemDiscount($cart);
    }

    public function testApplyPerItemDiscountSkippedOnRepeatedTotalsCalculation(): void
    {
        // No double-discount: WooCommerce fires the totals hook multiple times.
        // After the first pass did_action() > 1 and the engine must bail so the
        // already-reduced price is not discounted again.
        Functions\when('did_action')->justReturn(2);

        $cart = Mockery::mock(WC_Cart::class);
        $cart->shouldReceive('get_cart')->never();

        $engine = $this->engine(['discount_mode' => 'per_item'], static fn (WC_Product $p): array => ['items' => [], 'discount_percent' => 20.0]);
        $engine->applyPerItemDiscount($cart);
    }

    public function testApplyPerItemDiscountIgnoredInFeeMode(): void
    {
        $cart = Mockery::mock(WC_Cart::class);
        $cart->shouldReceive('get_cart')->never();

        $engine = $this->engine(['discount_mode' => 'fee'], static fn (WC_Product $p): array => ['items' => [], 'discount_percent' => 20.0]);
        $engine->applyPerItemDiscount($cart);
    }
}
