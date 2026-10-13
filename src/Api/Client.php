<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce\Api;

use Closure;
use InvalidArgumentException;

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
     * Send a request and return the object the API answered with.
     *
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
    {
        $headers = [
            'Authorization' => 'Bearer '.$this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
        ];

        $encoded = null;

        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $encoded = json_encode($body, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $url = rtrim($this->baseUrl, '/').'/v1/'.ltrim($path, '/');
        $response = ($this->transport)($method, $url, $headers, $encoded, self::TIMEOUT);

        $decoded = json_decode($response['body'], true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw ApiException::fromResponse($response['status'], $decoded);
        }

        $data = $decoded['data'] ?? null;

        if (! is_array($data)) {
            throw new ApiException('The VeliraPay API answered without the expected object.', $response['status']);
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
