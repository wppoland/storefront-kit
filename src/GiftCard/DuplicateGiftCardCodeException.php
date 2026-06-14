<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\GiftCard;

/**
 * Thrown by a {@see GiftCardRepository::issue()} implementation when the insert
 * is rejected by the database UNIQUE index on the code column (a concurrent
 * issue produced the same code between generation and insert).
 *
 * The {@see GiftCardEngine} catches this and regenerates the code, so two
 * simultaneous issues can never collide even though the duplicate check and the
 * insert are not a single atomic operation. Host repositories should map their
 * driver's duplicate-key error (e.g. MySQL error 1062) to this exception.
 */
final class DuplicateGiftCardCodeException extends \RuntimeException
{
}
