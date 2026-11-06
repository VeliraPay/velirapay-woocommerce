<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use VeliraPay\WooCommerce\Gateway;
use VeliraPay\WooCommerce\Orders\WooCommerceOrder;
use VeliraPay\WooCommerce\Plugin;
use WC_Order;

final class PluginTest extends TestCase
{
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
     * Clear the store, scheduler and HTTP doubles after polling.
     */
    protected function tearDown(): void
    {
        unset($GLOBALS['velirapay_test_orders'], $GLOBALS['velirapay_test_gateway'], $GLOBALS['velirapay_test_http'], $GLOBALS['velirapay_test_options'], $GLOBALS['velirapay_test_schedule']);

        parent::tearDown();
    }
}
