<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Tests\Pricing;

use Brain\Monkey\Functions;
use Mockery;
use ReflectionMethod;
use WC_Cart;
use WC_Product;
use WPPoland\StorefrontKit\Pricing\DynamicPricingEngine;
use WPPoland\StorefrontKit\Pricing\PriceTier;
use WPPoland\StorefrontKit\Tests\TestCase;

/**
 * @covers \WPPoland\StorefrontKit\Pricing\DynamicPricingEngine
 */
final class DynamicPricingEngineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Two decimal places, matching a typical WooCommerce store.
        Functions\when('wc_get_price_decimals')->justReturn(2);
        Functions\when('wc_price')->alias(static fn ($price): string => '$' . number_format((float) $price, 2));
    }

    /**
     * @param list<PriceTier> $tiers
     */
    private function engine(array $tiers, bool $enabled = true): DynamicPricingEngine
    {
        return new DynamicPricingEngine(
            'price-table',
            ['quantity' => 'Qty', 'discount' => 'Off', 'price' => 'Price', 'heading' => 'Bulk'],
            static fn (): bool => $enabled,
            static fn (): array => $tiers,
            static function (string $name, array $ctx): void {
            },
        );
    }

    public function testDiscountedPriceRoundsToStoreDecimals(): void
    {
        $engine = $this->engine([]);
        $method = new ReflectionMethod($engine, 'discountedPrice');

        // 100 * (1 - 0.333) = 66.7  ->  100 - 33.3% = 66.70
        self::assertSame(66.7, $method->invoke($engine, 100.0, 33.3));
        // Rounds to 2 dp: 9.99 * 0.9 = 8.991 -> 8.99
        self::assertSame(8.99, $method->invoke($engine, 9.99, 10.0));
    }

    public function testDiscountedPriceClampsOutOfRangePercent(): void
    {
        $engine = $this->engine([]);
        $method = new ReflectionMethod($engine, 'discountedPrice');

        // Negative percent must NOT inflate the price above regular.
        self::assertSame(100.0, $method->invoke($engine, 100.0, -50.0));
        // >100% must NOT invert to a negative price.
        self::assertSame(0.0, $method->invoke($engine, 100.0, 150.0));
    }

    public function testBestTierForPicksHighestMatchingThreshold(): void
    {
        $tiers = [
            new PriceTier(3, 5.0),
            new PriceTier(10, 20.0),
            new PriceTier(5, 10.0),
        ];
        $engine = $this->engine($tiers);
        $method = new ReflectionMethod($engine, 'bestTierFor');

        // qty 12 matches all three; deepest (highest threshold) tier wins.
        $best = $method->invoke($engine, 12, $tiers);
        self::assertInstanceOf(PriceTier::class, $best);
        self::assertSame(10, $best->minQuantity);
        self::assertSame(20.0, $best->discountPercent);

        // qty 6 matches the 3 and 5 tiers; the 5 tier wins.
        self::assertSame(5, $method->invoke($engine, 6, $tiers)->minQuantity);
    }

    public function testBestTierForReturnsNullBelowAnyThreshold(): void
    {
        $tiers = [new PriceTier(5, 10.0)];
        $engine = $this->engine($tiers);
        $method = new ReflectionMethod($engine, 'bestTierFor');

        self::assertNull($method->invoke($engine, 4, $tiers));
    }

    public function testBuildRowsSortedByQuantityWithDiscountMath(): void
    {
        $tiers = [
            new PriceTier(10, 20.0),
            new PriceTier(3, 5.0),
        ];
        $rows = $this->engine($tiers)->buildRows(100.0, $tiers);

        self::assertCount(2, $rows);
        // Ascending min_quantity.
        self::assertSame(3, $rows[0]['min_quantity']);
        self::assertSame(10, $rows[1]['min_quantity']);
        // Discount math.
        self::assertSame(95.0, $rows[0]['price']);
        self::assertSame(80.0, $rows[1]['price']);
        self::assertSame('$80.00', $rows[1]['price_html']);
    }

    public function testApplyTiersSetsDiscountedPriceFromRegularPrice(): void
    {
        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_doing_ajax')->justReturn(false);

        $product = Mockery::mock(WC_Product::class);
        $product->shouldReceive('get_regular_price')->andReturn('100');
        // Best tier for qty 10 is 25% off => 75.00.
        $product->shouldReceive('set_price')->once()->with('75');

        $cart = Mockery::mock(WC_Cart::class);
        $cart->shouldReceive('get_cart')->andReturn([
            'line' => ['quantity' => 10, 'data' => $product],
        ]);

        $this->engine([new PriceTier(10, 25.0)])->applyTiers($cart);
    }

    public function testApplyTiersRecomputesIdempotentlyFromRegularPriceNotSalePrice(): void
    {
        // Hardening behaviour: the engine always recomputes from the REGULAR
        // price, so running it twice (as WooCommerce does) yields the same
        // discounted price rather than compounding the discount.
        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_doing_ajax')->justReturn(false);

        $product = Mockery::mock(WC_Product::class);
        // Regular price is stable across calls; a previously-set lower price is
        // ignored because the tier is computed off get_regular_price().
        $product->shouldReceive('get_regular_price')->andReturn('200');
        // 10% off 200 = 180, both times — never 180 then 162.
        $product->shouldReceive('set_price')->twice()->with('180');

        $cart = Mockery::mock(WC_Cart::class);
        $cart->shouldReceive('get_cart')->andReturn([
            'line' => ['quantity' => 5, 'data' => $product],
        ]);

        $engine = $this->engine([new PriceTier(5, 10.0)]);
        $engine->applyTiers($cart);
        $engine->applyTiers($cart);
    }

    public function testApplyTiersSkipsLinesBelowAnyTierThreshold(): void
    {
        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_doing_ajax')->justReturn(false);

        $product = Mockery::mock(WC_Product::class);
        $product->shouldReceive('get_regular_price')->andReturn('100');
        $product->shouldReceive('set_price')->never();

        $cart = Mockery::mock(WC_Cart::class);
        $cart->shouldReceive('get_cart')->andReturn([
            'line' => ['quantity' => 2, 'data' => $product],
        ]);

        $this->engine([new PriceTier(5, 10.0)])->applyTiers($cart);
    }

    public function testApplyTiersDoesNothingWhenDisabled(): void
    {
        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_doing_ajax')->justReturn(false);

        $cart = Mockery::mock(WC_Cart::class);
        $cart->shouldReceive('get_cart')->never();

        $this->engine([new PriceTier(5, 10.0)], enabled: false)->applyTiers($cart);
    }
}
