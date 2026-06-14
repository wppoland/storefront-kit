<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Tests\Compare;

use Brain\Monkey\Functions;
use WPPoland\StorefrontKit\Compare\CompareEngine;
use WPPoland\StorefrontKit\Tests\TestCase;

/**
 * Covers the comparison engine's pure logic (difference highlighting, limit
 * notice formatting, gating) plus the add/remove/exists/dedup/cap behaviour of
 * the repository contract via an in-memory double.
 *
 * @covers \WPPoland\StorefrontKit\Compare\CompareEngine
 * @covers \WPPoland\StorefrontKit\Tests\Compare\FakeCompareRepository
 */
final class CompareEngineTest extends TestCase
{
    private FakeCompareRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new FakeCompareRepository();
        Functions\when('wp_strip_all_tags')->alias(static fn ($s): string => strip_tags((string) $s));
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function engine(array $settings = [], bool $enabled = true): CompareEngine
    {
        return new CompareEngine(
            $this->repo,
            'cmp_toggle',
            'cmp_clear',
            'cmp_nonce',
            'CmpData',
            'cmp-asset',
            'style.css',
            'script.js',
            '1.0',
            'compare',
            'cmp_guest',
            'loop-btn',
            'single-btn',
            'table',
            ['price' => 'Price', 'sku' => 'SKU'],
            ['add' => 'Add', 'remove' => 'Remove', 'limit_notice' => 'Max {limit} items'],
            static fn (): bool => $enabled,
            static fn (): array => $settings,
            static function (string $t, array $c): void {
            },
            static fn (string $t, array $c): string => '',
            static fn ($p, string $k): array => ['', ''],
        );
    }

    // --- Repository contract (the engine's add/remove/exists/dedup substrate) ---

    public function testRepositoryDedupesRepeatedAdds(): void
    {
        $this->repo->add(10, 1, null);
        $this->repo->add(10, 1, null);

        self::assertSame([10], $this->repo->findProductIds(1, null));
        self::assertSame(1, $this->repo->count(1, null));
    }

    public function testRepositoryAddRemoveExists(): void
    {
        $this->repo->add(10, 1, null);
        $this->repo->add(20, 1, null);
        self::assertTrue($this->repo->exists(10, 1, null));

        $this->repo->remove(10, 1, null);
        self::assertFalse($this->repo->exists(10, 1, null));
        self::assertSame([20], $this->repo->findProductIds(1, null));
    }

    public function testRepositoryRemoveOldestEnforcesCapOrdering(): void
    {
        $this->repo->add(1, 1, null);
        $this->repo->add(2, 1, null);
        $this->repo->add(3, 1, null);

        // Oldest-first: removeOldest() drops product 1.
        $this->repo->removeOldest(1, null);
        self::assertSame([2, 3], $this->repo->findProductIds(1, null));
    }

    public function testRepositoryScopesGuestAndUserOwnersSeparately(): void
    {
        $this->repo->add(5, null, 'guest-abc');
        $this->repo->add(9, 7, null);

        self::assertTrue($this->repo->exists(5, null, 'guest-abc'));
        self::assertFalse($this->repo->exists(5, 7, null));
        self::assertFalse($this->repo->exists(9, null, 'guest-abc'));
    }

    public function testRepositoryTransferGuestSessionToUserDedupes(): void
    {
        $this->repo->add(5, 7, null);          // user already has 5
        $this->repo->add(5, null, 'guest-abc'); // guest also has 5
        $this->repo->add(8, null, 'guest-abc');

        $this->repo->transferSessionToUser('guest-abc', 7);

        // Guest items merged into user, deduped, guest cleared.
        self::assertSame([5, 8], $this->repo->findProductIds(7, null));
        self::assertSame([], $this->repo->findProductIds(null, 'guest-abc'));
    }

    // --- Pure engine logic ---

    public function testCalculateDifferencesFlagsRowsWithDistinctValues(): void
    {
        $rows = [
            ['key' => 'price', 'label' => 'Price', 'values' => [], 'text_values' => ['$10', '$20']],
            ['key' => 'sku', 'label' => 'SKU', 'values' => [], 'text_values' => ['ABC', 'ABC']],
        ];

        $diff = $this->engine()->calculateDifferences($rows);

        self::assertTrue($diff['price']);
        self::assertFalse($diff['sku']);
    }

    public function testCalculateDifferencesIgnoresHtmlAndWhitespace(): void
    {
        $rows = [
            ['key' => 'desc', 'label' => 'Desc', 'values' => [], 'text_values' => ['<b>Same</b>', ' Same ']],
        ];

        // After stripping tags + trimming, both normalise to "Same" => no diff.
        self::assertFalse($this->engine()->calculateDifferences($rows)['desc']);
    }

    public function testGetLimitNoticeTextInterpolatesLimit(): void
    {
        self::assertSame('Max 4 items', $this->engine()->getLimitNoticeText(4));
    }

    public function testCanUseRespectsEnabledFlagAndGuestSetting(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);

        self::assertFalse($this->engine([], enabled: false)->canUse());
        self::assertTrue($this->engine(['allow_guests' => true])->canUse());
        self::assertFalse($this->engine(['allow_guests' => false])->canUse());
    }
}
