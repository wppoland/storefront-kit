<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Tests\GiftCard;

use WPPoland\StorefrontKit\GiftCard\DuplicateGiftCardCodeException;
use WPPoland\StorefrontKit\GiftCard\GiftCardRepository;

/**
 * In-memory {@see GiftCardRepository} double.
 *
 * Models the host DB contract: a UNIQUE index on `code`. To exercise the
 * engine's collision-retry path, a set of codes can be pre-seeded as
 * "already taken at insert time"; the first `issue()` for such a code throws
 * {@see DuplicateGiftCardCodeException} exactly as a real UNIQUE violation
 * would, then frees it so a regenerated code can succeed.
 */
final class FakeGiftCardRepository implements GiftCardRepository
{
    /** @var array<int, object{id:int,code:string,balance:float,recipient_email:string,order_id:int}> */
    public array $cards = [];

    private int $nextId = 1;

    /** @var array<string, bool> Codes that throw a duplicate error on the next issue(). */
    private array $collideOnce = [];

    /** @var list<string> Order in which codes were handed to issue(). */
    public array $issuedCodeAttempts = [];

    /**
     * Mark a code so the next issue() of it throws (simulating a concurrent
     * insert winning the UNIQUE race between findByCode() and issue()).
     */
    public function collideOnceOn(string $code): void
    {
        $this->collideOnce[$code] = true;
    }

    public function issue(string $code, float $balance, string $recipientEmail, int $orderId): int
    {
        $this->issuedCodeAttempts[] = $code;

        if (isset($this->collideOnce[$code])) {
            unset($this->collideOnce[$code]);

            throw new DuplicateGiftCardCodeException('duplicate code: ' . $code);
        }

        if ($this->findByCode($code) !== null) {
            throw new DuplicateGiftCardCodeException('duplicate code: ' . $code);
        }

        $id = $this->nextId++;
        $this->cards[$id] = (object) [
            'id' => $id,
            'code' => $code,
            'balance' => $balance,
            'recipient_email' => $recipientEmail,
            'order_id' => $orderId,
        ];

        return $id;
    }

    public function findByCode(string $code): ?object
    {
        foreach ($this->cards as $card) {
            if ($card->code === $code) {
                return $card;
            }
        }

        return null;
    }

    public function updateBalance(int $id, float $balance): void
    {
        if (isset($this->cards[$id])) {
            $this->cards[$id]->balance = $balance;
        }
    }
}
