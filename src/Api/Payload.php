<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce\Api;

defined('ABSPATH') || exit;

/**
 * Sanitizes what VeliraPay sends, an API answer or a webhook delivery, as soon as it is decoded, before any of it reaches
 * an order note, the order's meta or a redirect.
 */
final class Payload
{
    /**
     * Sanitize a decoded object: every string becomes plain text, one under a key ending in "_url" an http or https URL
     * (or nothing), and numbers, booleans and null are left as they are, so an amount or a flag means what it did.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function sanitize(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            $sanitized[$key] = match (true) {
                is_array($value) => self::sanitize($value),
                is_string($value) && is_string($key) && str_ends_with($key, '_url') => esc_url_raw($value, ['https', 'http']),
                is_string($value) => sanitize_text_field($value),
                default => $value,
            };
        }

        return $sanitized;
    }
}
