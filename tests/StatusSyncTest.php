<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeliraPay\WooCommerce\Orders\StatusSync;
use VeliraPay\WooCommerce\Tests\Support\FakeOrder;

final class StatusSyncTest extends TestCase
{
    public function test_a_paid_invoice_completes_payment_with_the_paying_charge(): void
    {
        $order = new FakeOrder('pending');

        (new StatusSync)->applyEvent($order, 'invoice.paid', ['invoice' => self::invoice('paid', [
            ['code' => 'm2q7w4e9r1ty', 'status' => 'expired', 'asset' => 'ETH'],
            ['code' => 'z8x6c4v2b0nm', 'status' => 'paid', 'asset' => 'USDC'],
        ])]);

        $this->assertSame('processing', $order->status);
        $this->assertSame('z8x6c4v2b0nm', $order->transactionId);
        $this->assertSame(['VeliraPay invoice INV-0042 paid (charge z8x6c4v2b0nm).'], $order->notes);
    }

    public function test_a_paid_invoice_is_not_applied_twice(): void
    {
        $order = new FakeOrder('completed');

        (new StatusSync)->applyEvent($order, 'invoice.paid', ['invoice' => self::invoice('paid')]);

        $this->assertSame('completed', $order->status);
        $this->assertNull($order->transactionId);
        $this->assertSame([], $order->notes);
    }

    public function test_a_paid_invoice_for_less_than_the_order_now_comes_to_holds_it(): void
    {
        $order = new FakeOrder('pending', total: '64.90');

        (new StatusSync)->applyEvent($order, 'invoice.paid', ['invoice' => self::invoice('paid', [
            ['code' => 'z8x6c4v2b0nm', 'status' => 'paid', 'asset' => 'USDC'],
        ])]);

        $this->assertSame('on-hold', $order->status);
        $this->assertNull($order->transactionId);
        $this->assertSame(['VeliraPay invoice INV-0042 was paid (charge z8x6c4v2b0nm), but it was for 49.90 EUR and the order now comes to 64.90 EUR, so it was not marked paid. Check the payment before fulfilling the order.'], $order->notes);
    }

    public function test_a_paid_invoice_in_another_currency_holds_the_order(): void
    {
        $order = new FakeOrder('pending', currency: 'USD');

        (new StatusSync)->applyEvent($order, 'invoice.paid', ['invoice' => self::invoice('paid')]);

        $this->assertSame('on-hold', $order->status);
        $this->assertNull($order->transactionId);
    }

    public function test_a_paid_invoice_matches_a_total_however_it_is_written(): void
    {
        $order = new FakeOrder('pending', total: '49.9');

        (new StatusSync)->applyEvent($order, 'invoice.paid', ['invoice' => self::invoice('paid')]);

        $this->assertSame('processing', $order->status);
    }

    public function test_polling_a_paid_invoice_that_no_longer_covers_the_order_notes_it_once(): void
    {
        $order = new FakeOrder('pending', total: '64.90');
        $invoice = self::invoice('paid', [['id' => 'z8x6c4v2b0nm', 'status' => 'paid', 'asset' => 'USDC']]);

        (new StatusSync)->applyInvoice($order, $invoice);
        (new StatusSync)->applyInvoice($order, $invoice);

        $this->assertSame('on-hold', $order->status);
        $this->assertNull($order->transactionId);
        $this->assertCount(1, $order->notes);
    }

    public function test_polling_a_paid_invoice_that_no_longer_covers_an_order_already_on_hold_still_notes_it(): void
    {
        $order = new FakeOrder('on-hold', total: '64.90');

        (new StatusSync)->applyInvoice($order, self::invoice('paid', [['id' => 'z8x6c4v2b0nm', 'status' => 'paid', 'asset' => 'USDC']]));

        $this->assertSame('on-hold', $order->status);
        $this->assertCount(1, $order->notes);
        $this->assertStringContainsString('so it was not marked paid', $order->notes[0]);
    }

    public function test_a_mismatch_noted_by_the_webhook_is_not_noted_again_by_polling(): void
    {
        $order = new FakeOrder('pending', total: '64.90');

        (new StatusSync)->applyEvent($order, 'invoice.paid', ['invoice' => self::invoice('paid')]);
        (new StatusSync)->applyInvoice($order, ['id' => 'w7c2h5j9d3kx', ...self::invoice('paid')]);

        $this->assertCount(1, $order->notes);
    }

    public function test_a_voided_invoice_cancels_the_order(): void
    {
        $order = new FakeOrder('pending');

        (new StatusSync)->applyEvent($order, 'invoice.voided', ['invoice' => self::invoice('void')]);

        $this->assertSame('cancelled', $order->status);
        $this->assertSame(['The VeliraPay invoice was voided and can no longer be paid.'], $order->notes);
    }

    public function test_a_voided_invoice_leaves_a_paid_or_cancelled_order_alone(): void
    {
        $paid = new FakeOrder('processing');
        $cancelled = new FakeOrder('cancelled');

        (new StatusSync)->applyEvent($paid, 'invoice.voided', ['invoice' => self::invoice('void')]);
        (new StatusSync)->applyEvent($cancelled, 'invoice.voided', ['invoice' => self::invoice('void')]);

        $this->assertSame('processing', $paid->status);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertCount(1, $paid->notes);
        $this->assertCount(1, $cancelled->notes);
    }

    public function test_a_detected_payment_puts_the_order_on_hold(): void
    {
        $order = new FakeOrder('pending');

        (new StatusSync)->applyEvent($order, 'charge.payment_detected', [
            'charge' => self::charge('pending'),
            'transaction' => ['txid' => 'abc', 'amount' => '0.002500000000000000', 'confirmations' => 0],
        ]);

        $this->assertSame('on-hold', $order->status);
        $this->assertSame(['Payment of 0.0025 BTC seen on chain, waiting for confirmations (charge k3v9x2m7q8wz).'], $order->notes);
    }

    public function test_notes_name_a_token_by_its_ticker_and_network_and_keep_a_coin_they_do_not_know_by_its_code(): void
    {
        $order = new FakeOrder('pending');
        $sync = new StatusSync;

        $sync->applyEvent($order, 'charge.created', ['charge' => self::charge('pending', ['asset' => 'USDT_TRON', 'asset_amount' => '52.400000'])]);
        $sync->applyEvent($order, 'charge.created', ['charge' => self::charge('pending', ['asset' => 'ETH_BASE', 'asset_amount' => '0.0151'])]);
        $sync->applyEvent($order, 'charge.created', ['charge' => self::charge('pending', ['asset' => 'NEWCOIN', 'asset_amount' => '3'])]);

        $this->assertSame([
            'The customer chose USDT (Tron) on VeliraPay: 52.4 USDT (Tron) due at the locked rate (charge k3v9x2m7q8wz).',
            'The customer chose ETH (Base) on VeliraPay: 0.0151 ETH (Base) due at the locked rate (charge k3v9x2m7q8wz).',
            'The customer chose NEWCOIN on VeliraPay: 3 NEWCOIN due at the locked rate (charge k3v9x2m7q8wz).',
        ], $order->notes);
    }

    public function test_a_lightning_payment_is_noted_as_final_rather_than_waiting_for_confirmations(): void
    {
        $order = new FakeOrder('pending');

        (new StatusSync)->applyEvent($order, 'charge.payment_detected', [
            'charge' => self::charge('pending', ['asset' => 'BTC_LIGHTNING', 'asset_amount' => '0.00012000']),
            'transaction' => ['txid' => 'a1b2', 'amount' => '0.00012000', 'confirmations' => 1],
        ]);

        $this->assertSame('on-hold', $order->status);
        $this->assertSame(['Lightning payment of 0.00012 BTC received. A paid Lightning invoice is final, so VeliraPay counts it in a moment (charge k3v9x2m7q8wz).'], $order->notes);
    }

    public function test_a_detected_payment_only_adds_a_note_once_on_hold_or_paid(): void
    {
        $held = new FakeOrder('on-hold');
        $paid = new FakeOrder('processing');

        (new StatusSync)->applyEvent($held, 'charge.payment_detected', ['charge' => self::charge('pending')]);
        (new StatusSync)->applyEvent($paid, 'charge.payment_detected', ['charge' => self::charge('pending')]);

        $this->assertSame('on-hold', $held->status);
        $this->assertSame('processing', $paid->status);
        $this->assertCount(1, $held->notes);
        $this->assertCount(1, $paid->notes);
    }

    /**
     * The events of a charge closing unpaid.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function closedCharges(): iterable
    {
        yield 'expired' => ['charge.expired', 'expired'];
        yield 'canceled' => ['charge.canceled', 'canceled'];
    }

    #[DataProvider('closedCharges')]
    public function test_an_order_held_for_a_payment_that_never_confirmed_waits_for_payment_again(string $event, string $status): void
    {
        $order = new FakeOrder('pending');
        $sync = new StatusSync;

        $sync->applyEvent($order, 'charge.payment_detected', ['charge' => self::charge('pending', ['transactions' => [self::transfer('t1', false)]]), 'transaction' => self::transfer('t1', false)]);
        // VeliraPay forgets a transfer the chain no longer knows, then closes the charge with nothing paid to it.
        $sync->applyEvent($order, $event, ['charge' => self::charge($status, ['transactions' => []])]);

        $this->assertSame('pending', $order->status);
        $this->assertSame('The payment seen on chain for charge k3v9x2m7q8wz never confirmed, and the charge has closed, so the order is waiting for payment again: the customer can pay on the invoice page.', $order->notes[1]);
    }

    public function test_a_charge_that_closed_with_a_late_payment_leaves_its_order_on_hold(): void
    {
        $order = new FakeOrder('pending');
        $sync = new StatusSync;

        $sync->applyEvent($order, 'charge.payment_detected', ['charge' => self::charge('pending', ['transactions' => [self::transfer('t1', false)]]), 'transaction' => self::transfer('t1', false)]);
        $sync->applyEvent($order, 'charge.expired', ['charge' => self::charge('expired', ['transactions' => [self::transfer('t1', false)]])]);

        $this->assertSame('on-hold', $order->status);
    }

    public function test_an_order_also_held_for_another_reason_stays_on_hold_when_the_detected_payment_never_confirms(): void
    {
        $order = new FakeOrder('pending');
        $sync = new StatusSync;

        $sync->applyEvent($order, 'charge.payment_detected', ['charge' => self::charge('pending', ['transactions' => [self::transfer('t1', false)]]), 'transaction' => self::transfer('t1', false)]);
        $sync->applySupersededEvent($order, 'invoice.paid', ['invoice' => ['code' => 'old000000000', 'number' => 'INV-0041', 'status' => 'paid', 'amount' => '49.90', 'currency' => 'EUR', 'charges' => [['code' => 'z8x6c4v2b0nm', 'status' => 'paid', 'asset' => 'BTC']]]]);
        $sync->applyEvent($order, 'charge.expired', ['charge' => self::charge('expired', ['transactions' => []])]);

        $this->assertSame('on-hold', $order->status);
    }

    public function test_a_detected_payment_on_an_order_already_on_hold_is_not_what_holds_it(): void
    {
        $order = new FakeOrder('on-hold');
        $sync = new StatusSync;

        $sync->applyEvent($order, 'charge.payment_detected', ['charge' => self::charge('pending', ['transactions' => [self::transfer('t1', false)]]), 'transaction' => self::transfer('t1', false)]);
        $sync->applyEvent($order, 'charge.expired', ['charge' => self::charge('expired', ['transactions' => []])]);

        $this->assertSame('on-hold', $order->status);
    }

    public function test_an_underpaid_charge_puts_the_order_on_hold_with_what_was_received(): void
    {
        $order = new FakeOrder('pending');

        (new StatusSync)->applyEvent($order, 'charge.underpaid', ['charge' => self::charge('underpaid', ['received_amount' => '0.002000000000000000'])]);

        $this->assertSame('on-hold', $order->status);
        $this->assertSame(['Underpaid: 0.002 of 0.0025 BTC received (charge k3v9x2m7q8wz). The customer can send the rest on the invoice page; if you accept the short payment in the VeliraPay dashboard instead, this order stays on hold for you to fulfil or cancel.'], $order->notes);
    }

    /**
     * Charges a late payment can reach without being counted.
     *
     * @return iterable<string, array{string}>
     */
    public static function closedOrShortCharges(): iterable
    {
        yield 'expired' => ['expired'];
        yield 'canceled' => ['canceled'];
        yield 'underpaid' => ['underpaid'];
    }

    #[DataProvider('closedOrShortCharges')]
    public function test_a_late_payment_puts_an_order_waiting_for_payment_on_hold(string $chargeStatus): void
    {
        foreach (['pending', 'failed', 'on-hold'] as $status) {
            $order = new FakeOrder($status);

            (new StatusSync)->applyEvent($order, 'charge.late_payment', ['charge' => self::charge($chargeStatus), 'transaction' => ['amount' => '0.001000000000000000']]);

            $this->assertSame('on-hold', $order->status, "A {$status} order");
            $this->assertCount(1, $order->notes);
            $this->assertStringStartsWith('A late payment of 0.001 BTC arrived on charge k3v9x2m7q8wz', $order->notes[0]);
        }
    }

    public function test_a_late_payment_only_adds_a_note_to_a_cancelled_order(): void
    {
        $order = new FakeOrder('cancelled');

        (new StatusSync)->applyEvent($order, 'charge.late_payment', ['charge' => self::charge('expired'), 'transaction' => ['amount' => '0.001000000000000000']]);

        $this->assertSame('cancelled', $order->status);
        $this->assertCount(1, $order->notes);
    }

    public function test_an_invoice_paid_by_accepting_a_late_payment_holds_the_order_instead_of_completing_it(): void
    {
        $order = new FakeOrder('pending');
        $sync = new StatusSync;

        $sync->applyEvent($order, 'charge.late_payment', ['charge' => self::charge('expired'), 'transaction' => ['amount' => '0.002500000000000000']]);
        $sync->applyEvent($order, 'invoice.paid', ['invoice' => self::invoice('paid', [['code' => 'k3v9x2m7q8wz', 'status' => 'paid', 'asset' => 'BTC']])]);
        $sync->applyInvoice($order, self::invoice('paid', [['id' => 'k3v9x2m7q8wz', 'status' => 'paid', 'asset' => 'BTC']]));

        $this->assertSame('on-hold', $order->status);
        $this->assertNull($order->transactionId);
        $this->assertCount(2, $order->notes);
        $this->assertSame('VeliraPay invoice INV-0042 was paid by accepting the late payment on charge k3v9x2m7q8wz, so this order was not marked paid. Move it to Processing to fulfil it, or cancel it once the payment is refunded.', $order->notes[1]);
    }

    public function test_an_invoice_paid_after_a_short_payment_with_no_top_up_holds_the_order(): void
    {
        $order = new FakeOrder('pending');
        $sync = new StatusSync;

        $sync->applyEvent($order, 'charge.payment_detected', ['charge' => self::charge('pending', ['transactions' => [self::transfer('t1', false)]]), 'transaction' => self::transfer('t1', false)]);
        $sync->applyEvent($order, 'charge.underpaid', ['charge' => self::charge('underpaid', ['received_amount' => '0.002000000000000000', 'transactions' => [self::transfer('t1', true)]])]);
        $sync->applyEvent($order, 'invoice.paid', ['invoice' => self::invoice('paid', [['code' => 'k3v9x2m7q8wz', 'status' => 'paid', 'asset' => 'BTC']])]);

        $this->assertSame('on-hold', $order->status);
        $this->assertNull($order->transactionId);
        $this->assertStringContainsString('the short payment was most likely accepted and this order was not marked paid', $order->notes[2]);
    }

    public function test_a_short_payment_topped_up_in_time_completes_the_order(): void
    {
        $order = new FakeOrder('pending');
        $sync = new StatusSync;

        $sync->applyEvent($order, 'charge.payment_detected', ['charge' => self::charge('pending', ['transactions' => [self::transfer('t1', false)]]), 'transaction' => self::transfer('t1', false)]);
        $sync->applyEvent($order, 'charge.underpaid', ['charge' => self::charge('underpaid', ['transactions' => [self::transfer('t1', true)]])]);
        $sync->applyEvent($order, 'charge.payment_detected', ['charge' => self::charge('underpaid', ['transactions' => [self::transfer('t1', true), self::transfer('t2', false)]]), 'transaction' => self::transfer('t2', false)]);
        $sync->applyEvent($order, 'invoice.paid', ['invoice' => self::invoice('paid', [['code' => 'k3v9x2m7q8wz', 'status' => 'paid', 'asset' => 'BTC']])]);

        $this->assertSame('processing', $order->status);
        $this->assertSame('k3v9x2m7q8wz', $order->transactionId);
    }

    public function test_a_top_up_reported_after_the_invoice_was_paid_completes_the_order_at_the_next_poll(): void
    {
        $order = new FakeOrder('pending');
        $sync = new StatusSync;

        $sync->applyEvent($order, 'charge.payment_detected', ['charge' => self::charge('pending', ['transactions' => [self::transfer('t1', false)]]), 'transaction' => self::transfer('t1', false)]);
        $sync->applyEvent($order, 'charge.underpaid', ['charge' => self::charge('underpaid', ['transactions' => [self::transfer('t1', true)]])]);
        // On Tron a transfer is credited as it is detected, so the top-up's detection can be delivered after the invoice is paid.
        $sync->applyEvent($order, 'invoice.paid', ['invoice' => self::invoice('paid', [['code' => 'k3v9x2m7q8wz', 'status' => 'paid', 'asset' => 'BTC']])]);

        $this->assertSame('on-hold', $order->status);

        $sync->applyEvent($order, 'charge.payment_detected', ['charge' => self::charge('underpaid', ['transactions' => [self::transfer('t1', true), self::transfer('t2', false)]]), 'transaction' => self::transfer('t2', false)]);
        $sync->applyInvoice($order, self::invoice('paid', [['id' => 'k3v9x2m7q8wz', 'status' => 'paid', 'asset' => 'BTC']]));

        $this->assertSame('processing', $order->status);
        $this->assertSame('k3v9x2m7q8wz', $order->transactionId);
    }

    public function test_a_simulated_short_payment_topped_up_by_another_simulation_completes_the_order(): void
    {
        $order = new FakeOrder('pending');
        $sync = new StatusSync;

        // Simulate payment, in test mode, credits each made-up transfer at once, without announcing it as detected.
        $sync->applyEvent($order, 'charge.underpaid', ['charge' => self::charge('underpaid', ['transactions' => [self::transfer('simulated-q8w2e6r4t1y3', true)]])]);
        $sync->applyEvent($order, 'invoice.paid', ['invoice' => self::invoice('paid', [['code' => 'k3v9x2m7q8wz', 'status' => 'paid', 'asset' => 'BTC']])]);

        $this->assertSame('processing', $order->status);
        $this->assertSame('k3v9x2m7q8wz', $order->transactionId);
    }

    public function test_a_second_transfer_seen_before_the_charge_came_up_short_still_completes_the_order(): void
    {
        $order = new FakeOrder('pending');
        $sync = new StatusSync;

        $sync->applyEvent($order, 'charge.payment_detected', ['charge' => self::charge('pending', ['transactions' => [self::transfer('t1', false)]]), 'transaction' => self::transfer('t1', false)]);
        $sync->applyEvent($order, 'charge.payment_detected', ['charge' => self::charge('pending', ['transactions' => [self::transfer('t1', false), self::transfer('t2', false)]]), 'transaction' => self::transfer('t2', false)]);
        $sync->applyEvent($order, 'charge.underpaid', ['charge' => self::charge('underpaid', ['transactions' => [self::transfer('t1', true), self::transfer('t2', false)]])]);
        $sync->applyEvent($order, 'invoice.paid', ['invoice' => self::invoice('paid', [['code' => 'k3v9x2m7q8wz', 'status' => 'paid', 'asset' => 'BTC']])]);

        $this->assertSame('processing', $order->status);
    }

    public function test_an_invoice_paid_in_time_by_another_charge_completes_the_order_despite_an_accepted_late_payment(): void
    {
        $order = new FakeOrder('pending');
        $sync = new StatusSync;

        $sync->applyEvent($order, 'charge.late_payment', ['charge' => self::charge('expired'), 'transaction' => ['amount' => '0.002500000000000000']]);
        $sync->applyInvoice($order, self::invoice('paid', [
            ['id' => 'k3v9x2m7q8wz', 'status' => 'paid', 'asset' => 'BTC'],
            ['id' => 'z8x6c4v2b0nm', 'status' => 'paid', 'asset' => 'USDC'],
        ]));

        $this->assertSame('processing', $order->status);
        $this->assertSame('z8x6c4v2b0nm', $order->transactionId);
    }

    public function test_a_paid_invoice_leaves_a_cancelled_order_cancelled_and_says_so_once(): void
    {
        $order = new FakeOrder('cancelled');
        $invoice = self::invoice('paid', [['code' => 'z8x6c4v2b0nm', 'status' => 'paid', 'asset' => 'USDC']]);

        (new StatusSync)->applyEvent($order, 'invoice.paid', ['invoice' => $invoice]);
        (new StatusSync)->applyInvoice($order, $invoice);

        $this->assertSame('cancelled', $order->status);
        $this->assertNull($order->transactionId);
        $this->assertSame(['VeliraPay invoice INV-0042 was paid (charge z8x6c4v2b0nm) after this order was cancelled, so it was left cancelled. Move it to Processing to fulfil it, or refund the payment from your wallet and record the refund in the VeliraPay dashboard.'], $order->notes);
    }

    public function test_news_of_a_payment_never_moves_a_cancelled_order(): void
    {
        $order = new FakeOrder('cancelled');
        $sync = new StatusSync;

        $sync->applyEvent($order, 'charge.payment_detected', ['charge' => self::charge('pending')]);
        $sync->applyEvent($order, 'charge.underpaid', ['charge' => self::charge('underpaid')]);
        $sync->applyEvent($order, 'invoice.paid', ['invoice' => self::invoice('paid', [['code' => 'k3v9x2m7q8wz', 'status' => 'paid', 'asset' => 'BTC']])]);
        $sync->applyInvoice($order, self::invoice('open', [['id' => 'k3v9x2m7q8wz', 'status' => 'underpaid', 'asset' => 'BTC']]));
        $sync->applySupersededEvent($order, 'invoice.paid', ['invoice' => self::invoice('paid', [['code' => 'k3v9x2m7q8wz', 'status' => 'paid', 'asset' => 'BTC']])]);

        $this->assertSame('cancelled', $order->status);
        $this->assertNull($order->transactionId);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function noteOnlyEvents(): iterable
    {
        yield 'charge.created' => ['charge.created', ['charge' => self::charge('pending')], 'The customer chose BTC on VeliraPay: 0.0025 BTC due at the locked rate (charge k3v9x2m7q8wz).'];
        yield 'charge.paid' => ['charge.paid', ['charge' => self::charge('paid', ['received_amount' => '0.002500000000000000'])], 'Charge k3v9x2m7q8wz was paid: 0.0025 BTC received.'];
        yield 'charge.expired' => ['charge.expired', ['charge' => self::charge('expired')], 'Charge k3v9x2m7q8wz expired before it was paid. The customer can get a new quote on the invoice page.'];
        yield 'charge.canceled' => ['charge.canceled', ['charge' => self::charge('canceled')], 'Charge k3v9x2m7q8wz was canceled.'];
        yield 'charge.late_payment on a paid charge' => ['charge.late_payment', ['charge' => self::charge('paid'), 'transaction' => ['amount' => '0.001000000000000000']], 'An additional payment of 0.001 BTC arrived on charge k3v9x2m7q8wz; it is added to the charge once confirmed.'];
        yield 'charge.refunded' => ['charge.refunded', ['charge' => self::charge('paid', ['refunded_amount' => '0.000500000000000000'])], 'A refund was recorded on charge k3v9x2m7q8wz: 0.0005 BTC refunded in total.'];
        yield 'payout.sent' => ['payout.sent', ['charge' => self::charge('paid'), 'payout' => ['sent_amount' => '0.002425', 'payout_txid' => 'cc9a3e0b']], 'Your share of charge k3v9x2m7q8wz is on its way: 0.002425 BTC in transaction cc9a3e0b.'];
        yield 'payout.confirmed' => ['payout.confirmed', ['charge' => self::charge('paid'), 'payout' => ['sent_amount' => '0.002425', 'payout_txid' => 'cc9a3e0b']], 'Your share of charge k3v9x2m7q8wz has reached your wallet: 0.002425 BTC.'];
        yield 'invoice.created' => ['invoice.created', ['invoice' => self::invoice('open')], 'VeliraPay event invoice.created received.'];
        yield 'invoice.sent' => ['invoice.sent', ['invoice' => self::invoice('open')], 'VeliraPay event invoice.sent received.'];
        yield 'invoice.viewed' => ['invoice.viewed', ['invoice' => self::invoice('open')], 'The customer opened the VeliraPay invoice.'];
        yield 'an event added later' => ['invoice.reminded', ['invoice' => self::invoice('open')], 'VeliraPay event invoice.reminded received.'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('noteOnlyEvents')]
    public function test_other_events_only_add_a_note(string $event, array $data, string $note): void
    {
        $order = new FakeOrder('pending');

        (new StatusSync)->applyEvent($order, $event, $data);

        $this->assertSame('pending', $order->status);
        $this->assertSame([$note], $order->notes);
    }

    public function test_polling_a_paid_invoice_completes_payment(): void
    {
        $order = new FakeOrder('on-hold');

        (new StatusSync)->applyInvoice($order, self::invoice('paid', [['id' => 'z8x6c4v2b0nm', 'status' => 'paid', 'asset' => 'USDC']]));

        $this->assertSame('processing', $order->status);
        $this->assertSame('z8x6c4v2b0nm', $order->transactionId);
    }

    public function test_polling_a_void_invoice_cancels_the_order(): void
    {
        $order = new FakeOrder('pending');

        (new StatusSync)->applyInvoice($order, self::invoice('void'));

        $this->assertSame('cancelled', $order->status);
    }

    public function test_polling_an_open_invoice_with_an_underpaid_charge_holds_the_order_once(): void
    {
        $order = new FakeOrder('pending');
        $invoice = self::invoice('open', [['id' => 'z8x6c4v2b0nm', 'status' => 'underpaid', 'asset' => 'USDC']]);

        (new StatusSync)->applyInvoice($order, $invoice);
        (new StatusSync)->applyInvoice($order, $invoice);

        $this->assertSame('on-hold', $order->status);
        $this->assertSame(['Charge z8x6c4v2b0nm is underpaid. Check it in the VeliraPay dashboard.'], $order->notes);
    }

    public function test_polling_an_open_invoice_without_a_payment_changes_nothing(): void
    {
        $order = new FakeOrder('pending');

        (new StatusSync)->applyInvoice($order, self::invoice('open', [['id' => 'm2q7w4e9r1ty', 'status' => 'expired', 'asset' => 'ETH']]));

        $this->assertSame('pending', $order->status);
        $this->assertSame([], $order->notes);
    }

    /**
     * Build an invoice as a webhook or the API carries it.
     *
     * @param  list<array<string, string>>  $charges
     * @return array<string, mixed>
     */
    private static function invoice(string $status, array $charges = []): array
    {
        return [
            'code' => 'w7c2h5j9d3kx',
            'number' => 'INV-0042',
            'status' => $status,
            'amount' => '49.90',
            'currency' => 'EUR',
            'hosted_url' => 'https://velirapay.com/i/w7c2h5j9d3kx',
            'charges' => $charges,
        ];
    }

    /**
     * Build a transfer as a webhook carries it.
     *
     * @return array<string, mixed>
     */
    private static function transfer(string $txid, bool $credited): array
    {
        return ['txid' => $txid, 'amount' => '0.001000000000000000', 'confirmations' => $credited ? 3 : 0, 'required_confirmations' => 2, 'credited' => $credited];
    }

    /**
     * Build a charge as a webhook carries it.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function charge(string $status, array $attributes = []): array
    {
        return array_replace([
            'code' => 'k3v9x2m7q8wz',
            'status' => $status,
            'fiat_amount' => '49.90',
            'fiat_currency' => 'EUR',
            'asset' => 'BTC',
            'asset_amount' => '0.002500000000000000',
            'received_amount' => null,
            'refunded_amount' => '0',
            'invoice' => ['code' => 'w7c2h5j9d3kx', 'number' => 'INV-0042'],
        ], $attributes);
    }
}
