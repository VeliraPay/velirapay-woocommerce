<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use VeliraPay\WooCommerce\Gateway;
use VeliraPay\WooCommerce\Orders\WooCommerceOrder;
use WC_Order;

final class GatewayTest extends TestCase
{
    public function test_an_invoice_inaccessible_to_the_current_key_stops_checkout_without_replacing_it(): void
    {
        $order = new WC_Order(1042);
        $order->update_meta_data(WooCommerceOrder::INVOICE_CODE_META, 'liveinvoice12');
        $GLOBALS['velirapay_test_orders'] = [1042 => $order];
        $requests = [];
        $GLOBALS['velirapay_test_http'] = static function (string $url, array $args) use (&$requests): array {
            $headers = is_array($args['headers'] ?? null) ? $args['headers'] : [];
            $requests[] = [$args['method'], $url, $headers['Authorization'] ?? null];

            return ['status' => 404, 'body' => '{"message":"No such object."}'];
        };
        $gateway = (new ReflectionClass(Gateway::class))->newInstanceWithoutConstructor();
        $gateway->settings = ['testmode' => 'yes', 'test_api_key' => 'vp_test_example'];

        $result = $gateway->process_payment(1042);

        $this->assertSame(['result' => 'failure'], $result);
        $this->assertSame([['GET', 'https://api.velirapay.com/v1/invoices/liveinvoice12', 'Bearer vp_test_example']], $requests);
        $this->assertSame('liveinvoice12', WooCommerceOrder::invoiceCodeOf($order));
    }

    public function test_an_open_invoice_matching_the_order_is_reused(): void
    {
        $order = new WC_Order(1042);
        $order->update_meta_data(WooCommerceOrder::INVOICE_CODE_META, 'liveinvoice12');
        $GLOBALS['velirapay_test_orders'] = [1042 => $order];
        $GLOBALS['velirapay_test_http'] = static fn (): array => [
            'status' => 200,
            'body' => '{"data":{"id":"liveinvoice12","status":"open","amount":"49.90","currency":"EUR","hosted_url":"https://velirapay.com/i/liveinvoice12"}}',
        ];
        $gateway = (new ReflectionClass(Gateway::class))->newInstanceWithoutConstructor();
        $gateway->settings = ['live_api_key' => 'vp_live_example'];

        $this->assertSame(['result' => 'success', 'redirect' => 'https://velirapay.com/i/liveinvoice12'], $gateway->process_payment(1042));
    }

    /**
     * Remove the store and HTTP doubles after each checkout.
     */
    protected function tearDown(): void
    {
        unset($GLOBALS['velirapay_test_orders'], $GLOBALS['velirapay_test_http']);

        parent::tearDown();
    }
}
