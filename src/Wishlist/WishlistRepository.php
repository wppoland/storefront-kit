<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Wishlist;

/**
 * Storage contract for the wishlist engine. The host plugin implements this
 * against its own table / option store. Items are addressed by product id plus
 * exactly one owner: a logged-in `$userId` or a guest `$sessionId`.
 */
interface WishlistRepository
{
    public function add(int $productId, ?int $userId, ?string $sessionId): void;

    public function remove(int $productId, ?int $userId, ?string $sessionId): void;

    public function exists(int $productId, ?int $userId, ?string $sessionId): bool;

    /**
     * Ordered list of stored product ids for the given owner.
     *
     * @return list<int>
     */
    public function findProductIds(?int $userId, ?string $sessionId): array;

    /**
     * Reassign a guest session's items to a user (called on login).
     */
    public function transferSessionToUser(string $sessionId, int $userId): void;
}
