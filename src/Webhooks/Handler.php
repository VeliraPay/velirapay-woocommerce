<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce\Webhooks;

use VeliraPay\WooCommerce\Orders\OrderRepository;
use VeliraPay\WooCommerce\Orders\StatusSync;

/**
 * Turns a webhook delivery into an order update and the HTTP answer VeliraPay should get.
 */
final class Handler
{
    /**
     * Create a new handler.
     *
     * @param  string  $mode  The mode the gateway works in, "live" or "test"; deliveries of the other mode are ignored.
     * @param  list<string>  $secrets  Every endpoint secret deliveries may be signed with, live and test.
     */
    public function __construct(
        private OrderRepository $orders,
        private StatusSync $sync,
        private string $mode,
        private array $secrets,
    ) {}

    /**
     * Handle a delivery and get the status and plain-text body to answer with.
     *
     * @param  string  $payload  The raw request body.
     * @param  string|null  $signatureHeader  The X-VeliraPay-Signature header.
     * @return array{status: int, body: string}
     */
    public function handle(string $payload, ?string $signatureHeader): array
    {
        if (! Signature::verify($payload, $signatureHeader, $this->secrets)) {
            return self::answer(400, 'The signature is missing, invalid or too old.');
        }

        $delivery = json_decode($payload, true);
        $event = is_array($delivery) ? ($delivery['event'] ?? null) : null;

        if (! is_array($delivery) || ! is_string($event) || $event === '') {
            return self::answer(400, 'The payload is not a webhook delivery.');
        }

        if (($delivery['test'] ?? false) === true) {
            return self::answer(200, 'Test delivery received.');
        }

        if (($delivery['mode'] ?? null) !== $this->mode) {
            return self::answer(200, sprintf('Ignored: the gateway is in %s mode.', $this->mode));
        }

        $data = is_array($delivery['data'] ?? null) ? $delivery['data'] : [];
        $code = self::invoiceCode($data);

        if ($code === null) {
            return self::answer(200, 'Ignored: the delivery is not about an invoice.');
        }

        $order = $this->orders->findByInvoiceCode($code);

        // Acknowledged rather than refused: VeliraPay retries a refusal and switches the endpoint off after repeated ones,
        // and an invoice made outside the store, or one an order has outgrown, is no error.
        if ($order === null) {
            return self::answer(200, sprintf('Ignored: no order in this store uses invoice %s.', $code));
        }

        $eventId = $delivery['event_id'] ?? $delivery['id'] ?? null;
        $eventId = is_string($eventId) && $eventId !== '' ? $eventId : null;

        if ($eventId !== null && $order->hasHandledEvent($eventId)) {
            return self::answer(200, 'Already handled.');
        }

        $this->sync->applyEvent($order, $event, $data);

        if ($eventId !== null) {
            $order->rememberEvent($eventId);
        }

        return self::answer(200, sprintf('Handled %s for invoice %s.', $event, $code));
    }

    /**
     * Get the code of the invoice the delivery is about: the invoice itself, or the one its charge pays.
     *
     * @param  array<array-key, mixed>  $data
     */
    private static function invoiceCode(array $data): ?string
    {
        $invoice = $data['invoice'] ?? null;

        if (! is_array($invoice) && is_array($data['charge'] ?? null)) {
            $invoice = $data['charge']['invoice'] ?? null;
        }

        $code = is_array($invoice) ? ($invoice['code'] ?? null) : null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * Shape an answer.
     *
     * @return array{status: int, body: string}
     */
    private static function answer(int $status, string $body): array
    {
        return ['status' => $status, 'body' => $body];
    }
}
