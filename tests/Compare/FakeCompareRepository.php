<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Tests\Compare;

use WPPoland\StorefrontKit\Compare\CompareRepository;

/**
 * In-memory {@see CompareRepository} double.
 *
 * Items are keyed per owner (user id or guest session id) and stored
 * oldest-first, mirroring the contract the engine relies on for add/remove/
 * exists/dedup and removeOldest() cap enforcement.
 */
final class FakeCompareRepository implements CompareRepository
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

        // Dedup: never store the same product id twice for one owner.
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

    public function count(?int $userId, ?string $sessionId): int
    {
        return count($this->store[$this->key($userId, $sessionId)] ?? []);
    }

    public function removeOldest(?int $userId, ?string $sessionId): void
    {
        $key = $this->key($userId, $sessionId);

        if (! empty($this->store[$key])) {
            array_shift($this->store[$key]);
        }
    }

    public function clear(?int $userId, ?string $sessionId): void
    {
        unset($this->store[$this->key($userId, $sessionId)]);
    }

    public function findProductIds(?int $userId, ?string $sessionId): array
    {
        return $this->store[$this->key($userId, $sessionId)] ?? [];
    }

    public function transferSessionToUser(string $sessionId, int $userId): void
    {
        $from = $this->key(null, $sessionId);
        $to = $this->key($userId, null);

        foreach ($this->store[$from] ?? [] as $id) {
            $this->add($id, $userId, null);
        }

        unset($this->store[$from]);
    }
}
