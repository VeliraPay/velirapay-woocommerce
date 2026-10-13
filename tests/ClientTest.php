<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce\Tests;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VeliraPay\WooCommerce\Api\ApiException;
use VeliraPay\WooCommerce\Api\Client;

final class ClientTest extends TestCase
{
    /**
     * The requests the fake transport received.
     *
     * @var list<array{method: string, url: string, headers: array<array-key, mixed>, body: string|null, timeout: int}>
     */
    private array $requests = [];

    public function test_creating_an_invoice_sends_the_expected_request(): void
    {
        $client = $this->client(201, '{"data":{"id":"w7c2h5j9d3kx","number":"INV-0042","hosted_url":"https://velirapay.com/i/w7c2h5j9d3kx"}}');

        $invoice = $client->createInvoice([
            'customer_name' => 'Grace Hopper',
            'customer_email' => 'grace@example.com',
            'amount' => '49.90',
            'currency' => 'EUR',
            'memo' => 'Order #1042 at Grace\'s Shop',
            'send_email' => false,
        ], 'woocommerce-order-1042-wc_order_abc');

        $this->assertSame('w7c2h5j9d3kx', $invoice['id']);
        $this->assertSame('https://velirapay.com/i/w7c2h5j9d3kx', $invoice['hosted_url']);

        $request = $this->requests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://api.velirapay.com/v1/invoices', $request['url']);
        $this->assertSame('Bearer vp_test_secret', $request['headers']['Authorization']);
        $this->assertSame('application/json', $request['headers']['Accept']);
        $this->assertSame('application/json', $request['headers']['Content-Type']);
        $this->assertSame('velirapay-woocommerce/0.1.0', $request['headers']['User-Agent']);
        $this->assertSame('woocommerce-order-1042-wc_order_abc', $request['headers']['Idempotency-Key']);
        $this->assertSame(15, $request['timeout']);
        $this->assertSame(
            '{"customer_name":"Grace Hopper","customer_email":"grace@example.com","amount":"49.90","currency":"EUR","memo":"Order #1042 at Grace\'s Shop","send_email":false}',
            $request['body'],
        );
    }

    public function test_retrieving_an_invoice_is_a_bare_get(): void
    {
        $client = $this->client(200, '{"data":{"id":"w7c2h5j9d3kx","status":"open"}}');

        $invoice = $client->retrieveInvoice('w7c2h5j9d3kx');

        $this->assertSame('open', $invoice['status']);
        $this->assertSame('GET', $this->requests[0]['method']);
        $this->assertSame('https://api.velirapay.com/v1/invoices/w7c2h5j9d3kx', $this->requests[0]['url']);
        $this->assertNull($this->requests[0]['body']);
        $this->assertArrayNotHasKey('Content-Type', $this->requests[0]['headers']);
        $this->assertArrayNotHasKey('Idempotency-Key', $this->requests[0]['headers']);
    }

    public function test_voiding_an_invoice_posts_to_its_void_action(): void
    {
        $client = $this->client(200, '{"data":{"id":"w7c2h5j9d3kx","status":"void"}}');

        $client->voidInvoice('w7c2h5j9d3kx');

        $this->assertSame('POST', $this->requests[0]['method']);
        $this->assertSame('https://api.velirapay.com/v1/invoices/w7c2h5j9d3kx/void', $this->requests[0]['url']);
    }

    public function test_an_empty_code_is_refused_before_any_request(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->client(200, '{}')->retrieveInvoice(' ');
    }

    public function test_a_validation_error_carries_the_status_message_and_fields(): void
    {
        $client = $this->client(422, '{"message":"The amount field must be at least 0.01. (and 1 more error)","errors":{"amount":["The amount field must be at least 0.01."],"currency":["The selected currency is invalid."]}}');

        try {
            $client->createInvoice(['amount' => '0'], 'key');
            $this->fail('No exception was thrown.');
        } catch (ApiException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertSame('The amount field must be at least 0.01. (and 1 more error)', $exception->getMessage());
            $this->assertSame([
                'amount' => ['The amount field must be at least 0.01.'],
                'currency' => ['The selected currency is invalid.'],
            ], $exception->errors);
            $this->assertFalse($exception->isConflict());
            $this->assertFalse($exception->isNotFound());
        }
    }

    public function test_a_conflict_and_a_missing_object_are_told_apart(): void
    {
        try {
            $this->client(409, '{"message":"Only open invoices without a payment on its way can be voided."}')->voidInvoice('w7c2h5j9d3kx');
            $this->fail('No exception was thrown.');
        } catch (ApiException $exception) {
            $this->assertTrue($exception->isConflict());
            $this->assertSame('Only open invoices without a payment on its way can be voided.', $exception->getMessage());
        }

        try {
            $this->client(404, '{"message":"No such object."}')->retrieveInvoice('nope');
            $this->fail('No exception was thrown.');
        } catch (ApiException $exception) {
            $this->assertTrue($exception->isNotFound());
            $this->assertSame([], $exception->errors);
        }
    }

    public function test_an_error_without_a_json_body_gets_a_message_from_its_status(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('The VeliraPay API answered with HTTP 502.');

        $this->client(502, '<html>Bad gateway</html>')->retrieveInvoice('w7c2h5j9d3kx');
    }

    public function test_a_success_without_the_object_is_an_error(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('without the expected object');

        $this->client(200, '{"ok":true}')->retrieveInvoice('w7c2h5j9d3kx');
    }

    public function test_a_transport_failure_is_passed_on(): void
    {
        $client = new Client('vp_live_secret', static function (): array {
            throw new ApiException('Could not reach the VeliraPay API: cURL error 28', 0);
        }, 'velirapay-woocommerce/0.1.0');

        try {
            $client->retrieveInvoice('w7c2h5j9d3kx');
            $this->fail('No exception was thrown.');
        } catch (ApiException $exception) {
            $this->assertSame(0, $exception->status);
            $this->assertStringContainsString('cURL error 28', $exception->getMessage());
        }
    }

    public function test_the_mode_is_read_from_the_key_prefix(): void
    {
        $this->assertSame('live', Client::modeOf('vp_live_abc'));
        $this->assertSame('test', Client::modeOf('vp_test_abc'));
        $this->assertNull(Client::modeOf('sk_live_abc'));
        $this->assertNull(Client::modeOf(''));

        $this->assertTrue($this->client(200, '{}', 'vp_test_abc')->isTestMode());
        $this->assertFalse($this->client(200, '{}', 'vp_live_abc')->isTestMode());
    }

    public function test_a_key_without_a_mode_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->client(200, '{}', 'not-a-key');
    }

    public function test_the_base_url_can_be_changed(): void
    {
        $client = new Client('vp_test_secret', $this->transport(200, '{"data":{}}'), 'velirapay-woocommerce/0.1.0', 'https://api.velirapay.test/');

        $client->retrieveInvoice('w7c2h5j9d3kx');

        $this->assertSame('https://api.velirapay.test/v1/invoices/w7c2h5j9d3kx', $this->requests[0]['url']);
    }

    /**
     * Create a client whose every request gets the given answer.
     */
    private function client(int $status, string $body, string $apiKey = 'vp_test_secret'): Client
    {
        return new Client($apiKey, $this->transport($status, $body), 'velirapay-woocommerce/0.1.0');
    }

    /**
     * Create a transport that records requests and answers them all the same way.
     *
     * @return Closure(string, string, array<string, string>, string|null, int): array{status: int, body: string}
     */
    private function transport(int $status, string $body): Closure
    {
        return function (string $method, string $url, array $headers, ?string $requestBody, int $timeout) use ($status, $body): array {
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $requestBody, 'timeout' => $timeout];

            return ['status' => $status, 'body' => $body];
        };
    }
}
