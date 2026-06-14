<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\GiftCard;

use WPPoland\StorefrontKit\Support\Formatter;

/**
 * Namespace-neutral gift-card / store-credit engine (powers the Gift Cards –
 * Store Credit for WooCommerce plugin).
 *
 * A product flagged as a gift card (the host resolves is-gift-card + amount via
 * injected closures) generates a unique code on order completion; the code,
 * balance, recipient email and order id are persisted through the host-supplied
 * {@see GiftCardRepository} (custom table — same delegation as
 * {@see \WPPoland\StorefrontKit\Waitlist\WaitlistRepository}) and the recipient
 * is emailed. Redemption: a code field at checkout applies the remaining balance
 * as a negative cart fee, and the balance is decremented on order completion.
 *
 * Everything WooCommerce / text-domain / option specific is constructor-injected
 * via closures and arrays — nothing is hard-coded here. The code field markup
 * ships in the consuming plugin via the injected `renderField` closure.
 */
final class GiftCardEngine
{
    /**
     * @param \Closure(): bool $isEnabled
     * @param \Closure(): array<string, mixed> $settings Resolved settings.
     * @param \Closure(\WC_Product): bool $isGiftCard Whether a product is a
     *        gift card.
     * @param \Closure(\WC_Order_Item_Product): array{0: float, 1: string} $resolveCard
     *        Returns `[amount, recipient_email]` for a purchased gift-card line.
     * @param \Closure(string, array<string, mixed>): void $renderField
     *        Echoes the checkout redeem-code field.
     * @param array<string, string> $labels Fallback strings keyed by
     *        `fee_label`, `email_subject`, `email_body`, `invalid_code`,
     *        `applied`.
     */
    public function __construct(
        private readonly GiftCardRepository $repository,
        private readonly string $sessionKey,
        private readonly string $fieldName,
        private readonly string $nonceAction,
        private readonly string $fieldTemplate,
        private readonly array $labels,
        private readonly \Closure $isEnabled,
        private readonly \Closure $settings,
        private readonly \Closure $isGiftCard,
        private readonly \Closure $resolveCard,
        private readonly \Closure $renderField,
    ) {
    }

    public function registerHooks(): void
    {
        add_action('woocommerce_review_order_before_payment', [$this, 'renderRedeemField'], 10);
        add_action('woocommerce_checkout_update_order_review', [$this, 'captureRedeemCode'], 10);
        add_action('woocommerce_cart_calculate_fees', [$this, 'applyRedeemDiscount'], 30);
        add_action('woocommerce_checkout_create_order', [$this, 'persistRedeemCode'], 10, 1);
        add_action('woocommerce_order_status_completed', [$this, 'handleOrderCompleted'], 10, 1);
    }

    public function renderRedeemField(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        ($this->renderField)($this->fieldTemplate, [
            'field_name' => $this->fieldName,
            'nonce_field' => wp_create_nonce($this->nonceAction),
            'applied_code' => $this->getAppliedCode(),
            'settings' => $this->getSettings(),
        ]);
    }

    public function captureRedeemCode(string $postedData): void
    {
        if (! $this->isEnabled() || ! WC()->session instanceof \WC_Session) {
            return;
        }

        $parsed = [];
        parse_str($postedData, $parsed);

        $code = isset($parsed[$this->fieldName]) ? $this->normalizeCode((string) $parsed[$this->fieldName]) : '';

        if ($code === '') {
            WC()->session->__unset($this->sessionKey);

            return;
        }

        WC()->session->set($this->sessionKey, $code);
    }

    public function applyRedeemDiscount(\WC_Cart $cart): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $card = $this->getAppliedCard();

        if ($card === null) {
            return;
        }

        $cartTotal = (float) $cart->get_subtotal() + (float) $cart->get_subtotal_tax();
        $applied = min($card->balance, $cartTotal);

        if ($applied <= 0) {
            return;
        }

        $cart->add_fee(
            Formatter::interpolate($this->message('fee_label'), ['code' => $card->code]),
            -$applied,
        );
    }

    /**
     * Persist the session-held redeem code onto the order at creation time so
     * {@see redeemAppliedCard()} can decrement the balance reliably later — the
     * WC session is not guaranteed to survive until `order_status_completed`.
     */
    public function persistRedeemCode(\WC_Order $order): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $code = $this->getAppliedCode();

        if ($code !== '') {
            $order->update_meta_data($this->sessionKey, $code);
        }
    }

    public function handleOrderCompleted(int $orderId): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $order = wc_get_order($orderId);

        if (! $order instanceof \WC_Order) {
            return;
        }

        // Guard against an order being completed more than once (e.g.
        // completed -> refunded -> completed, or a manual re-trigger), which
        // would otherwise re-issue cards and decrement balances twice.
        $processedFlag = $this->sessionKey . '_processed';

        if ($order->get_meta($processedFlag) === 'yes') {
            return;
        }

        $order->update_meta_data($processedFlag, 'yes');
        $order->save();

        $this->issueGiftCards($order);
        $this->redeemAppliedCard($order);
    }

    private function issueGiftCards(\WC_Order $order): void
    {
        foreach ($order->get_items() as $item) {
            if (! $item instanceof \WC_Order_Item_Product) {
                continue;
            }

            $product = $item->get_product();

            if (! $product instanceof \WC_Product || ! (bool) ($this->isGiftCard)($product)) {
                continue;
            }

            [$amount, $recipientEmail] = $this->resolveCardDetails($item);

            if ($amount <= 0 || ! is_email($recipientEmail)) {
                continue;
            }

            $quantity = max(1, (int) $item->get_quantity());

            for ($i = 0; $i < $quantity; $i++) {
                $code = $this->issueUniqueCard($amount, $recipientEmail, $order->get_id());

                if ($code !== '') {
                    $this->sendRecipientEmail($recipientEmail, $code, $amount);
                }
            }
        }
    }

    private function redeemAppliedCard(\WC_Order $order): void
    {
        $code = (string) $order->get_meta($this->sessionKey);

        if ($code === '') {
            return;
        }

        $card = $this->repository->findByCode($code);

        if ($card === null) {
            return;
        }

        $used = 0.0;

        foreach ($order->get_fees() as $fee) {
            $total = (float) $fee->get_total();

            if ($total < 0) {
                $used += abs($total);
            }
        }

        $newBalance = max(0.0, $card->balance - $used);
        $this->repository->updateBalance($card->id, $newBalance);
    }

    /**
     * @return object{id:int,code:string,balance:float,recipient_email:string,order_id:int}|null
     */
    public function getAppliedCard(): ?object
    {
        $code = $this->getAppliedCode();

        if ($code === '') {
            return null;
        }

        $card = $this->repository->findByCode($code);

        if ($card === null || $card->balance <= 0) {
            return null;
        }

        return $card;
    }

    public function getAppliedCode(): string
    {
        if (! WC()->session instanceof \WC_Session) {
            return '';
        }

        $code = WC()->session->get($this->sessionKey);

        return is_string($code) ? $code : '';
    }

    public function generateCode(): string
    {
        $prefix = (string) ($this->getSettings()['code_prefix'] ?? '');
        $random = strtoupper(wp_generate_password(12, false, false));
        $code = $prefix . $random;

        return $this->normalizeCode($code);
    }

    /**
     * Issue one gift card with a code that is unique even under concurrency.
     *
     * Uniqueness is guaranteed at two layers: the kit pre-checks each candidate
     * via {@see GiftCardRepository::findByCode()} (cheap, filters the common
     * case), and the host's DB-level UNIQUE index is the authority — if a
     * concurrent issue inserts the same code between our check and our insert,
     * {@see GiftCardRepository::issue()} throws
     * {@see DuplicateGiftCardCodeException} and we regenerate. After a bounded
     * number of attempts the entropy is widened so a usable code is always
     * issued without an unbounded loop. Returns the issued code, or '' if no
     * code could be issued.
     */
    private function issueUniqueCard(float $amount, string $recipientEmail, int $orderId): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = $attempt < 5 ? $this->generateCode() : $this->generateWideCode();

            if ($code === '' || $this->repository->findByCode($code) !== null) {
                continue;
            }

            try {
                $this->repository->issue($code, $amount, $recipientEmail, $orderId);

                return $code;
            } catch (DuplicateGiftCardCodeException) {
                // A concurrent issue won the race for this code; regenerate.
                continue;
            }
        }

        return '';
    }

    private function generateWideCode(): string
    {
        $prefix = (string) ($this->getSettings()['code_prefix'] ?? '');

        return $this->normalizeCode($prefix . strtoupper(wp_generate_password(20, false, false)));
    }

    private function sendRecipientEmail(string $recipientEmail, string $code, float $amount): void
    {
        $subject = Formatter::interpolate($this->message('email_subject'), [
            'code' => $code,
            'amount' => wp_strip_all_tags(wc_price($amount)),
        ]);

        $body = Formatter::interpolate($this->message('email_body'), [
            'code' => $code,
            'amount' => wp_strip_all_tags(wc_price($amount)),
        ]);

        wp_mail($recipientEmail, $subject, $body);
    }

    /**
     * @return array{0: float, 1: string}
     */
    private function resolveCardDetails(\WC_Order_Item_Product $item): array
    {
        $resolved = ($this->resolveCard)($item);

        if (! is_array($resolved) || ! isset($resolved[0], $resolved[1])) {
            return [0.0, ''];
        }

        return [(float) $resolved[0], sanitize_email((string) $resolved[1])];
    }

    private function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', $code) ?? '');
    }

    private function isEnabled(): bool
    {
        return (bool) ($this->isEnabled)();
    }

    /**
     * @return array<string, mixed>
     */
    private function getSettings(): array
    {
        $settings = ($this->settings)();

        return is_array($settings) ? $settings : [];
    }

    private function message(string $labelKey): string
    {
        return $this->labels[$labelKey] ?? '';
    }
}
