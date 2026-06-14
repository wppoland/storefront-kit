<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Tests\Badge;

use Brain\Monkey\Functions;
use Mockery;
use ReflectionMethod;
use WC_Product;
use WPPoland\StorefrontKit\Badge\Badge;
use WPPoland\StorefrontKit\Badge\BadgeEngine;
use WPPoland\StorefrontKit\Tests\TestCase;

/**
 * @covers \WPPoland\StorefrontKit\Badge\BadgeEngine
 * @covers \WPPoland\StorefrontKit\Badge\Badge
 */
final class BadgeEngineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('sanitize_key')->alias(static fn ($k): string => strtolower(preg_replace('/[^a-z0-9_]/i', '', (string) $k)));
        // apply_filters is a pass-through in unit context (no host filters).
        Functions\when('apply_filters')->alias(static fn (string $hook, $value) => $value);
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $meta
     */
    private function engine(array $settings, array $meta = [], bool $enabled = true): BadgeEngine
    {
        return new BadgeEngine(
            'badges',
            ['sale' => 'Sale', 'new' => 'New', 'out_of_stock' => 'Sold out'],
            ['manual_text' => 'm_text', 'manual_style' => 'm_style', 'secondary_text' => 's_text'],
            static fn (): bool => $enabled,
            static fn (): array => $settings,
            static fn (WC_Product $p, string $key): mixed => $meta[$key] ?? '',
            static function (string $t, array $c): void {
            },
        );
    }

    /**
     * A product mock with sane defaults; override specific expectations after.
     */
    private function product(): WC_Product&Mockery\MockInterface
    {
        $product = Mockery::mock(WC_Product::class);
        $product->shouldReceive('is_on_sale')->andReturn(false)->byDefault();
        $product->shouldReceive('is_in_stock')->andReturn(true)->byDefault();
        $product->shouldReceive('managing_stock')->andReturn(false)->byDefault();
        $product->shouldReceive('get_total_sales')->andReturn(0)->byDefault();
        $product->shouldReceive('get_shipping_class')->andReturn('')->byDefault();
        $product->shouldReceive('get_date_created')->andReturn(null)->byDefault();

        return $product;
    }

    public function testReturnsNoBadgesWhenDisabled(): void
    {
        self::assertSame([], $this->engine([], [], enabled: false)->getBadges($this->product()));
    }

    public function testSelectsSaleBadgeWhenOnSale(): void
    {
        $product = $this->product();
        $product->shouldReceive('is_on_sale')->andReturn(true);

        $badges = $this->engine(['show_sale_badge' => true])->getBadges($product);

        self::assertCount(1, $badges);
        self::assertSame('Sale', $badges[0]->text);
        self::assertSame('warning', $badges[0]->style);
    }

    public function testManualBadgeUsesMetaStyleOverDefault(): void
    {
        $badges = $this->engine(
            ['show_manual_badge' => true, 'manual_badge_style' => 'accent'],
            ['m_text' => 'Limited', 'm_style' => 'danger'],
        )->getBadges($this->product());

        self::assertCount(1, $badges);
        self::assertSame('Limited', $badges[0]->text);
        self::assertSame('danger', $badges[0]->style);
    }

    public function testDedupesIdenticalBadges(): void
    {
        // Manual and secondary both produce the same text+style => collapse to 1.
        $badges = $this->engine(
            ['show_manual_badge' => true, 'show_secondary_badge' => true, 'manual_badge_style' => 'neutral', 'secondary_badge_style' => 'neutral'],
            ['m_text' => 'Dup', 's_text' => 'Dup'],
        )->getBadges($this->product());

        self::assertCount(1, $badges);
        self::assertSame('Dup', $badges[0]->text);
    }

    public function testCapsBadgesPerContext(): void
    {
        $product = $this->product();
        $product->shouldReceive('is_on_sale')->andReturn(true);
        $product->shouldReceive('is_in_stock')->andReturn(false);

        // Manual + secondary + sale + out_of_stock = 4 candidates; cap loop to 2.
        $badges = $this->engine(
            [
                'show_manual_badge' => true,
                'show_secondary_badge' => true,
                'show_sale_badge' => true,
                'show_out_of_stock_badge' => true,
                'max_badges_loop' => 2,
                'manual_badge_style' => 'a',
                'secondary_badge_style' => 'b',
            ],
            ['m_text' => 'M', 's_text' => 'S'],
        )->getBadges($product, 'loop');

        self::assertCount(2, $badges);
        // Priority order: manual first.
        self::assertSame('M', $badges[0]->text);
    }

    public function testOutOfStockBadgeWhenNotInStock(): void
    {
        $product = $this->product();
        $product->shouldReceive('is_in_stock')->andReturn(false);

        $badges = $this->engine(['show_out_of_stock_badge' => true])->getBadges($product);

        self::assertCount(1, $badges);
        self::assertSame('Sold out', $badges[0]->text);
        self::assertSame('neutral', $badges[0]->style);
    }

    public function testDiscountPercentBadgeComputedFromPrices(): void
    {
        $product = $this->product();
        $product->shouldReceive('is_on_sale')->andReturn(true);
        $product->shouldReceive('get_regular_price')->andReturn('100');
        $product->shouldReceive('get_sale_price')->andReturn('75');

        $badges = $this->engine([
            'show_sale_badge' => false,
            'show_discount_percent_badge' => true,
        ])->getBadges($product);

        self::assertCount(1, $badges);
        self::assertSame('-25%', $badges[0]->text);
        self::assertSame('danger', $badges[0]->style);
    }

    public function testGetDiscountPercentGuardsInvalidPrices(): void
    {
        $engine = $this->engine([]);
        $method = new ReflectionMethod(BadgeEngine::class, 'getDiscountPercent');

        $noSale = $this->product();
        $noSale->shouldReceive('get_regular_price')->andReturn('100');
        $noSale->shouldReceive('get_sale_price')->andReturn('0');
        self::assertSame(0, $method->invoke($engine, $noSale));

        // Sale >= regular must not yield a positive (or negative) discount.
        $inverted = $this->product();
        $inverted->shouldReceive('get_regular_price')->andReturn('50');
        $inverted->shouldReceive('get_sale_price')->andReturn('60');
        self::assertSame(0, $method->invoke($engine, $inverted));
    }

    public function testBadgeDedupeKeyCombinesTextAndStyle(): void
    {
        self::assertSame('Sale|warning', (new Badge('Sale', 'warning'))->dedupeKey());
    }
}
