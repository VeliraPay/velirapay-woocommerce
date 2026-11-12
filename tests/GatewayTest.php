<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce\Tests;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use VeliraPay\WooCommerce\Gateway;
use VeliraPay\WooCommerce\Orders\StatusSync;
use VeliraPay\WooCommerce\Orders\WooCommerceOrder;
use VeliraPay\WooCommerce\Orders\WooCommerceOrders;
use VeliraPay\WooCommerce\Plugin;
use VeliraPay\WooCommerce\Webhooks\Handler;
use VeliraPay\WooCommerce\Webhooks\Signature;
use WC_Cart;
use WC_Order;
use WP_User;

final class GatewayTest extends TestCase
{
    private const SECRET = 'whsec_live_2b7Qf9LkXw3NcR8vT1mZ5pYs0HdJ4gUe6AoKiVbE';

    /**
     * The method, URL and timeout of each request the HTTP double answered.
     *
     * @var list<array{mixed, string, mixed}>
     */
    private array $requests = [];

    /**
     * The method and path, and the idempotency key, of each request the routing HTTP double answered.
     *
     * @var list<array{string, string|null}>
     */
    private array $sent = [];

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

    public function test_a_replacement_whose_creation_fails_leaves_no_voided_invoice_current_for_its_void_to_cancel_the_order(): void
    {
        $order = $this->orderWithOutdatedInvoice();
        $this->route([
            'GET invoices/oldinvoice12' => [200, self::invoiceJson('oldinvoice12', 'open', '49.90')],
            'POST invoices/oldinvoice12/void' => [200, self::invoiceJson('oldinvoice12', 'void', '49.90')],
            'POST invoices' => [500, '{"message":"Server Error"}'],
        ]);

        $this->assertSame(['result' => 'failure'], $this->gateway()->process_payment(1042));
        $this->assertSame('', WooCommerceOrder::invoiceCodeOf($order));
        $this->assertSame(['oldinvoice12'], WooCommerceOrder::supersededInvoiceCodesOf($order));

        // The void's own webhook, and the hourly poll, no longer take the voided invoice for the order's.
        $this->deliver('invoice.voided', ['invoice' => ['code' => 'oldinvoice12', 'number' => 'INV-0041', 'status' => 'void']]);
        Plugin::syncPendingOrders();

        $this->assertSame('pending', $order->get_status());
    }

    public function test_the_attempt_after_a_failed_replacement_uses_the_same_idempotency_key(): void
    {
        $order = $this->orderWithOutdatedInvoice();
        $this->route([
            'GET invoices/oldinvoice12' => [200, self::invoiceJson('oldinvoice12', 'open', '49.90')],
            'POST invoices/oldinvoice12/void' => [200, self::invoiceJson('oldinvoice12', 'void', '49.90')],
            'POST invoices' => [500, '{"message":"Server Error"}'],
        ]);
        $this->gateway()->process_payment(1042);
        $this->route(['POST invoices' => [201, self::invoiceJson('newinvoice34', 'open', '64.90')]]);

        $result = $this->gateway()->process_payment(1042);

        $this->assertSame(['result' => 'success', 'redirect' => 'https://velirapay.com/i/newinvoice34'], $result);
        $this->assertSame('newinvoice34', WooCommerceOrder::invoiceCodeOf($order));
        $this->assertSame(['oldinvoice12'], WooCommerceOrder::supersededInvoiceCodesOf($order));
        $creations = array_values(array_filter($this->sent, static fn (array $request): bool => $request[0] === 'POST invoices'));
        $this->assertCount(2, $creations);
        $this->assertNotNull($creations[0][1]);
        $this->assertSame($creations[0][1], $creations[1][1]);
    }

    public function test_the_void_of_an_outdated_invoice_delivered_during_its_replacement_leaves_the_order_alone(): void
    {
        $order = $this->orderWithOutdatedInvoice();
        $this->route([
            'GET invoices/oldinvoice12' => [200, self::invoiceJson('oldinvoice12', 'open', '49.90')],
            'POST invoices/oldinvoice12/void' => function (): array {
                // VeliraPay announces the void as soon as it commits, before the store has the new invoice.
                $this->deliver('invoice.voided', ['invoice' => ['code' => 'oldinvoice12', 'number' => 'INV-0041', 'status' => 'void']]);

                return [200, self::invoiceJson('oldinvoice12', 'void', '49.90')];
            },
            'POST invoices' => [201, self::invoiceJson('newinvoice34', 'open', '64.90')],
        ]);

        $this->assertSame(['result' => 'success', 'redirect' => 'https://velirapay.com/i/newinvoice34'], $this->gateway()->process_payment(1042));
        $this->assertSame('pending', $order->get_status());
        $this->assertSame('newinvoice34', WooCommerceOrder::invoiceCodeOf($order));
    }

    /**
     * Ways VeliraPay can refuse to void an outdated invoice.
     *
     * @return iterable<string, array{int, array{result: string, redirect?: string}}>
     */
    public static function refusedVoids(): iterable
    {
        yield 'a payment is on its way' => [409, ['result' => 'success', 'redirect' => 'https://velirapay.com/i/oldinvoice12']];
        yield 'VeliraPay cannot be reached' => [503, ['result' => 'failure']];
    }

    /**
     * @param  array{result: string, redirect?: string}  $result
     */
    #[DataProvider('refusedVoids')]
    public function test_an_outdated_invoice_that_could_not_be_voided_stays_the_orders_own(int $status, array $result): void
    {
        $order = $this->orderWithOutdatedInvoice();
        $this->route([
            'GET invoices/oldinvoice12' => [200, self::invoiceJson('oldinvoice12', 'open', '49.90')],
            'POST invoices/oldinvoice12/void' => [$status, '{"message":"Only open invoices without a payment on its way can be voided."}'],
        ]);

        $this->assertSame($result, $this->gateway()->process_payment(1042));
        $this->assertSame('oldinvoice12', WooCommerceOrder::invoiceCodeOf($order));
        $this->assertSame('https://velirapay.com/i/oldinvoice12', $order->get_meta(WooCommerceOrder::INVOICE_URL_META));
        $this->assertSame([], WooCommerceOrder::supersededInvoiceCodesOf($order));
        $this->assertNotContains('POST invoices', array_column($this->sent, 0));
    }

    /**
     * Fields whose refusal the customer can act on, and how VeliraPay words it.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function refusalsTheCustomerCanFix(): iterable
    {
        yield 'an email that cannot receive mail' => ['customer_email', 'Mail cannot be delivered to this address. Check how it is spelled.'];
        yield 'an amount under the lowest price' => ['amount', 'Enter at least €0.50.'];
    }

    #[DataProvider('refusalsTheCustomerCanFix')]
    public function test_a_refusal_the_customer_can_fix_is_shown_at_checkout(string $field, string $message): void
    {
        $order = new WC_Order(1042);
        $GLOBALS['velirapay_test_orders'] = [1042 => $order];
        $this->route(['POST invoices' => [422, (string) json_encode(['message' => $message, 'errors' => [$field => [$message], 'memo' => ['Too long <b>memo</b>.']]])]]);

        $this->assertSame(['result' => 'failure'], $this->gateway()->process_payment(1042));
        $this->assertSame(['Crypto payment: '.esc_html($message)], array_column(wc_get_notices('error'), 'notice'));
        $this->assertSame(['VeliraPay could not create an invoice: '.$message], array_column(wc_get_order_notes(['order_id' => 1042]), 'content'));
    }

    /**
     * Refusals and failures the customer cannot act on.
     *
     * @return iterable<string, array{int, string}>
     */
    public static function otherFailures(): iterable
    {
        yield 'another field' => [422, '{"message":"The selected currency is invalid.","errors":{"currency":["The selected currency is invalid."]}}'];
        yield 'a refused key' => [403, '{"message":"This API key does not have the invoices:write permission."}'];
        yield 'a server error' => [500, '{"message":"Server Error"}'];
    }

    #[DataProvider('otherFailures')]
    public function test_any_other_failure_shows_the_general_notice(int $status, string $body): void
    {
        $GLOBALS['velirapay_test_orders'] = [1042 => new WC_Order(1042)];
        $this->route(['POST invoices' => [$status, $body]]);

        $this->assertSame(['result' => 'failure'], $this->gateway()->process_payment(1042));
        $this->assertSame(['The crypto payment could not be started. Please try again or choose another payment method.'], array_column(wc_get_notices('error'), 'notice'));
    }

    public function test_an_order_without_an_email_is_refused_before_asking_the_api(): void
    {
        $order = new WC_Order(1042);
        $order->set_billing_email('');
        $GLOBALS['velirapay_test_orders'] = [1042 => $order];
        $this->route([]);

        $this->assertSame(['result' => 'failure'], $this->gateway()->process_payment(1042));
        $this->assertSame([], $this->sent);
        $this->assertSame(['Crypto payment: an email address is needed to pay. Please enter your billing email address.'], array_column(wc_get_notices('error'), 'notice'));
    }

    public function test_an_order_without_an_email_uses_its_customers_account_email(): void
    {
        $order = new WC_Order(1042);
        $order->set_billing_email('');
        $order->set_customer_id(7);
        $user = new WP_User;
        $user->user_email = 'ada@example.com';
        $GLOBALS['velirapay_test_users'] = [7 => $user];
        $GLOBALS['velirapay_test_orders'] = [1042 => $order];
        $bodies = [];
        $GLOBALS['velirapay_test_http'] = static function (string $url, array $args) use (&$bodies): array {
            $bodies[] = $args['body'] ?? null;

            return ['status' => 201, 'body' => self::invoiceJson('newinvoice34', 'open', '49.90')];
        };

        $this->assertSame(['result' => 'success', 'redirect' => 'https://velirapay.com/i/newinvoice34'], $this->gateway()->process_payment(1042));
        $this->assertCount(1, $bodies);
        $this->assertIsString($bodies[0]);
        $this->assertStringContainsString('"customer_email":"ada@example.com"', $bodies[0]);
    }

    public function test_the_webhook_url_is_always_https(): void
    {
        $GLOBALS['velirapay_test_home'] = 'http://store.example';

        $this->assertSame('https://store.example/wc-api/velirapay/', $this->gateway()->webhookUrl());
    }

    public function test_the_settings_warn_a_site_not_served_over_https(): void
    {
        $gateway = $this->gateway();

        $GLOBALS['velirapay_test_home'] = 'http://store.example';
        $insecure = $gateway->generate_velirapay_webhook_url_html('webhook_url', ['title' => 'Webhook URL']);
        $GLOBALS['velirapay_test_home'] = 'https://store.example';
        $secure = $gateway->generate_velirapay_webhook_url_html('webhook_url', ['title' => 'Webhook URL']);

        $this->assertStringContainsString('value="https://store.example/wc-api/velirapay/"', $insecure);
        $this->assertStringContainsString('VeliraPay only sends webhooks to https:// addresses on port 443', $insecure);
        $this->assertStringNotContainsString('VeliraPay only sends webhooks to https:// addresses on port 443', $secure);
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
     * Create a live-mode gateway with a key and make it the store's.
     */
    private function gateway(): Gateway
    {
        $gateway = (new ReflectionClass(Gateway::class))->newInstanceWithoutConstructor();
        $gateway->settings = ['live_api_key' => 'vp_live_example', 'live_webhook_secret' => self::SECRET];
        $GLOBALS['velirapay_test_gateway'] = $gateway;

        return $gateway;
    }

    /**
     * Create order 1042, now coming to 64.90 EUR, whose invoice was raised for 49.90.
     */
    private function orderWithOutdatedInvoice(): WC_Order
    {
        $order = new WC_Order(1042);
        $order->set_total('64.90');
        $order->update_meta_data(WooCommerceOrder::INVOICE_CODE_META, 'oldinvoice12');
        $order->update_meta_data(WooCommerceOrder::INVOICE_URL_META, 'https://velirapay.com/i/oldinvoice12');
        $GLOBALS['velirapay_test_orders'] = [1042 => $order];

        return $order;
    }

    /**
     * Post a signed live delivery to the webhook handler, as the store's endpoint would.
     *
     * @param  array<string, mixed>  $data
     */
    private function deliver(string $event, array $data): void
    {
        $payload = (string) json_encode(['id' => 'delivery-'.$event, 'event_id' => 'event-'.$event, 'event' => $event, 'mode' => 'live', 'data' => $data]);

        (new Handler(new WooCommerceOrders, new StatusSync, 'live', [self::SECRET]))->handle($payload, Signature::header($payload, self::SECRET));
    }

    /**
     * Answer each request by its method and path, recording the method and path, and the idempotency key, of each.
     *
     * @param  array<string, array{int, string}|Closure(): array{int, string}>  $answers
     */
    private function route(array $answers): void
    {
        $GLOBALS['velirapay_test_http'] = function (string $url, array $args) use ($answers): array {
            $route = (is_string($args['method'] ?? null) ? $args['method'] : '').' '.substr($url, strlen('https://api.velirapay.com/v1/'));
            $headers = is_array($args['headers'] ?? null) ? $args['headers'] : [];
            $key = $headers['Idempotency-Key'] ?? null;
            $this->sent[] = [$route, is_string($key) ? $key : null];
            $answer = $answers[$route] ?? [404, '{"message":"No such object."}'];
            [$status, $body] = $answer instanceof Closure ? $answer() : $answer;

            return ['status' => $status, 'body' => $body];
        };
    }

    /**
     * Build an invoice as the API returns it.
     */
    private static function invoiceJson(string $code, string $status, string $amount): string
    {
        return (string) json_encode(['data' => [
            'id' => $code,
            'number' => 'INV-'.$code,
            'status' => $status,
            'amount' => $amount,
            'currency' => 'EUR',
            'hosted_url' => 'https://velirapay.com/i/'.$code,
            'charges' => [],
        ]]);
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
            $GLOBALS['velirapay_test_gateway'],
            $GLOBALS['velirapay_test_options'],
            $GLOBALS['velirapay_test_schedule'],
            $GLOBALS['velirapay_test_events'],
            $GLOBALS['velirapay_test_logs'],
            $GLOBALS['velirapay_test_users'],
            $GLOBALS['velirapay_test_home'],
        );

        parent::tearDown();
    }
}
