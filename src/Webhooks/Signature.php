<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce\Webhooks;

/**
 * Checks the signature VeliraPay puts on every webhook delivery.
 */
final class Signature
{
    /**
     * The header carrying the delivery's signature.
     */
    public const HEADER = 'X-VeliraPay-Signature';

    /**
     * How many seconds a signature stays valid.
     */
    public const TOLERANCE = 300;

    /**
     * Determine if the payload was signed with one of the secrets, recently.
     *
     * @param  string  $payload  The raw request body, exactly as received.
     * @param  string|null  $header  The X-VeliraPay-Signature header: "t=<unix time>,v1=<hex>", possibly with several v1 values.
     * @param  list<string>  $secrets  The endpoint secrets to try, each starting with "whsec_".
     */
    public static function verify(string $payload, ?string $header, array $secrets, int $tolerance = self::TOLERANCE, ?int $now = null): bool
    {
        if ($header === null || trim($header) === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && $timestamp === null) {
                $timestamp = $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || preg_match('/^\d{1,12}$/', $timestamp) !== 1 || $signatures === []) {
            return false;
        }

        if (abs(($now ?? time()) - (int) $timestamp) > $tolerance) {
            return false;
        }

        foreach ($secrets as $secret) {
            if ($secret === '') {
                continue;
            }

            $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

            foreach ($signatures as $signature) {
                if (hash_equals($expected, $signature)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Build the header VeliraPay would send with a payload, to test a receiver.
     */
    public static function header(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return "t={$timestamp},v1=".hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }

    /**
     * Split a comma-separated list of secrets, as entered in the settings, into the non-empty ones.
     *
     * @return list<string>
     */
    public static function parseSecrets(string $list): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $list)),
            static fn (string $secret): bool => $secret !== '',
        ));
    }
}
