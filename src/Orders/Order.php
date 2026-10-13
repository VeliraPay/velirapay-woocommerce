<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce\Orders;

/**
 * What the status mapping needs from a WooCommerce order, so it can be tested without WordPress.
 */
interface Order
{
    /**
     * Get the order's status without the "wc-" prefix, such as "pending" or "on-hold".
     */
    public function status(): string;

    /**
     * Determine if the order has been paid for.
     */
    public function isPaid(): bool;

    /**
     * Record the payment, with the charge that made it as the transaction id, and move the order on.
     */
    public function markPaid(string $transactionId, string $note): void;

    /**
     * Move the order to a status, with a note saying why.
     */
    public function setStatus(string $status, string $note): void;

    /**
     * Add a note for the merchant.
     */
    public function addNote(string $note): void;

    /**
     * Determine if a webhook event was already applied to the order.
     */
    public function hasHandledEvent(string $eventId): bool;

    /**
     * Remember that a webhook event was applied to the order.
     */
    public function rememberEvent(string $eventId): void;
}
