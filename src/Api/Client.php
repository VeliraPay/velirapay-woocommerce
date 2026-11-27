<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce\Api;

use Closure;
use InvalidArgumentException;

defined('ABSPATH') || exit;

/**
 * Calls the VeliraPay API through an HTTP transport handed in, so it runs without WordPress in tests.
 */
final class Client
{
    /**
     * Where the API lives.
     */
    public const BASE_URL = 'https://api.velirapay.com';

    /**
     * How many seconds a request may take.
     */
    public const TIMEOUT = 15;

    /**
     * How many invoices a list asks for, the most the API gives at once.
     */
    public const LISTED = 100;

    /**
     * The prefix of a live API key.
     */
    public const LIVE_PREFIX = 'vp_live_';

    /**
     * The prefix of a test API key.
     */
    public const TEST_PREFIX = 'vp_test_';

    /**
     * Create a new client.
     *
     * @param  Closure(string, string, array<string, string>, string|null, int): array{status: int, body: string}  $transport  Sends one request, given the method, URL, headers, body and timeout.
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        private string $apiKey,
        private Closure $transport,
        private string $userAgent,
        private string $baseUrl = self::BASE_URL,
    ) {
        if (self::modeOf($apiKey) === null) {
            throw new InvalidArgumentException('A VeliraPay API key starts with vp_live_ or vp_test_.');
        }
    }

    /**
     * Get the mode a key works in from its prefix: "live", "test", or null for a key the API would refuse.
     */
    public static function modeOf(string $apiKey): ?string
    {
        return match (true) {
            str_starts_with($apiKey, self::LIVE_PREFIX) => 'live',
            str_starts_with($apiKey, self::TEST_PREFIX) => 'test',
            default => null,
        };
    }

    /**
     * Determine if the client works in test mode, on the test networks.
     */
    public function isTestMode(): bool
    {
        return self::modeOf($this->apiKey) === 'test';
    }

    /**
     * Create an invoice.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function createInvoice(array $params, string $idempotencyKey): array
    {
        return $this->request('POST', 'invoices', $params, $idempotencyKey);
    }

    /**
     * Retrieve an invoice by its code.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function retrieveInvoice(string $code): array
    {
        return $this->request('GET', 'invoices/'.self::segment($code));
    }

    /**
     * List the newest open invoices whose number, customer name or customer email holds a term, a hundred at most.
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws ApiException
     */
    public function openInvoicesMatching(string $search): array
    {
        return $this->invoicesMatching($search, 'open');
    }

    /**
     * List the paid invoices whose number or customer contains the search text, newest first, up to the most the API gives at once.
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws ApiException
     */
    public function paidInvoicesMatching(string $search): array
    {
        return $this->invoicesMatching($search, 'paid');
    }

    /**
     * List the invoices of one status whose number or customer contains the search text.
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws ApiException
     */
    private function invoicesMatching(string $search, string $status): array
    {
        $invoices = $this->request('GET', 'invoices?'.http_build_query(['status' => $status, 'search' => $search, 'per_page' => self::LISTED], '', '&', PHP_QUERY_RFC3986));

        return array_values(array_filter($invoices, 'is_array'));
    }

    /**
     * Void an open invoice so it can no longer be paid.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function voidInvoice(string $code): array
    {
        return $this->request('POST', 'invoices/'.self::segment($code).'/void');
    }

    /**
     * List the coins on offer in the key's mode, priced in a currency: whether the account takes each, and the lowest
     * price each can be charged at.
     *
     * @param  int  $timeout  How many seconds to wait, shorter than usual where a page is waiting.
     * @return array<array-key, mixed>
     *
     * @throws ApiException
     */
    public function listAssets(string $currency, int $timeout = self::TIMEOUT): array
    {
        return $this->request('GET', 'assets?currency='.rawurlencode($currency), timeout: $timeout);
    }

    /**
     * Send a request and return the object the API answered with.
     *
     * @param  array<string, mixed>|null  $body
     * @param  int  $timeout  How many seconds the request may take.
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null, int $timeout = self::TIMEOUT): array
    {
        $headers = [
            'Authorization' => 'Bearer '.$this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
        ];

        $encoded = null;

        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $encoded = (string) wp_json_encode($body, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $url = rtrim($this->baseUrl, '/').'/v1/'.ltrim($path, '/');
        $response = ($this->transport)($method, $url, $headers, $encoded, $timeout);

        $decoded = json_decode($response['body'], true);
        $decoded = is_array($decoded) ? Payload::sanitize($decoded) : [];

        if ($response['status'] < 200 || $response['status'] >= 300) {
            // Built before the throw, whose arguments are checked for escaping: fromResponse() escapes the API's message itself.
            $exception = ApiException::fromResponse($response['status'], $decoded);

            throw $exception;
        }

        $data = $decoded['data'] ?? null;

        if (! is_array($data)) {
            throw new ApiException('The VeliraPay API answered without the expected object.', (int) $response['status']);
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Make an object's code safe to put in a path.
     *
     * @throws InvalidArgumentException
     */
    private static function segment(string $code): string
    {
        if (trim($code) === '') {
            throw new InvalidArgumentException('An invoice code is required.');
        }

        return rawurlencode($code);
    }
}
