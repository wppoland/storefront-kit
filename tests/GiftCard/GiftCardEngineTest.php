<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Tests\GiftCard;

use Brain\Monkey\Functions;
use Mockery;
use ReflectionMethod;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use WPPoland\StorefrontKit\GiftCard\GiftCardEngine;
use WPPoland\StorefrontKit\Tests\TestCase;

/**
 * @covers \WPPoland\StorefrontKit\GiftCard\GiftCardEngine
 */
final class GiftCardEngineTest extends TestCase
{
    private FakeGiftCardRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new FakeGiftCardRepository();

        // Common WP/WC function stubs used across the gift-card flow.
        Functions\when('wp_strip_all_tags')->alias(static fn ($s): string => (string) $s);
        Functions\when('wc_price')->alias(static fn ($p): string => '$' . number_format((float) $p, 2));
        Functions\when('wp_mail')->justReturn(true);
        Functions\when('is_email')->alias(static fn ($e): bool => is_string($e) && str_contains($e, '@'));
        Functions\when('sanitize_email')->alias(static fn ($e): string => (string) $e);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function engine(array $settings = []): GiftCardEngine
    {
        return new GiftCardEngine(
            $this->repo,
            'sk_gc_code',
            'gc_field',
            'gc_nonce',
            'gc-field-tpl',
            [
                'fee_label' => 'Gift card {code}',
                'email_subject' => 'Your card {code}',
                'email_body' => 'Code {code} worth {amount}',
            ],
            static fn (): bool => true,
            static fn (): array => $settings,
            static fn (WC_Product $p): bool => true,
            static fn (WC_Order_Item_Product $i): array => [50.0, 'buyer@example.com'],
            static function (string $t, array $c): void {
            },
        );
    }

    public function testGenerateCodeUppercasesAndPrefixesAndStrips(): void
    {
        // wp_generate_password returns lowercase-with-symbols; the engine
        // uppercases, then normalize() strips anything but [A-Z0-9-].
        Functions\when('wp_generate_password')->justReturn('ab!cd_12');

        $code = $this->engine(['code_prefix' => 'GC-'])->generateCode();

        // 'GC-' . strtoupper('ab!cd_12') = 'GC-AB!CD_12' -> strip -> 'GC-ABCD12'
        self::assertSame('GC-ABCD12', $code);
    }

    public function testIssueUniqueCardRetriesOnDuplicateThenSucceeds(): void
    {
        // Two distinct candidate codes; the first collides at insert time
        // (UNIQUE race), the engine must catch the exception and regenerate.
        $codes = ['AAAA1111', 'BBBB2222'];
        Functions\when('wp_generate_password')->alias(static function () use (&$codes): string {
            return array_shift($codes) ?? 'ZZZZ9999';
        });

        $this->repo->collideOnceOn('AAAA1111');

        $method = new ReflectionMethod(GiftCardEngine::class, 'issueUniqueCard');
        $issued = $method->invoke($this->engine(), 25.0, 'r@example.com', 7);

        self::assertSame('BBBB2222', $issued);
        // Confirm the first (colliding) code was actually attempted, proving the
        // retry path ran rather than just skipping it.
        self::assertSame(['AAAA1111', 'BBBB2222'], $this->repo->issuedCodeAttempts);
        self::assertNotNull($this->repo->findByCode('BBBB2222'));
    }

    public function testIssueUniqueCardWidensEntropyAfterFiveAttempts(): void
    {
        // Track the password length requested per attempt. The first five
        // attempts use the normal width (12); from the sixth the engine widens
        // to 20. We force the first five candidates to be pre-existing so the
        // engine is pushed past the widening threshold.
        $lengths = [];
        $counter = 0;
        Functions\when('wp_generate_password')->alias(static function (int $len) use (&$lengths, &$counter): string {
            $lengths[] = $len;

            return 'CODE' . str_pad((string) $counter++, 4, '0', STR_PAD_LEFT);
        });

        // Pre-seed the five normal-width candidates so findByCode() rejects them.
        foreach (['CODE0000', 'CODE0001', 'CODE0002', 'CODE0003', 'CODE0004'] as $taken) {
            $this->repo->cards[] = (object) [
                'id' => 0,
                'code' => $taken,
                'balance' => 1.0,
                'recipient_email' => 'x@example.com',
                'order_id' => 0,
            ];
        }

        $method = new ReflectionMethod(GiftCardEngine::class, 'issueUniqueCard');
        $issued = $method->invoke($this->engine(), 25.0, 'r@example.com', 7);

        // The sixth candidate (CODE0005, generated at width 20) is the one issued.
        self::assertSame('CODE0005', $issued);
        // First five attempts requested width 12, the sixth requested width 20.
        self::assertSame([12, 12, 12, 12, 12, 20], $lengths);
    }

    public function testIssueUniqueCardReturnsEmptyWhenAllAttemptsExhausted(): void
    {
        // Every candidate already exists -> no code can be issued.
        Functions\when('wp_generate_password')->justReturn('SAME');
        $this->repo->cards[] = (object) [
            'id' => 0,
            'code' => 'SAME',
            'balance' => 1.0,
            'recipient_email' => 'x@example.com',
            'order_id' => 0,
        ];

        $method = new ReflectionMethod(GiftCardEngine::class, 'issueUniqueCard');
        self::assertSame('', $method->invoke($this->engine(), 25.0, 'r@example.com', 7));
    }

    public function testRedeemDecrementsBalanceByAppliedFees(): void
    {
        // Seed a redeemable card.
        $this->repo->issue('REDEEM01', 100.0, 'h@example.com', 1);

        $fee = Mockery::mock();
        $fee->shouldReceive('get_total')->andReturn(-30.0);

        $order = Mockery::mock(WC_Order::class);
        $order->shouldReceive('get_meta')->with('sk_gc_code')->andReturn('REDEEM01');
        $order->shouldReceive('get_fees')->andReturn([$fee]);

        $method = new ReflectionMethod(GiftCardEngine::class, 'redeemAppliedCard');
        $method->invoke($this->engine(), $order);

        self::assertSame(70.0, $this->repo->findByCode('REDEEM01')->balance);
    }

    public function testRedeemNeverDrivesBalanceNegative(): void
    {
        $this->repo->issue('REDEEM02', 20.0, 'h@example.com', 1);

        $fee = Mockery::mock();
        $fee->shouldReceive('get_total')->andReturn(-50.0);

        $order = Mockery::mock(WC_Order::class);
        $order->shouldReceive('get_meta')->with('sk_gc_code')->andReturn('REDEEM02');
        $order->shouldReceive('get_fees')->andReturn([$fee]);

        $method = new ReflectionMethod(GiftCardEngine::class, 'redeemAppliedCard');
        $method->invoke($this->engine(), $order);

        self::assertSame(0.0, $this->repo->findByCode('REDEEM02')->balance);
    }

    public function testHandleOrderCompletedIsIdempotent(): void
    {
        // First completion: processed flag absent -> issues a card. A second
        // completion must short-circuit on the flag and NOT issue again.
        Functions\when('wp_generate_password')->justReturn('ISSUE001');

        $product = Mockery::mock(WC_Product::class);

        $item = Mockery::mock(WC_Order_Item_Product::class);
        $item->shouldReceive('get_product')->andReturn($product);
        $item->shouldReceive('get_quantity')->andReturn(1);

        $order = Mockery::mock(WC_Order::class);
        $order->shouldReceive('get_id')->andReturn(99);
        $order->shouldReceive('get_items')->andReturn([$item]);
        // No redeem code on the order.
        $order->shouldReceive('get_meta')->with('sk_gc_code')->andReturn('');
        $order->shouldReceive('update_meta_data');
        $order->shouldReceive('save');

        // First call: flag not yet set. Second call: flag is 'yes'.
        $order->shouldReceive('get_meta')->with('sk_gc_code_processed')
            ->andReturn('', 'yes');

        Functions\when('wc_get_order')->justReturn($order);

        $engine = $this->engine();
        $engine->handleOrderCompleted(99);
        $engine->handleOrderCompleted(99);

        // Exactly one card issued despite two completions.
        self::assertCount(1, $this->repo->cards);
    }
}
