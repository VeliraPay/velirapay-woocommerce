<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce\Orders;

/**
 * Turns what VeliraPay says about an invoice into WooCommerce order transitions, for webhooks and for polling alike.
 */
final class StatusSync
{
    /**
     * Apply a webhook event to the order the invoice was created for.
     *
     * @param  string  $event  The event type, such as "invoice.paid".
     * @param  array<array-key, mixed>  $data  The delivery's data: the invoice, or the charge and its transfer.
     */
    public function applyEvent(Order $order, string $event, array $data): void
    {
        $invoice = is_array($data['invoice'] ?? null) ? $data['invoice'] : [];
        $charge = is_array($data['charge'] ?? null) ? $data['charge'] : [];
        $transfer = is_array($data['transaction'] ?? null) ? $data['transaction'] : [];

        $code = self::text($charge['code'] ?? null);
        $asset = self::text($charge['asset'] ?? null);
        $due = self::amount($charge['asset_amount'] ?? null);
        $received = self::amount($charge['received_amount'] ?? null);
        $seen = self::amount($transfer['amount'] ?? $charge['asset_amount'] ?? null);

        match ($event) {
            'invoice.paid' => $this->markPaid($order, $invoice),
            'invoice.voided' => $this->cancel($order, __('The VeliraPay invoice was voided and can no longer be paid.', 'velirapay-woocommerce')),
            'invoice.viewed' => $order->addNote(__('The customer opened the VeliraPay invoice.', 'velirapay-woocommerce')),
            'charge.created' => $order->addNote(sprintf(
                /* translators: 1: coin, 2: amount, 3: charge code */
                __('The customer chose %1$s on VeliraPay: %2$s %1$s due at the locked rate (charge %3$s).', 'velirapay-woocommerce'),
                $asset,
                $due,
                $code,
            )),
            'charge.payment_detected' => $this->hold($order, sprintf(
                /* translators: 1: amount, 2: coin, 3: charge code */
                __('Payment of %1$s %2$s seen on chain, waiting for confirmations (charge %3$s).', 'velirapay-woocommerce'),
                $seen,
                $asset,
                $code,
            )),
            'charge.underpaid' => $this->hold($order, sprintf(
                /* translators: 1: amount received, 2: amount due, 3: coin, 4: charge code */
                __('Underpaid: %1$s of %2$s %3$s received (charge %4$s). Check the charge in the VeliraPay dashboard.', 'velirapay-woocommerce'),
                $received,
                $due,
                $asset,
                $code,
            )),
            'charge.paid' => $order->addNote(sprintf(
                /* translators: 1: charge code, 2: amount, 3: coin */
                __('Charge %1$s was paid: %2$s %3$s received.', 'velirapay-woocommerce'),
                $code,
                $received,
                $asset,
            )),
            'charge.expired' => $order->addNote(sprintf(
                /* translators: %s: charge code */
                __('Charge %s expired before it was paid. The customer can get a new quote on the invoice page.', 'velirapay-woocommerce'),
                $code,
            )),
            'charge.canceled' => $order->addNote(sprintf(
                /* translators: %s: charge code */
                __('Charge %s was canceled.', 'velirapay-woocommerce'),
                $code,
            )),
            'charge.late_payment' => $order->addNote(sprintf(
                ($charge['status'] ?? null) === 'paid'
                    /* translators: 1: amount, 2: coin, 3: charge code */
                    ? __('An additional payment of %1$s %2$s arrived on charge %3$s; it is added to the charge once confirmed.', 'velirapay-woocommerce')
                    /* translators: 1: amount, 2: coin, 3: charge code */
                    : __('A late payment of %1$s %2$s arrived on charge %3$s. Accept it in the VeliraPay dashboard; refund it from your wallet after accepting.', 'velirapay-woocommerce'),
                $seen,
                $asset,
                $code,
            )),
            'charge.refunded' => $order->addNote(sprintf(
                /* translators: 1: charge code, 2: amount, 3: coin */
                __('A refund was recorded on charge %1$s: %2$s %3$s refunded in total.', 'velirapay-woocommerce'),
                $code,
                self::amount($charge['refunded_amount'] ?? null),
                $asset,
            )),
            default => $order->addNote(sprintf(
                /* translators: %s: event type */
                __('VeliraPay event %s received.', 'velirapay-woocommerce'),
                $event,
            )),
        };
    }

    /**
     * Apply the invoice as the API describes it now, after polling it.
     *
     * @param  array<array-key, mixed>  $invoice  The invoice object from GET /v1/invoices/{id}.
     */
    public function applyInvoice(Order $order, array $invoice): void
    {
        match ($invoice['status'] ?? null) {
            'paid' => $this->markPaid($order, $invoice),
            'void' => $this->cancel($order, __('The VeliraPay invoice was voided and can no longer be paid.', 'velirapay-woocommerce')),
            default => $this->holdIfUnderpaid($order, $invoice),
        };
    }

    /**
     * Record the payment with the charge that paid the invoice, once.
     *
     * @param  array<array-key, mixed>  $invoice
     */
    private function markPaid(Order $order, array $invoice): void
    {
        if ($order->isPaid()) {
            return;
        }

        $charge = self::chargeWithStatus($invoice, 'paid');

        $order->markPaid($charge, sprintf(
            /* translators: 1: invoice number, 2: charge code */
            __('VeliraPay invoice %1$s paid (charge %2$s).', 'velirapay-woocommerce'),
            self::text($invoice['number'] ?? null),
            $charge,
        ));
    }

    /**
     * Cancel the order, unless it was paid after all.
     */
    private function cancel(Order $order, string $note): void
    {
        if ($order->isPaid() || $order->status() === 'cancelled') {
            $order->addNote($note);

            return;
        }

        $order->setStatus('cancelled', $note);
    }

    /**
     * Put the order on hold while a payment is confirming or short, keeping the note either way.
     */
    private function hold(Order $order, string $note): void
    {
        if ($order->isPaid() || $order->status() === 'on-hold') {
            $order->addNote($note);

            return;
        }

        $order->setStatus('on-hold', $note);
    }

    /**
     * Put the order on hold when the open invoice has an underpaid charge, without repeating the note on each poll.
     *
     * @param  array<array-key, mixed>  $invoice
     */
    private function holdIfUnderpaid(Order $order, array $invoice): void
    {
        $charge = self::chargeWithStatus($invoice, 'underpaid');

        if ($charge === '' || $order->isPaid() || $order->status() === 'on-hold') {
            return;
        }

        $order->setStatus('on-hold', sprintf(
            /* translators: %s: charge code */
            __('Charge %s is underpaid. Check it in the VeliraPay dashboard.', 'velirapay-woocommerce'),
            $charge,
        ));
    }

    /**
     * Get the code of the invoice's first charge in a status, or an empty string.
     *
     * @param  array<array-key, mixed>  $invoice
     */
    private static function chargeWithStatus(array $invoice, string $status): string
    {
        foreach (is_array($invoice['charges'] ?? null) ? $invoice['charges'] : [] as $charge) {
            if (is_array($charge) && ($charge['status'] ?? null) === $status) {
                // Webhooks name a charge by "code", the API by "id".
                return self::text($charge['code'] ?? $charge['id'] ?? null);
            }
        }

        return '';
    }

    /**
     * Get a value as text, for a note.
     */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Get a coin amount as text without the trailing zeros webhooks carry, such as "0.0025" for "0.002500000000000000".
     */
    private static function amount(mixed $value): string
    {
        $amount = self::text($value);

        if (str_contains($amount, '.')) {
            $amount = rtrim(rtrim($amount, '0'), '.');
        }

        return $amount === '' ? '0' : $amount;
    }
}
