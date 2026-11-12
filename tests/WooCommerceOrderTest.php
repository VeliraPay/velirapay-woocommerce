<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeliraPay\WooCommerce\Orders\StatusSync;
use VeliraPay\WooCommerce\Orders\WooCommerceOrder;
use WC_Order;

final class WooCommerceOrderTest extends TestCase
{
    #[DataProvider('settledStatuses')]
    public function test_delayed_payment_events_cannot_reopen_a_settled_order(string $status, bool $hasPaymentDate): void
    {
        $order = new WC_Order(1042);
        $order->set_status($status);

        if ($hasPaymentDate) {
            $order->set_date_paid('2026-11-01T12:00:00Z');
        }

        $wrapped = new WooCommerceOrder($order);
        $sync = new StatusSync;

        $sync->applyEvent($wrapped, 'charge.payment_detected', ['charge' => ['code' => 'charge123456', 'asset' => 'BTC']]);
        $sync->applyInvoice($wrapped, ['status' => 'void']);
        $sync->applyInvoice($wrapped, ['status' => 'paid', 'amount' => '49.90', 'currency' => 'EUR']);

        $this->assertSame($status, $order->get_status());
    }

    public function test_a_paid_invoice_completes_a_cancelled_order_without_a_payment_date_once(): void
    {
        $order = new WC_Order(1042);
        $order->set_status('cancelled');
        $GLOBALS['velirapay_test_orders'] = [1042 => $order];
        $invoice = ['code' => 'w7c2h5j9d3kx', 'number' => 'INV-0042', 'status' => 'paid', 'amount' => '49.90', 'currency' => 'EUR', 'charges' => [['id' => 'z8x6c4v2b0nm', 'status' => 'paid', 'asset' => 'USDC']]];

        (new StatusSync)->applyInvoice(new WooCommerceOrder($order), $invoice);
        (new StatusSync)->applyInvoice(new WooCommerceOrder($order), $invoice);

        $this->assertSame('processing', $order->get_status());
        $this->assertNotNull($order->get_date_paid());
        $this->assertSame('z8x6c4v2b0nm', $order->get_transaction_id());
        $this->assertCount(1, wc_get_order_notes(['order_id' => 1042]));
    }

    /**
     * Remove the store double after each order.
     */
    protected function tearDown(): void
    {
        unset($GLOBALS['velirapay_test_orders']);

        parent::tearDown();
    }

    /**
     * Orders whose payment must not be applied again.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function settledStatuses(): iterable
    {
        yield 'refunded' => ['refunded', false];
        yield 'cancelled after payment' => ['cancelled', true];
        yield 'custom fulfilment status' => ['shipped', true];
    }
}
