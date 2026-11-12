<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use VeliraPay\WooCommerce\Gateway;
use VeliraPay\WooCommerce\Orders\WooCommerceOrder;
use WC_Cart;
use WC_Order;

final class GatewayTest extends TestCase
{
    /**
     * The method, URL and timeout of each request the HTTP double answered.
     *
     * @var list<array{mixed, string, mixed}>
     */
    private array $requests = [];

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

    public function test_the_method_is_not_offered_below_the_lowest_price_of_the_currency(): void
    {
        $this->answerAssets([['id' => 'BTC', 'accepted' => true, 'minimum_price' => '0.50']]);
        $gateway = $this->gateway();

        $this->assertFalse($this->availableFor($gateway, '0.49'));
        $this->assertTrue($this->availableFor($gateway, '0.50'));

        $GLOBALS['velirapay_test_currency'] = 'TRY';
        $this->answerAssets([['id' => 'BTC', 'accepted' => true, 'minimum_price' => '25.00']]);

        $this->assertFalse($this->availableFor($gateway, '24.99'));
        $this->assertTrue($this->availableFor($gateway, '25.00'));
    }

    public function test_the_method_is_not_offered_when_no_coin_the_account_takes_reaches_the_total(): void
    {
        $this->answerAssets([
            ['id' => 'BTC', 'accepted' => false, 'minimum_price' => '0.50'],
            ['id' => 'USDT', 'accepted' => true, 'minimum_price' => '9.27'],
        ]);
        $gateway = $this->gateway();

        $this->assertFalse($this->availableFor($gateway, '5.00'));
        $this->assertTrue($this->availableFor($gateway, '9.27'));
        $this->assertSame([['GET', 'https://api.velirapay.com/v1/assets?currency=EUR', 3]], $this->requests);
        $this->assertSame('9.27', $gateway->lowestPayableAmount('EUR'));
    }

    public function test_a_coin_without_a_price_yet_is_taken_as_payable(): void
    {
        $this->answerAssets([['id' => 'USDC', 'accepted' => true, 'minimum_price' => null]]);

        $this->assertTrue($this->availableFor($this->gateway(), '5.00'));
    }

    public function test_an_account_taking_no_coin_is_not_offered(): void
    {
        $this->answerAssets([['id' => 'BTC', 'accepted' => false, 'minimum_price' => '0.50']]);
        $gateway = $this->gateway();

        $this->assertFalse($this->availableFor($gateway, '100.00'));
        $this->assertNull($gateway->lowestPayableAmount('EUR'));
    }

    public function test_an_api_that_cannot_be_reached_falls_back_on_the_lowest_price_and_is_not_asked_again_at_once(): void
    {
        $this->answer(503, '{"message":"Try later."}');
        $gateway = $this->gateway();

        $this->assertTrue($this->availableFor($gateway, '5.00'));
        $this->assertTrue($this->availableFor($gateway, '6.00'));
        $this->assertFalse($this->availableFor($gateway, '0.30'));
        $this->assertCount(1, $this->requests);
    }

    public function test_the_order_pay_page_is_checked_against_the_order(): void
    {
        $order = new WC_Order(1042);
        $order->set_total('0.40');
        $GLOBALS['velirapay_test_orders'] = [1042 => $order];
        $GLOBALS['velirapay_test_query_vars'] = ['order-pay' => '1042'];
        $this->answerAssets([['id' => 'BTC', 'accepted' => true, 'minimum_price' => '0.50']]);

        $this->assertFalse($this->gateway()->is_available());

        $order->set_total('12.00');

        $this->assertTrue($this->gateway()->is_available());
    }

    public function test_without_a_cart_the_method_is_offered_without_asking_the_api(): void
    {
        $this->answerAssets([]);

        $this->assertTrue($this->gateway()->is_available());
        $this->assertSame([], $this->requests);
    }

    public function test_an_unsupported_store_currency_is_not_offered(): void
    {
        $GLOBALS['velirapay_test_currency'] = 'JPY';

        $this->assertFalse($this->availableFor($this->gateway(), '1000'));
    }

    /**
     * Create a live-mode gateway with a key.
     */
    private function gateway(): Gateway
    {
        $gateway = (new ReflectionClass(Gateway::class))->newInstanceWithoutConstructor();
        $gateway->settings = ['live_api_key' => 'vp_live_example'];

        return $gateway;
    }

    /**
     * Determine if the gateway is offered for a cart coming to a total.
     */
    private function availableFor(Gateway $gateway, string $total): bool
    {
        $cart = new WC_Cart;
        $cart->set_total($total);
        $GLOBALS['velirapay_test_cart'] = $cart;

        return $gateway->is_available();
    }

    /**
     * Answer every request with the coins on offer, forgetting any remembered before.
     *
     * @param  list<array<string, mixed>>  $assets
     */
    private function answerAssets(array $assets): void
    {
        unset($GLOBALS['velirapay_test_transients']);
        $this->answer(200, (string) json_encode(['data' => $assets, 'meta' => ['mode' => 'live']]));
    }

    /**
     * Answer every request the same way, recording the method, URL and timeout of each.
     */
    private function answer(int $status, string $body): void
    {
        $this->requests = [];
        $GLOBALS['velirapay_test_http'] = function (string $url, array $args) use ($status, $body): array {
            $this->requests[] = [$args['method'] ?? null, $url, $args['timeout'] ?? null];

            return ['status' => $status, 'body' => $body];
        };
    }

    /**
     * Remove the store and HTTP doubles after each checkout.
     */
    protected function tearDown(): void
    {
        unset(
            $GLOBALS['velirapay_test_orders'],
            $GLOBALS['velirapay_test_http'],
            $GLOBALS['velirapay_test_cart'],
            $GLOBALS['velirapay_test_currency'],
            $GLOBALS['velirapay_test_query_vars'],
            $GLOBALS['velirapay_test_transients'],
            $GLOBALS['velirapay_test_notices'],
        );

        parent::tearDown();
    }
}
