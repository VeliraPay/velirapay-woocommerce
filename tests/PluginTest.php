<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use VeliraPay\WooCommerce\Api\ApiException;
use VeliraPay\WooCommerce\Api\Client;
use VeliraPay\WooCommerce\Gateway;
use VeliraPay\WooCommerce\Orders\WooCommerceOrder;
use VeliraPay\WooCommerce\Plugin;
use WC_Order;

final class PluginTest extends TestCase
{
    /**
     * The URL of each request the HTTP double answered.
     *
     * @var list<string>
     */
    private array $requests = [];

    /**
     * The orders in the store double, by ID.
     *
     * @var array<int, WC_Order>
     */
    private array $orders = [];

    /**
     * The method and path of each request the routing HTTP double answered.
     *
     * @var list<string>
     */
    private array $sent = [];

    public function test_polling_continues_past_fifty_orders_without_skipping_failed_or_changed_orders(): void
    {
        $orders = [];

        for ($id = 1; $id <= 53; $id++) {
            $order = new WC_Order($id);
            $order->update_meta_data(WooCommerceOrder::INVOICE_CODE_META, 'invoice'.$id);
            $orders[$id] = $order;
        }

        $GLOBALS['velirapay_test_orders'] = $orders;
        $gateway = (new ReflectionClass(Gateway::class))->newInstanceWithoutConstructor();
        $gateway->settings = ['live_api_key' => 'vp_live_example'];
        $GLOBALS['velirapay_test_gateway'] = $gateway;
        $requests = [];
        $GLOBALS['velirapay_test_http'] = static function (string $url) use (&$requests): array {
            $requests[] = basename($url);

            return ['status' => 503, 'body' => '{"message":"Try later."}'];
        };

        Plugin::syncPendingOrders();

        $this->assertCount(50, $requests);
        $this->assertSame('invoice50', $requests[49]);
        $this->assertSame([51, 52, 53], get_option('velirapay_sync_order_ids'));
        $this->assertGreaterThanOrEqual(time(), wp_next_scheduled(Plugin::SYNC_BATCH_HOOK));

        Plugin::syncPendingOrders();
        $this->assertCount(50, $requests);
        $orders[51]->set_status('completed');
        unset($orders[52]);
        $GLOBALS['velirapay_test_orders'] = $orders;
        wp_clear_scheduled_hook(Plugin::SYNC_BATCH_HOOK);

        Plugin::syncOrderBatch();

        $this->assertCount(51, $requests);
        $this->assertSame('invoice53', $requests[50]);
        $this->assertFalse(get_option('velirapay_sync_order_ids'));
        $this->assertFalse(wp_next_scheduled(Plugin::SYNC_BATCH_HOOK));
    }

    /**
     * Charges that mean a customer is paying the invoice: inside the rate lock, a transfer confirming, or short.
     *
     * @return iterable<string, array{string}>
     */
    public static function paymentsInProgress(): iterable
    {
        yield 'a charge awaiting payment or confirming' => ['pending'];
        yield 'a short payment' => ['underpaid'];
    }

    #[DataProvider('paymentsInProgress')]
    public function test_the_hold_stock_timer_does_not_cancel_an_order_whose_invoice_is_being_paid(string $chargeStatus): void
    {
        $order = $this->pendingOrder();
        $this->answer(200, (string) json_encode(['data' => ['id' => 'invoice1042', 'status' => 'open', 'amount' => '49.90', 'currency' => 'EUR', 'charges' => [
            ['id' => 'oldcharge000', 'status' => 'expired', 'asset' => 'ETH'],
            ['id' => 'k3v9x2m7q8wz', 'status' => $chargeStatus, 'asset' => 'BTC'],
        ]]]));

        $this->assertFalse(Plugin::keepOrderBeingPaid(true, $order));
        $this->assertSame($chargeStatus === 'underpaid' ? 'on-hold' : 'pending', $order->get_status());
        $this->assertSame(['https://api.velirapay.com/v1/invoices/invoice1042'], $this->requests);
    }

    public function test_the_hold_stock_timer_marks_an_order_paid_instead_of_cancelling_it_when_its_invoice_was_paid(): void
    {
        $order = $this->pendingOrder();
        $this->answer(200, '{"data":{"id":"invoice1042","number":"INV-0042","status":"paid","amount":"49.90","currency":"EUR","charges":[{"id":"k3v9x2m7q8wz","status":"paid","asset":"BTC"}]}}');

        $this->assertFalse(Plugin::keepOrderBeingPaid(true, $order));
        $this->assertSame('processing', $order->get_status());
    }

    /**
     * Invoices nobody is paying, and answers that never change.
     *
     * @return iterable<string, array{int, string}>
     */
    public static function nothingToWaitFor(): iterable
    {
        yield 'an open invoice whose quotes ran out' => [200, '{"data":{"id":"invoice1042","status":"open","charges":[{"id":"k3v9x2m7q8wz","status":"expired","asset":"BTC"}]}}'];
        yield 'an open invoice no coin was picked for' => [200, '{"data":{"id":"invoice1042","status":"open","charges":[]}}'];
        yield 'a voided invoice' => [200, '{"data":{"id":"invoice1042","status":"void","charges":[]}}'];
        yield 'an invoice of the other mode or account' => [404, '{"message":"No such object."}'];
        yield 'a revoked key' => [401, '{"message":"Invalid API key."}'];
        yield 'a key without the permission' => [403, '{"message":"This API key does not have the invoices:read permission."}'];
    }

    #[DataProvider('nothingToWaitFor')]
    public function test_the_hold_stock_timer_cancels_an_order_nobody_is_paying(int $status, string $body): void
    {
        $order = $this->pendingOrder();
        $this->answer($status, $body);

        $this->assertTrue(Plugin::keepOrderBeingPaid(true, $order));
        $this->assertSame('pending', $order->get_status());
    }

    public function test_while_the_api_cannot_be_reached_only_orders_young_enough_to_be_being_paid_are_kept(): void
    {
        update_option('woocommerce_hold_stock_minutes', '60');
        $young = $this->pendingOrder();
        $young->set_date_created(gmdate('c', time() - 149 * 60));
        $old = $this->pendingOrder(1043);
        $old->set_date_created(gmdate('c', time() - 151 * 60));
        $this->answer(503, '{"message":"Try later."}');

        $this->assertFalse(Plugin::keepOrderBeingPaid(true, $young));
        $this->assertTrue(Plugin::keepOrderBeingPaid(true, $old));
        $this->assertCount(1, $this->requests);
    }

    public function test_the_hold_stock_timer_is_left_alone_for_orders_it_would_not_cancel_or_that_are_not_paid_with_velirapay(): void
    {
        $other = $this->pendingOrder();
        $other->set_payment_method('cod');
        $unpaid = $this->pendingOrder(1043);
        $unpaid->delete_meta_data(WooCommerceOrder::INVOICE_CODE_META);
        $this->answer(200, '{"data":{"id":"invoice1042","status":"open","charges":[{"id":"k3v9x2m7q8wz","status":"pending","asset":"BTC"}]}}');

        $this->assertFalse(Plugin::keepOrderBeingPaid(false, $this->pendingOrder(1044)));
        $this->assertTrue(Plugin::keepOrderBeingPaid(true, $other));
        $this->assertTrue(Plugin::keepOrderBeingPaid(true, $unpaid));
        $this->assertSame([], $this->requests);
    }

    public function test_a_void_that_cannot_reach_the_api_is_tried_again_from_wp_cron_and_noted_only_when_it_gives_up(): void
    {
        $order = $this->pendingOrder();
        $order->set_status('cancelled');
        $this->route(['POST invoices/invoice1042/void' => [503, '{"message":"Service Unavailable"}']]);

        Plugin::voidInvoiceOfCancelledOrder(1042, $order);

        foreach ([2 => 1, 3 => 5, 4 => 15, 5 => 60] as $attempt => $minutes) {
            $at = wp_next_scheduled(Plugin::VOID_HOOK, [1042, 'invoice1042', $attempt]);
            $this->assertIsInt($at, "Attempt {$attempt}");
            $this->assertEqualsWithDelta(time() + $minutes * 60, $at, 5);
            $this->assertSame([], wc_get_order_notes(['order_id' => 1042]));

            Plugin::retryVoid(1042, 'invoice1042', $attempt);
        }

        $this->assertCount(5, $this->sent);
        $this->assertFalse(wp_next_scheduled(Plugin::VOID_HOOK, [1042, 'invoice1042', 6]));
        $this->assertSame(['The VeliraPay invoice could not be voided: Service Unavailable'], array_column(wc_get_order_notes(['order_id' => 1042]), 'content'));
    }

    public function test_a_retried_void_is_dropped_once_the_order_is_no_longer_cancelled_or_has_another_invoice(): void
    {
        $order = $this->pendingOrder();
        $this->route([]);

        Plugin::retryVoid(1042, 'invoice1042', 2);
        $order->set_status('cancelled');
        Plugin::retryVoid(1042, 'otherinvoice', 2);

        $this->assertSame([], $this->sent);
    }

    public function test_a_void_refused_because_a_payment_keeps_the_invoice_payable_says_so(): void
    {
        $order = $this->pendingOrder();
        $order->set_status('cancelled');
        $this->route([
            'POST invoices/invoice1042/void' => [409, '{"message":"Only open invoices without a payment on its way can be voided."}'],
            'GET invoices/invoice1042' => [200, '{"data":{"id":"invoice1042","status":"open","charges":[{"id":"k3v9x2m7q8wz","status":"underpaid","asset":"BTC"}]}}'],
        ]);

        Plugin::voidInvoiceOfCancelledOrder(1042, $order);

        $this->assertSame(['VeliraPay invoice invoice1042 could not be voided: a short payment, or one still confirming, keeps it payable. Check it in the VeliraPay dashboard, where a short payment has to be accepted before it can be refunded.'], array_column(wc_get_order_notes(['order_id' => 1042]), 'content'));
        $this->assertSame([], $GLOBALS['velirapay_test_events'] ?? []);
    }

    public function test_a_void_refused_because_the_invoice_is_already_settled_adds_no_note(): void
    {
        $order = $this->pendingOrder();
        $order->set_status('cancelled');
        $this->route([
            'POST invoices/invoice1042/void' => [409, '{"message":"Only open invoices without a payment on its way can be voided."}'],
            'GET invoices/invoice1042' => [200, '{"data":{"id":"invoice1042","status":"void","charges":[]}}'],
        ]);

        Plugin::voidInvoiceOfCancelledOrder(1042, $order);

        $this->assertSame([], wc_get_order_notes(['order_id' => 1042]));
    }

    public function test_an_invoice_of_the_other_mode_or_account_is_noted_without_retrying(): void
    {
        $order = $this->pendingOrder();
        $order->set_status('cancelled');
        $this->route([]);

        Plugin::voidInvoiceOfCancelledOrder(1042, $order);

        $this->assertSame(['VeliraPay invoice invoice1042 could not be voided with the current API key: it belongs to the other mode or another account. Void it in the VeliraPay dashboard.'], array_column(wc_get_order_notes(['order_id' => 1042]), 'content'));
        $this->assertSame([], $GLOBALS['velirapay_test_events'] ?? []);
    }

    public function test_a_voided_invoice_is_noted(): void
    {
        $order = $this->pendingOrder();
        $order->set_status('cancelled');
        $this->route(['POST invoices/invoice1042/void' => [200, '{"data":{"id":"invoice1042","status":"void"}}']]);

        Plugin::voidInvoiceOfCancelledOrder(1042, $order);

        $this->assertSame(['The VeliraPay invoice was voided; it can no longer be paid.'], array_column(wc_get_order_notes(['order_id' => 1042]), 'content'));
    }

    public function test_api_requests_never_follow_a_redirect_with_the_key(): void
    {
        $sent = [];
        $GLOBALS['velirapay_test_http'] = static function (string $url, array $args) use (&$sent): array {
            $sent[] = $args;

            return ['status' => 302, 'body' => ''];
        };
        $client = new Client('vp_live_example', Plugin::httpTransport(), Plugin::userAgent());

        try {
            $client->retrieveInvoice('invoice1042');
            $this->fail('No exception was thrown.');
        } catch (ApiException $exception) {
            $this->assertSame(302, $exception->status);
        }

        $this->assertCount(1, $sent);
        $this->assertSame(0, $sent[0]['redirection'] ?? null);
        $this->assertSame('Bearer vp_live_example', is_array($sent[0]['headers'] ?? null) ? ($sent[0]['headers']['Authorization'] ?? null) : null);
    }

    public function test_a_key_refused_while_the_store_runs_is_shown_until_the_same_kind_of_request_succeeds(): void
    {
        $this->pendingOrder();
        $client = new Client('vp_live_example', Plugin::httpTransport(), Plugin::userAgent());
        $this->route(['POST invoices' => [403, '{"message":"This API key does not have the invoices:write permission."}']]);

        try {
            $client->createInvoice(['amount' => '49.90'], 'key');
        } catch (ApiException) {
        }

        $this->assertStringContainsString('VeliraPay refused the live API key: This API key does not have the invoices:write permission.', $this->notices());

        // The hourly poll's reads succeed, but say nothing of what the checkout needs.
        $this->route(['GET invoices/invoice1042' => [200, '{"data":{"id":"invoice1042","status":"open","charges":[]}}']]);
        $client->retrieveInvoice('invoice1042');

        $this->assertStringContainsString('invoices:write', $this->notices());

        $this->route(['POST invoices' => [201, '{"data":{"id":"invoice1042","status":"open"}}']]);
        $client->createInvoice(['amount' => '49.90'], 'key');

        $this->assertStringNotContainsString('refused', $this->notices());
    }

    /**
     * Statuses of an order whose invoice can still be paid.
     *
     * @return iterable<string, array{string}>
     */
    public static function unpaidStatuses(): iterable
    {
        yield 'pending' => ['pending'];
        yield 'on hold' => ['on-hold'];
        yield 'failed' => ['failed'];
    }

    #[DataProvider('unpaidStatuses')]
    public function test_trashing_an_unpaid_order_voids_its_invoice_from_wp_cron(string $status): void
    {
        $order = $this->pendingOrder();
        $order->set_status($status);
        $this->route(['POST invoices/invoice1042/void' => [200, '{"data":{"id":"invoice1042","status":"void"}}']]);

        Plugin::voidInvoiceOfRemovedOrder(1042, $order);
        // On the posts table, trashing through WooCommerce fires WordPress's own trash hook as well.
        Plugin::voidInvoiceOfTrashedPost(1042);

        $this->assertSame([], $this->sent);
        $this->assertCount(1, is_array($GLOBALS['velirapay_test_events'] ?? null) ? $GLOBALS['velirapay_test_events'] : []);
        $this->assertIsInt(wp_next_scheduled(Plugin::VOID_HOOK, [1042, 'invoice1042', 1]));

        $order->set_status('trash');
        Plugin::retryVoid(1042, 'invoice1042', 1);

        $this->assertSame(['POST invoices/invoice1042/void'], $this->sent);
        $this->assertSame(['The VeliraPay invoice was voided; it can no longer be paid.'], array_column(wc_get_order_notes(['order_id' => 1042]), 'content'));
    }

    public function test_deleting_an_unpaid_order_voids_its_invoice_once_it_is_gone(): void
    {
        $order = $this->pendingOrder();
        $this->route(['POST invoices/invoice1042/void' => [200, '{"data":{"id":"invoice1042","status":"void"}}']]);

        Plugin::voidInvoiceOfRemovedOrder(1042, $order);
        unset($GLOBALS['velirapay_test_orders']);
        Plugin::retryVoid(1042, 'invoice1042', 1);

        $this->assertSame(['POST invoices/invoice1042/void'], $this->sent);
        $this->assertSame(['Invoice invoice1042 of a deleted order: The VeliraPay invoice was voided; it can no longer be paid.'], $GLOBALS['velirapay_test_logs'] ?? null);
    }

    public function test_removing_an_order_that_cannot_be_paid_any_more_leaves_its_invoice_alone(): void
    {
        $paid = $this->pendingOrder();
        $paid->set_status('processing');
        $cancelled = $this->pendingOrder(1043);
        $cancelled->set_status('cancelled');
        $other = $this->pendingOrder(1044);
        $other->set_payment_method('cod');

        Plugin::voidInvoiceOfRemovedOrder(1042, $paid);
        Plugin::voidInvoiceOfRemovedOrder(1043, $cancelled);
        Plugin::voidInvoiceOfRemovedOrder(1044, $other);
        Plugin::voidInvoiceOfTrashedPost(999);

        $this->assertSame([], $GLOBALS['velirapay_test_events'] ?? []);
    }

    /**
     * Create a pending order paid with VeliraPay through the checkout, with an invoice, and a live-mode gateway.
     */
    private function pendingOrder(int $id = 1042): WC_Order
    {
        $order = new WC_Order($id);
        $order->update_meta_data(WooCommerceOrder::INVOICE_CODE_META, 'invoice'.$id);
        $order->set_date_created(gmdate('c'));
        $this->orders[$id] = $order;
        $GLOBALS['velirapay_test_orders'] = $this->orders;

        $gateway = (new ReflectionClass(Gateway::class))->newInstanceWithoutConstructor();
        $gateway->settings = ['live_api_key' => 'vp_live_example'];
        $GLOBALS['velirapay_test_gateway'] = $gateway;

        return $order;
    }

    /**
     * Get the admin notices the plugin prints for the store's configuration.
     */
    private function notices(): string
    {
        ob_start();
        Plugin::configurationNotices();

        return (string) ob_get_clean();
    }

    /**
     * Answer each request by its method and path, a 404 for any other, recording the method and path of each.
     *
     * @param  array<string, array{int, string}>  $answers
     */
    private function route(array $answers): void
    {
        $this->sent = [];
        $GLOBALS['velirapay_test_http'] = function (string $url, array $args) use ($answers): array {
            $route = (is_string($args['method'] ?? null) ? $args['method'] : '').' '.substr($url, strlen('https://api.velirapay.com/v1/'));
            $this->sent[] = $route;
            [$status, $body] = $answers[$route] ?? [404, '{"message":"No such object."}'];

            return ['status' => $status, 'body' => $body];
        };
    }

    /**
     * Answer every request the same way, recording each URL.
     */
    private function answer(int $status, string $body): void
    {
        $this->requests = [];
        $GLOBALS['velirapay_test_http'] = function (string $url) use ($status, $body): array {
            $this->requests[] = $url;

            return ['status' => $status, 'body' => $body];
        };
    }

    /**
     * Clear the store, scheduler and HTTP doubles after polling.
     */
    protected function tearDown(): void
    {
        unset(
            $GLOBALS['velirapay_test_orders'],
            $GLOBALS['velirapay_test_gateway'],
            $GLOBALS['velirapay_test_http'],
            $GLOBALS['velirapay_test_options'],
            $GLOBALS['velirapay_test_schedule'],
            $GLOBALS['velirapay_test_events'],
            $GLOBALS['velirapay_test_transients'],
            $GLOBALS['velirapay_test_logs'],
        );

        parent::tearDown();
    }
}
