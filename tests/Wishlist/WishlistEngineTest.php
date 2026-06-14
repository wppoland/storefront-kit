<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Tests\Wishlist;

use Brain\Monkey\Functions;
use Mockery;
use WC_Product;
use WPPoland\StorefrontKit\Tests\TestCase;
use WPPoland\StorefrontKit\Wishlist\WishlistEngine;

/**
 * @covers \WPPoland\StorefrontKit\Wishlist\WishlistEngine
 * @covers \WPPoland\StorefrontKit\Tests\Wishlist\FakeWishlistRepository
 */
final class WishlistEngineTest extends TestCase
{
    private FakeWishlistRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new FakeWishlistRepository();
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function engine(array $settings = [], bool $enabled = true): WishlistEngine
    {
        return new WishlistEngine(
            $this->repo,
            'wl_toggle',
            'wl_nonce',
            'WlData',
            'wl-asset',
            'style.css',
            'script.js',
            '1.0',
            'wishlist',
            'wl_guest',
            'loop-btn',
            'single-btn',
            'account',
            ['add' => 'Add to wishlist', 'remove' => 'Remove from wishlist'],
            static fn (): bool => $enabled,
            static fn (): array => $settings,
            static function (string $t, array $c): void {
            },
            static fn (string $t, array $c): string => '',
        );
    }

    private function product(int $id, bool $variable = false): WC_Product
    {
        $product = Mockery::mock(WC_Product::class);
        $product->shouldReceive('get_id')->andReturn($id);
        $product->shouldReceive('is_type')->with('variable')->andReturn($variable);

        return $product;
    }

    // --- Repository contract ---

    public function testRepositoryDedupesAndTogglesMembership(): void
    {
        $this->repo->add(1, 5, null);
        $this->repo->add(1, 5, null);
        self::assertSame([1], $this->repo->findProductIds(5, null));

        $this->repo->remove(1, 5, null);
        self::assertFalse($this->repo->exists(1, 5, null));
    }

    public function testRepositoryTransferMergesGuestIntoUserWithoutDuplicates(): void
    {
        $this->repo->add(1, 5, null);
        $this->repo->add(1, null, 'guest');
        $this->repo->add(2, null, 'guest');

        $this->repo->transferSessionToUser('guest', 5);

        self::assertSame([1, 2], $this->repo->findProductIds(5, null));
        self::assertSame([], $this->repo->findProductIds(null, 'guest'));
    }

    // --- Engine logic ---

    public function testGetButtonDataReflectsMembershipForSimpleProduct(): void
    {
        Functions\when('get_current_user_id')->justReturn(5);
        $this->repo->add(42, 5, null);

        $data = $this->engine()->getButtonData($this->product(42));

        self::assertSame(42, $data['product_id']);
        self::assertTrue($data['in_wishlist']);
        self::assertSame('Remove from wishlist', $data['label']);
        self::assertFalse($data['requires_variation']);
    }

    public function testGetButtonDataUsesAddLabelWhenAbsent(): void
    {
        Functions\when('get_current_user_id')->justReturn(5);

        $data = $this->engine()->getButtonData($this->product(99));

        self::assertFalse($data['in_wishlist']);
        self::assertSame('Add to wishlist', $data['label']);
    }

    public function testGetButtonDataNeverMarksVariableProductInWishlist(): void
    {
        // A variable parent product requires a chosen variation, so it is never
        // reported as in-wishlist regardless of stored state.
        Functions\when('get_current_user_id')->justReturn(5);
        $this->repo->add(7, 5, null);

        $data = $this->engine()->getButtonData($this->product(7, variable: true));

        self::assertTrue($data['requires_variation']);
        self::assertFalse($data['in_wishlist']);
    }

    public function testCanUseRespectsEnabledAndGuestSetting(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);

        self::assertFalse($this->engine([], enabled: false)->canUse());
        self::assertTrue($this->engine(['allow_guests' => true])->canUse());
        self::assertFalse($this->engine(['allow_guests' => false])->canUse());
    }
}
