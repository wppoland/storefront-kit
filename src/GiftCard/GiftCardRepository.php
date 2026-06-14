<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\GiftCard;

/**
 * Storage contract for the gift-card engine. The host plugin implements this
 * against its own custom table (e.g. `{$wpdb->prefix}..._gift_cards`).
 *
 * Mirroring {@see \WPPoland\StorefrontKit\Waitlist\WaitlistRepository}, storage
 * stays in the consuming plugin so the library hard-codes no table name and no
 * `$wpdb` access. The host implementation is the place for the justified
 * `// phpcs:disable WordPress.DB.DirectDatabaseQuery.*` block around the
 * `$wpdb->prefix`-derived table name and the prepared queries.
 *
 * A gift card record is `{code, balance, recipient_email, order_id}`.
 */
interface GiftCardRepository
{
    /**
     * Persist a freshly issued gift card. Implementations must store the unique
     * `$code`, the starting `$balance`, the `$recipientEmail` and the source
     * `$orderId`, and return the new row id.
     */
    public function issue(string $code, float $balance, string $recipientEmail, int $orderId): int;

    /**
     * Look up a gift card by its code, or null if unknown.
     *
     * @return object{id:int,code:string,balance:float,recipient_email:string,order_id:int}|null
     */
    public function findByCode(string $code): ?object;

    /**
     * Overwrite the remaining balance for a gift card row.
     */
    public function updateBalance(int $id, float $balance): void;
}
