<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce\Orders;

use WC_Order;

/**
 * A WooCommerce order, with the invoice and the webhook events it saw kept in its meta.
 */
final class WooCommerceOrder implements Order
{
    /**
     * The meta key holding the code of the invoice created for the order.
     */
    public const INVOICE_CODE_META = '_velirapay_invoice_code';

    /**
     * The meta key holding the hosted page of that invoice.
     */
    public const INVOICE_URL_META = '_velirapay_invoice_url';

    /**
     * The meta key holding the ids of the webhook events applied to the order.
     */
    public const EVENTS_META = '_velirapay_events';

    /**
     * How many event ids are kept for deduplication.
     */
    private const EVENTS_KEPT = 100;

    /**
     * Wrap an order.
     */
    public function __construct(private WC_Order $order) {}

    /**
     * Get the code of the invoice created for an order, or an empty string.
     */
    public static function invoiceCodeOf(WC_Order $order): string
    {
        $code = $order->get_meta(self::INVOICE_CODE_META);

        return is_string($code) ? $code : '';
    }

    /**
     * Remember the invoice created for an order.
     *
     * @param  array<string, mixed>  $invoice
     */
    public static function rememberInvoice(WC_Order $order, array $invoice): void
    {
        $order->update_meta_data(self::INVOICE_CODE_META, is_scalar($invoice['id'] ?? null) ? (string) $invoice['id'] : '');
        $order->update_meta_data(self::INVOICE_URL_META, is_scalar($invoice['hosted_url'] ?? null) ? (string) $invoice['hosted_url'] : '');
        $order->save();
    }

    /**
     * {@inheritDoc}
     */
    public function status(): string
    {
        return $this->order->get_status();
    }

    /**
     * {@inheritDoc}
     */
    public function isPaid(): bool
    {
        return $this->order->is_paid();
    }

    /**
     * {@inheritDoc}
     */
    public function markPaid(string $transactionId, string $note): void
    {
        $this->order->add_order_note($note);
        $this->order->payment_complete($transactionId);
    }

    /**
     * {@inheritDoc}
     */
    public function setStatus(string $status, string $note): void
    {
        $this->order->update_status($status, $note);
    }

    /**
     * {@inheritDoc}
     */
    public function addNote(string $note): void
    {
        $this->order->add_order_note($note);
    }

    /**
     * {@inheritDoc}
     */
    public function hasHandledEvent(string $eventId): bool
    {
        return in_array($eventId, $this->handledEvents(), true);
    }

    /**
     * {@inheritDoc}
     */
    public function rememberEvent(string $eventId): void
    {
        $events = [...$this->handledEvents(), $eventId];

        $this->order->update_meta_data(self::EVENTS_META, array_slice($events, -self::EVENTS_KEPT));
        $this->order->save();
    }

    /**
     * Get the ids of the webhook events applied so far.
     *
     * @return list<string>
     */
    private function handledEvents(): array
    {
        $events = $this->order->get_meta(self::EVENTS_META);

        return is_array($events) ? array_values(array_filter($events, 'is_string')) : [];
    }
}
