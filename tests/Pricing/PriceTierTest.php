<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Tests\Pricing;

use PHPUnit\Framework\TestCase;
use WPPoland\StorefrontKit\Pricing\PriceTier;

/**
 * @covers \WPPoland\StorefrontKit\Pricing\PriceTier
 */
final class PriceTierTest extends TestCase
{
    public function testFromArrayBuildsTierFromValidRow(): void
    {
        $tier = PriceTier::fromArray(['min_qty' => '5', 'discount_percent' => '12.5']);

        self::assertInstanceOf(PriceTier::class, $tier);
        self::assertSame(5, $tier->minQuantity);
        self::assertSame(12.5, $tier->discountPercent);
    }

    public function testFromArrayRejectsZeroOrMissingQuantity(): void
    {
        self::assertNull(PriceTier::fromArray(['min_qty' => 0, 'discount_percent' => 10]));
        self::assertNull(PriceTier::fromArray(['discount_percent' => 10]));
    }

    public function testFromArrayRejectsZeroOrMissingDiscount(): void
    {
        self::assertNull(PriceTier::fromArray(['min_qty' => 5, 'discount_percent' => 0]));
        self::assertNull(PriceTier::fromArray(['min_qty' => 5]));
    }

    public function testAppliesToIsInclusiveAtThreshold(): void
    {
        $tier = new PriceTier(3, 10.0);

        self::assertFalse($tier->appliesTo(2));
        self::assertTrue($tier->appliesTo(3));
        self::assertTrue($tier->appliesTo(4));
    }
}
