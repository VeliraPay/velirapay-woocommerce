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

    public function test_an_underpaid_charge_puts_the_order_on_hold_with_what_was_received(): void
    {
        $order = new FakeOrder('pending');

        (new StatusSync)->applyEvent($order, 'charge.underpaid', ['charge' => self::charge('underpaid', ['received_amount' => '0.002000000000000000'])]);

        $this->assertSame('on-hold', $order->status);
        $this->assertSame(['Underpaid: 0.002 of 0.0025 BTC received (charge k3v9x2m7q8wz). Check the charge in the VeliraPay dashboard.'], $order->notes);
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
        yield 'charge.late_payment' => ['charge.late_payment', ['charge' => self::charge('expired'), 'transaction' => ['amount' => '0.001000000000000000']], 'A late payment of 0.001 BTC arrived on charge k3v9x2m7q8wz. Accept it in the VeliraPay dashboard; refund it from your wallet after accepting.'];
        yield 'charge.late_payment on a paid charge' => ['charge.late_payment', ['charge' => self::charge('paid'), 'transaction' => ['amount' => '0.001000000000000000']], 'An additional payment of 0.001 BTC arrived on charge k3v9x2m7q8wz; it is added to the charge once confirmed.'];
        yield 'charge.refunded' => ['charge.refunded', ['charge' => self::charge('paid', ['refunded_amount' => '0.000500000000000000'])], 'A refund was recorded on charge k3v9x2m7q8wz: 0.0005 BTC refunded in total.'];
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
