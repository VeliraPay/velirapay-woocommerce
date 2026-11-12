<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce\Orders;

/**
 * Turns what VeliraPay says about an invoice into WooCommerce order transitions, for webhooks and for polling alike.
 */
final class StatusSync
{
    /**
     * The statuses of an order still waiting for its payment, the only ones a payment moves.
     */
    private const UNPAID_STATUSES = ['pending', 'failed', 'on-hold'];

    /**
     * How many transfers to one charge are looked through when telling a topped-up short payment from an accepted one.
     */
    private const MOST_TRANSFERS = 20;

    /**
     * How a coin is named in a note where its code alone would not say which network it is on: by its ticker and network,
     * as VeliraPay's own lists do. Any other coin, including one added later, is named by its code.
     */
    private const COIN_NAMES = [
        'BTC_LIGHTNING' => 'BTC (Lightning)',
        'USDT' => 'USDT (Ethereum)',
        'USDC' => 'USDC (Ethereum)',
        'USDT_TRON' => 'USDT (Tron)',
        'USDT_BSC' => 'USDT (BNB Chain)',
        'USDC_BSC' => 'USDC (BNB Chain)',
        'USDC_BASE' => 'USDC (Base)',
        'ETH_BASE' => 'ETH (Base)',
        'USDT_ARBITRUM' => 'USDT (Arbitrum One)',
        'USDC_ARBITRUM' => 'USDC (Arbitrum One)',
        'ETH_ARBITRUM' => 'ETH (Arbitrum One)',
        'USDT_POLYGON' => 'USDT (Polygon)',
        'USDC_POLYGON' => 'USDC (Polygon)',
    ];

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
        $payout = is_array($data['payout'] ?? null) ? $data['payout'] : [];

        $code = self::text($charge['code'] ?? null);
        $asset = self::coin($charge['asset'] ?? null);
        $due = self::amount($charge['asset_amount'] ?? null);
        $received = self::amount($charge['received_amount'] ?? null);
        $seen = self::amount($transfer['amount'] ?? $charge['asset_amount'] ?? null);

        if ($code !== '') {
            $this->rememberCharge($order, $event, $code, $charge);
        }

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
            'charge.payment_detected' => $this->hold($order, ($charge['asset'] ?? null) === 'BTC_LIGHTNING'
                ? sprintf(
                    /* translators: 1: amount in bitcoin, 2: charge code */
                    __('Lightning payment of %1$s BTC received. A paid Lightning invoice is final, so VeliraPay counts it in a moment (charge %2$s).', 'velirapay-woocommerce'),
                    $seen,
                    $code,
                )
                : sprintf(
                    /* translators: 1: amount, 2: coin, 3: charge code */
                    __('Payment of %1$s %2$s seen on chain, waiting for confirmations (charge %3$s).', 'velirapay-woocommerce'),
                    $seen,
                    $asset,
                    $code,
                ), detectedCharge: $code),
            'charge.underpaid' => $this->hold($order, sprintf(
                /* translators: 1: amount received, 2: amount due, 3: coin, 4: charge code */
                __('Underpaid: %1$s of %2$s %3$s received (charge %4$s). The customer can send the rest on the invoice page; if you accept the short payment in the VeliraPay dashboard instead, this order stays on hold for you to fulfil or cancel.', 'velirapay-woocommerce'),
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
            'charge.expired' => $this->chargeClosed($order, $code, $charge, sprintf(
                /* translators: %s: charge code */
                __('Charge %s expired before it was paid. The customer can get a new quote on the invoice page.', 'velirapay-woocommerce'),
                $code,
            )),
            'charge.canceled' => $this->chargeClosed($order, $code, $charge, sprintf(
                /* translators: %s: charge code */
                __('Charge %s was canceled.', 'velirapay-woocommerce'),
                $code,
            )),
            'charge.late_payment' => ($charge['status'] ?? null) === 'paid'
                ? $order->addNote(sprintf(
                    /* translators: 1: amount, 2: coin, 3: charge code */
                    __('An additional payment of %1$s %2$s arrived on charge %3$s; it is added to the charge once confirmed.', 'velirapay-woocommerce'),
                    $seen,
                    $asset,
                    $code,
                ))
                : $this->holdWhileUnpaid($order, sprintf(
                    /* translators: 1: amount, 2: coin, 3: charge code */
                    __('A late payment of %1$s %2$s arrived on charge %3$s. It has to be accepted in the VeliraPay dashboard, whether you keep it or refund it from your wallet. Accepting it does not mark this order paid: move the order to Processing to fulfil it, or cancel it once the payment is refunded.', 'velirapay-woocommerce'),
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
            'payout.sent' => $order->addNote(sprintf(
                /* translators: 1: charge code, 2: amount, 3: coin, 4: transaction hash */
                __('Your share of charge %1$s is on its way: %2$s %3$s in transaction %4$s.', 'velirapay-woocommerce'),
                $code,
                self::amount($payout['sent_amount'] ?? null),
                $asset,
                self::text($payout['payout_txid'] ?? null),
            )),
            'payout.confirmed' => $order->addNote(sprintf(
                /* translators: 1: charge code, 2: amount, 3: coin */
                __('Your share of charge %1$s has reached your wallet: %2$s %3$s.', 'velirapay-woocommerce'),
                $code,
                self::amount($payout['sent_amount'] ?? null),
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
     * Apply a webhook event about an invoice the order used before it was replaced: money reaching it is held for the
     * merchant to sort out, never counted as the order's payment, and anything else about it is left alone.
     *
     * @param  string  $event  The event type, such as "invoice.paid".
     * @param  array<array-key, mixed>  $data  The delivery's data: the invoice, or the charge and its transfer.
     */
    public function applySupersededEvent(Order $order, string $event, array $data): void
    {
        $invoice = is_array($data['invoice'] ?? null) ? $data['invoice'] : [];
        $charge = is_array($data['charge'] ?? null) ? $data['charge'] : [];
        $transfer = is_array($data['transaction'] ?? null) ? $data['transaction'] : [];
        $number = self::text($invoice['number'] ?? (is_array($charge['invoice'] ?? null) ? ($charge['invoice']['number'] ?? null) : null));
        $asset = self::coin($charge['asset'] ?? null);
        $seen = self::amount($transfer['amount'] ?? $charge['asset_amount'] ?? null);

        match ($event) {
            'invoice.paid' => $this->holdForSupersededPayment($order, $invoice, $number),
            'charge.payment_detected', 'charge.underpaid', 'charge.late_payment' => $order->addNote(sprintf(
                /* translators: 1: amount, 2: coin, 3: charge code, 4: invoice number */
                __('A payment of %1$s %2$s reached charge %3$s of VeliraPay invoice %4$s, which this order used before it was replaced. Check it in the VeliraPay dashboard.', 'velirapay-woocommerce'),
                $seen,
                $asset,
                self::text($charge['code'] ?? null),
                $number,
            )),
            default => null,
        };
    }

    /**
     * Hold the order for a replaced invoice that was paid, or note a second payment on an order already paid.
     *
     * @param  array<array-key, mixed>  $invoice
     */
    private function holdForSupersededPayment(Order $order, array $invoice, string $number): void
    {
        $charge = self::chargeWithStatus($invoice, 'paid');
        $paid = trim(self::money($invoice['amount'] ?? null).' '.self::text($invoice['currency'] ?? null));

        if ($order->isPaid()) {
            $order->addNote(sprintf(
                /* translators: 1: invoice number, 2: charge code, 3: amount and currency */
                __('VeliraPay invoice %1$s, which this order used before it was replaced, was paid as well (charge %2$s, %3$s): the customer has paid twice. Refund the extra payment from your wallet and record it in the VeliraPay dashboard.', 'velirapay-woocommerce'),
                $number,
                $charge,
                $paid,
            ));

            return;
        }

        $this->hold($order, sprintf(
            /* translators: 1: invoice number, 2: charge code, 3: amount and currency */
            __('VeliraPay invoice %1$s, which this order used before it was replaced, was paid (charge %2$s, %3$s). The order was not marked paid: check the payment against the order, and void its current invoice once it is settled.', 'velirapay-woocommerce'),
            $number,
            $charge,
            $paid,
        ));
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
     * Record the payment with the charge that paid the invoice, once. An order the invoice no longer covers, or whose
     * invoice was paid by accepting a short or late payment, is held instead, and one that no longer waits for payment,
     * such as a cancelled one, is only noted.
     *
     * @param  array<array-key, mixed>  $invoice
     */
    private function markPaid(Order $order, array $invoice): void
    {
        if ($order->isPaid()) {
            return;
        }

        $charge = $this->payingCharge($order, $invoice);
        $number = self::text($invoice['number'] ?? null);

        if (! self::covers($invoice, $order)) {
            // Remembered like an event, so the webhook and every later poll note the mismatch once between them.
            $mismatch = 'amount-mismatch:'.self::text($invoice['code'] ?? $invoice['id'] ?? null);

            if ($order->hasHandledEvent($mismatch)) {
                return;
            }

            $this->hold($order, sprintf(
                /* translators: 1: invoice number, 2: charge code, 3: invoice amount and currency, 4: order total and currency */
                __('VeliraPay invoice %1$s was paid (charge %2$s), but it was for %3$s and the order now comes to %4$s, so it was not marked paid. Check the payment before fulfilling the order.', 'velirapay-woocommerce'),
                $number,
                $charge,
                trim(self::money($invoice['amount'] ?? null).' '.self::text($invoice['currency'] ?? null)),
                trim(self::money($order->total()).' '.$order->currency()),
            ));
            $order->rememberEvent($mismatch);

            return;
        }

        if ($this->wasAccepted($order, $charge)) {
            // Noted once between the webhook and every later poll, as the mismatch is.
            if (! $order->hasHandledEvent('accepted:'.$charge)) {
                $this->holdWhileUnpaid($order, $order->hasHandledEvent('late:'.$charge)
                    ? sprintf(
                        /* translators: 1: invoice number, 2: charge code */
                        __('VeliraPay invoice %1$s was paid by accepting the late payment on charge %2$s, so this order was not marked paid. Move it to Processing to fulfil it, or cancel it once the payment is refunded.', 'velirapay-woocommerce'),
                        $number,
                        $charge,
                    )
                    : sprintf(
                        /* translators: 1: invoice number, 2: charge code */
                        __('VeliraPay invoice %1$s was paid, but charge %2$s was last reported short and no top-up was seen since, so the short payment was most likely accepted and this order was not marked paid. Check the charge in the VeliraPay dashboard, then move the order to Processing to fulfil it, or cancel it once the payment is refunded.', 'velirapay-woocommerce'),
                        $number,
                        $charge,
                    ));
                $order->rememberEvent('accepted:'.$charge);
            }

            return;
        }

        if (! in_array($order->status(), self::UNPAID_STATUSES, true)) {
            $leftAlone = 'left-alone:'.self::text($invoice['code'] ?? $invoice['id'] ?? null);

            if (! $order->hasHandledEvent($leftAlone)) {
                $order->addNote($order->status() === 'cancelled'
                    ? sprintf(
                        /* translators: 1: invoice number, 2: charge code */
                        __('VeliraPay invoice %1$s was paid (charge %2$s) after this order was cancelled, so it was left cancelled. Move it to Processing to fulfil it, or refund the payment from your wallet and record the refund in the VeliraPay dashboard.', 'velirapay-woocommerce'),
                        $number,
                        $charge,
                    )
                    : sprintf(
                        /* translators: 1: invoice number, 2: charge code, 3: order status */
                        __('VeliraPay invoice %1$s was paid (charge %2$s) while this order was %3$s, so its status was left alone.', 'velirapay-woocommerce'),
                        $number,
                        $charge,
                        $order->status(),
                    ));
                $order->rememberEvent($leftAlone);
            }

            return;
        }

        $order->markPaid($charge, sprintf(
            /* translators: 1: invoice number, 2: charge code */
            __('VeliraPay invoice %1$s paid (charge %2$s).', 'velirapay-woocommerce'),
            $number,
            $charge,
        ));
    }

    /**
     * Remember what a charge event says about how the charge may come to be paid: by being accepted, after a late
     * payment, or after a short one that no top-up followed.
     *
     * @param  array<array-key, mixed>  $charge
     */
    private function rememberCharge(Order $order, string $event, string $code, array $charge): void
    {
        $transfers = is_array($charge['transactions'] ?? null) ? $charge['transactions'] : null;

        $marker = match (true) {
            // A charge that is closed, or short past its rate lock, becomes paid only when the merchant accepts it.
            $event === 'charge.late_payment' && ($charge['status'] ?? null) !== 'paid' => 'late:'.$code,
            // Counted against the transfers VeliraPay had counted, so a top-up seen later can be told apart.
            $event === 'charge.underpaid' => 'short:'.$code.':'.count(array_filter($transfers ?? [], static fn (mixed $transfer): bool => is_array($transfer) && ($transfer['credited'] ?? false) === true)),
            $event === 'charge.payment_detected' && $transfers !== null => 'seen:'.$code.':'.count($transfers),
            default => null,
        };

        if ($marker !== null && ! $order->hasHandledEvent($marker)) {
            $order->rememberEvent($marker);
        }
    }

    /**
     * Determine if the charge was paid by the merchant accepting it rather than by the customer's payment covering it:
     * a late payment reached it, or it was last reported short and no transfer was seen after.
     */
    private function wasAccepted(Order $order, string $charge): bool
    {
        if ($charge === '') {
            return false;
        }

        if ($order->hasHandledEvent('late:'.$charge)) {
            return true;
        }

        for ($counted = self::MOST_TRANSFERS; $counted >= 0; $counted--) {
            if (! $order->hasHandledEvent("short:{$charge}:{$counted}")) {
                continue;
            }

            for ($seen = $counted + 1; $seen <= self::MOST_TRANSFERS + 1; $seen++) {
                if ($order->hasHandledEvent("seen:{$charge}:{$seen}")) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Get the code of the charge that paid the invoice: a paid charge the customer's payment covered, ahead of one
     * the merchant accepted.
     *
     * @param  array<array-key, mixed>  $invoice
     */
    private function payingCharge(Order $order, array $invoice): string
    {
        $paid = self::chargesWithStatus($invoice, 'paid');

        foreach ($paid as $charge) {
            if (! $this->wasAccepted($order, $charge)) {
                return $charge;
            }
        }

        return $paid[0] ?? '';
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
     * Put the order on hold while a payment is confirming or short, keeping the note either way. A cancelled order is
     * never brought back: it only gets the note.
     *
     * @param  string  $detectedCharge  The charge a transfer was seen for, when that is why the order is held.
     */
    private function hold(Order $order, string $note, string $detectedCharge = ''): void
    {
        if ($order->isPaid() || $order->status() === 'cancelled') {
            $order->addNote($note);

            return;
        }

        if ($order->status() === 'on-hold') {
            $order->addNote($note);

            // Held for more than a transfer seen on chain, so the order stays held should that transfer never confirm.
            if ($detectedCharge === '' && ! $order->hasHandledEvent('held')) {
                $order->rememberEvent('held');
            }

            return;
        }

        $order->setStatus('on-hold', $note);

        if ($detectedCharge !== '') {
            $order->rememberEvent('detected:'.$detectedCharge);
        }
    }

    /**
     * Note a charge that closed unpaid, sending an order held only for a transfer to it back to waiting for payment when
     * that transfer never confirmed: VeliraPay forgets a transfer the chain no longer knows, then closes the charge with
     * nothing paid to it. A charge that closed with a transfer still listed is a late payment, which keeps its hold.
     *
     * @param  array<array-key, mixed>  $charge
     */
    private function chargeClosed(Order $order, string $code, array $charge, string $note): void
    {
        $neverConfirmed = $code !== ''
            && ($charge['transactions'] ?? null) === []
            && $order->status() === 'on-hold'
            && ! $order->isPaid()
            && $order->hasHandledEvent('detected:'.$code)
            && ! $order->hasHandledEvent('held');

        if (! $neverConfirmed) {
            $order->addNote($note);

            return;
        }

        $order->setStatus('pending', sprintf(
            /* translators: %s: charge code */
            __('The payment seen on chain for charge %s never confirmed, and the charge has closed, so the order is waiting for payment again: the customer can pay on the invoice page.', 'velirapay-woocommerce'),
            $code,
        ));
    }

    /**
     * Put an order still waiting for payment on hold, and only note it on any other, a cancelled one included.
     */
    private function holdWhileUnpaid(Order $order, string $note): void
    {
        if (in_array($order->status(), self::UNPAID_STATUSES, true)) {
            $this->hold($order, $note);

            return;
        }

        $order->addNote($note);
    }

    /**
     * Put the order on hold when the open invoice has an underpaid charge, without repeating the note on each poll.
     *
     * @param  array<array-key, mixed>  $invoice
     */
    private function holdIfUnderpaid(Order $order, array $invoice): void
    {
        $charge = self::chargeWithStatus($invoice, 'underpaid');

        if ($charge === '' || $order->isPaid() || ! in_array($order->status(), ['pending', 'failed'], true)) {
            return;
        }

        $order->setStatus('on-hold', sprintf(
            /* translators: %s: charge code */
            __('Charge %s is underpaid. Check it in the VeliraPay dashboard.', 'velirapay-woocommerce'),
            $charge,
        ));
    }

    /**
     * Determine if the invoice was for what the order comes to now, in the order's currency.
     *
     * @param  array<array-key, mixed>  $invoice
     */
    private static function covers(array $invoice, Order $order): bool
    {
        $amount = self::money($invoice['amount'] ?? null);

        return $amount !== ''
            && $amount === self::money($order->total())
            && strtoupper(self::text($invoice['currency'] ?? null)) === strtoupper($order->currency());
    }

    /**
     * Get a fiat amount with two decimals, such as "49.90", or an empty string when it is not a number.
     */
    private static function money(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : '';
    }

    /**
     * Get the code of the invoice's first charge in a status, or an empty string.
     *
     * @param  array<array-key, mixed>  $invoice
     */
    private static function chargeWithStatus(array $invoice, string $status): string
    {
        return self::chargesWithStatus($invoice, $status)[0] ?? '';
    }

    /**
     * Get the codes of the invoice's charges in a status, in the order the invoice lists them.
     *
     * @param  array<array-key, mixed>  $invoice
     * @return list<string>
     */
    private static function chargesWithStatus(array $invoice, string $status): array
    {
        $codes = [];

        foreach (is_array($invoice['charges'] ?? null) ? $invoice['charges'] : [] as $charge) {
            if (is_array($charge) && ($charge['status'] ?? null) === $status) {
                // Webhooks name a charge by "code", the API by "id".
                $codes[] = self::text($charge['code'] ?? $charge['id'] ?? null);
            }
        }

        return $codes;
    }

    /**
     * Get a value as text, for a note.
     */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Get how a coin is named in a note, such as "USDT (Tron)" for USDT_TRON, or "BTC".
     */
    private static function coin(mixed $asset): string
    {
        $code = self::text($asset);

        return self::COIN_NAMES[$code] ?? $code;
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
