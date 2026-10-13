<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce\Tests;

use PHPUnit\Framework\TestCase;
use VeliraPay\WooCommerce\Orders\StatusSync;
use VeliraPay\WooCommerce\Tests\Support\FakeOrder;
use VeliraPay\WooCommerce\Tests\Support\FakeOrders;
use VeliraPay\WooCommerce\Webhooks\Handler;
use VeliraPay\WooCommerce\Webhooks\Signature;

final class HandlerTest extends TestCase
{
    private const LIVE_SECRET = 'whsec_live_2b7Qf9LkXw3NcR8vT1mZ5pYs0HdJ4gUe6AoKiVbE';

    private const TEST_SECRET = 'whsec_test_9zY8xW7vU6tS5rQ4pO3nM2lK1jH0gF9eD8cB7aA6';

    public function test_a_paid_invoice_marks_its_order_paid_and_is_remembered(): void
    {
        $order = new FakeOrder('pending');
        $payload = self::payload('invoice.paid', 'live', ['invoice' => [
            'code' => 'w7c2h5j9d3kx',
            'number' => 'INV-0042',
            'status' => 'paid',
            'charges' => [['code' => 'z8x6c4v2b0nm', 'status' => 'paid', 'asset' => 'USDC']],
        ]]);

        $response = $this->handler($order)->handle($payload, Signature::header($payload, self::LIVE_SECRET));

        $this->assertSame(200, $response['status']);
        $this->assertSame('Handled invoice.paid for invoice w7c2h5j9d3kx.', $response['body']);
        $this->assertSame('processing', $order->status);
        $this->assertSame('z8x6c4v2b0nm', $order->transactionId);
        $this->assertSame(['9b1f7a3e-2c4d-4e8f-a6b0-1d2c3e4f5a6b'], $order->events);
    }

    public function test_a_charge_event_finds_the_order_through_its_invoice(): void
    {
        $order = new FakeOrder('pending');
        $payload = self::payload('charge.payment_detected', 'live', [
            'charge' => ['code' => 'k3v9x2m7q8wz', 'status' => 'pending', 'asset' => 'BTC', 'asset_amount' => '0.002500000000000000', 'invoice' => ['code' => 'w7c2h5j9d3kx', 'number' => 'INV-0042']],
            'transaction' => ['amount' => '0.002500000000000000'],
        ]);

        $response = $this->handler($order)->handle($payload, Signature::header($payload, self::LIVE_SECRET));

        $this->assertSame(200, $response['status']);
        $this->assertSame('on-hold', $order->status);
    }

    public function test_a_bad_signature_is_refused(): void
    {
        $order = new FakeOrder('pending');
        $payload = self::payload('invoice.paid', 'live', ['invoice' => ['code' => 'w7c2h5j9d3kx', 'status' => 'paid']]);

        $this->assertSame(400, $this->handler($order)->handle($payload, Signature::header($payload, 'whsec_other'))['status']);
        $this->assertSame(400, $this->handler($order)->handle($payload, null)['status']);
        $this->assertSame(400, $this->handler($order)->handle($payload, Signature::header($payload, self::LIVE_SECRET, time() - 600))['status']);
        $this->assertSame('pending', $order->status);
        $this->assertSame([], $order->events);
    }

    public function test_a_delivery_signed_with_the_other_modes_secret_is_still_verified(): void
    {
        $order = new FakeOrder('pending');
        $payload = self::payload('invoice.paid', 'live', ['invoice' => ['code' => 'w7c2h5j9d3kx', 'status' => 'paid']]);

        $response = $this->handler($order)->handle($payload, Signature::header($payload, self::TEST_SECRET));

        $this->assertSame(200, $response['status']);
        $this->assertSame('processing', $order->status);
    }

    public function test_a_test_delivery_from_the_dashboard_is_acknowledged_and_ignored(): void
    {
        $order = new FakeOrder('pending');
        $payload = '{"id":"3c9e1f7a-5b2d-4e8c-9a1f-7b3d5e9c1a2f","event":"charge.paid","test":true,"mode":"live","created_at":"2026-09-24T09:00:00.000000Z","data":{"charge":{"code":"testab12cd34","status":"paid","invoice":{"code":"w7c2h5j9d3kx"}}}}';

        $response = $this->handler($order)->handle($payload, Signature::header($payload, self::LIVE_SECRET));

        $this->assertSame(200, $response['status']);
        $this->assertSame('Test delivery received.', $response['body']);
        $this->assertSame('pending', $order->status);
        $this->assertSame([], $order->notes);
        $this->assertSame([], $order->events);
    }

    public function test_a_delivery_of_the_other_mode_is_acknowledged_and_ignored(): void
    {
        $order = new FakeOrder('pending');
        $payload = self::payload('invoice.paid', 'test', ['invoice' => ['code' => 'w7c2h5j9d3kx', 'status' => 'paid']]);

        $response = $this->handler($order)->handle($payload, Signature::header($payload, self::TEST_SECRET));

        $this->assertSame(200, $response['status']);
        $this->assertSame('Ignored: the gateway is in live mode.', $response['body']);
        $this->assertSame('pending', $order->status);
        $this->assertSame([], $order->events);
    }

    public function test_a_repeated_event_is_applied_once(): void
    {
        $order = new FakeOrder('pending');
        $payload = self::payload('charge.expired', 'live', ['charge' => ['code' => 'k3v9x2m7q8wz', 'status' => 'expired', 'invoice' => ['code' => 'w7c2h5j9d3kx']]]);
        $handler = $this->handler($order);

        $first = $handler->handle($payload, Signature::header($payload, self::LIVE_SECRET));
        $second = $handler->handle($payload, Signature::header($payload, self::LIVE_SECRET));

        $this->assertSame(200, $first['status']);
        $this->assertSame(200, $second['status']);
        $this->assertSame('Already handled.', $second['body']);
        $this->assertCount(1, $order->notes);
        $this->assertSame(['9b1f7a3e-2c4d-4e8f-a6b0-1d2c3e4f5a6b'], $order->events);
    }

    public function test_an_unknown_invoice_is_acknowledged_and_ignored(): void
    {
        $payload = self::payload('invoice.paid', 'live', ['invoice' => ['code' => 'unknown00000', 'status' => 'paid']]);
        $order = new FakeOrder('pending');

        $response = $this->handler($order)->handle($payload, Signature::header($payload, self::LIVE_SECRET));

        $this->assertSame(200, $response['status']);
        $this->assertSame('Ignored: no order in this store uses invoice unknown00000.', $response['body']);
        $this->assertSame('pending', $order->status());
        $this->assertSame([], $order->notes);
    }

    public function test_a_charge_without_an_invoice_is_acknowledged_and_ignored(): void
    {
        $payload = self::payload('charge.paid', 'live', ['charge' => ['code' => 'k3v9x2m7q8wz', 'status' => 'paid', 'invoice' => null]]);

        $response = $this->handler(new FakeOrder('pending'))->handle($payload, Signature::header($payload, self::LIVE_SECRET));

        $this->assertSame(200, $response['status']);
        $this->assertSame('Ignored: the delivery is not about an invoice.', $response['body']);
    }

    public function test_a_signed_payload_that_is_not_a_delivery_is_refused(): void
    {
        $payload = '["invoice.paid"]';

        $response = $this->handler(new FakeOrder('pending'))->handle($payload, Signature::header($payload, self::LIVE_SECRET));

        $this->assertSame(400, $response['status']);
    }

    /**
     * Create a handler for a live-mode gateway holding one order, paid with invoice w7c2h5j9d3kx.
     */
    private function handler(FakeOrder $order): Handler
    {
        return new Handler(new FakeOrders(['w7c2h5j9d3kx' => $order]), new StatusSync, 'live', [self::LIVE_SECRET, self::TEST_SECRET]);
    }

    /**
     * Build a delivery's JSON.
     *
     * @param  array<string, mixed>  $data
     */
    private static function payload(string $event, string $mode, array $data): string
    {
        return json_encode([
            'id' => '5f0c6f5e-8d1b-4a53-9a57-3c2b1d0e9f87',
            'event_id' => '9b1f7a3e-2c4d-4e8f-a6b0-1d2c3e4f5a6b',
            'event' => $event,
            'mode' => $mode,
            'created_at' => '2026-10-13T10:05:42.000000Z',
            'data' => $data,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
