<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Compare;

/**
 * Storage contract for the compare engine. The host plugin implements this
 * against its own table / option store. Items are addressed by product id plus
 * exactly one owner: a logged-in `$userId` or a guest `$sessionId`. The list is
 * expected to be returned oldest-first so `removeOldest()` can enforce the cap.
 */
interface CompareRepository
{
    public function add(int $productId, ?int $userId, ?string $sessionId): void;

    public function remove(int $productId, ?int $userId, ?string $sessionId): void;

    public function exists(int $productId, ?int $userId, ?string $sessionId): bool;

    public function count(?int $userId, ?string $sessionId): int;

    /**
     * Drop the oldest stored item (used when the max-items cap is reached).
     */
    public function removeOldest(?int $userId, ?string $sessionId): void;

    public function clear(?int $userId, ?string $sessionId): void;

    /**
     * Ordered (oldest-first) list of stored product ids for the given owner.
     *
     * @return list<int>
     */
    public function findProductIds(?int $userId, ?string $sessionId): array;

    /**
     * Reassign a guest session's items to a user (called on login).
     */
    public function transferSessionToUser(string $sessionId, int $userId): void;
}
