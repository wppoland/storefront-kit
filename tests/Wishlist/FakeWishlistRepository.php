<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Tests\Wishlist;

use WPPoland\StorefrontKit\Wishlist\WishlistRepository;

/**
 * In-memory {@see WishlistRepository} double exercising the add/remove/exists/
 * dedup and guest->user transfer contract the engine relies on.
 */
final class FakeWishlistRepository implements WishlistRepository
{
    /** @var array<string, list<int>> */
    private array $store = [];

    private function key(?int $userId, ?string $sessionId): string
    {
        return $userId !== null ? 'u:' . $userId : 's:' . (string) $sessionId;
    }

    public function add(int $productId, ?int $userId, ?string $sessionId): void
    {
        $key = $this->key($userId, $sessionId);
        $this->store[$key] ??= [];

        if (! in_array($productId, $this->store[$key], true)) {
            $this->store[$key][] = $productId;
        }
    }

    public function remove(int $productId, ?int $userId, ?string $sessionId): void
    {
        $key = $this->key($userId, $sessionId);

        if (! isset($this->store[$key])) {
            return;
        }

        $this->store[$key] = array_values(array_filter(
            $this->store[$key],
            static fn (int $id): bool => $id !== $productId,
        ));
    }

    public function exists(int $productId, ?int $userId, ?string $sessionId): bool
    {
        return in_array($productId, $this->store[$this->key($userId, $sessionId)] ?? [], true);
    }

    public function findProductIds(?int $userId, ?string $sessionId): array
    {
        return $this->store[$this->key($userId, $sessionId)] ?? [];
    }

    public function transferSessionToUser(string $sessionId, int $userId): void
    {
        $from = $this->key(null, $sessionId);

        foreach ($this->store[$from] ?? [] as $id) {
            $this->add($id, $userId, null);
        }

        unset($this->store[$from]);
    }
}
