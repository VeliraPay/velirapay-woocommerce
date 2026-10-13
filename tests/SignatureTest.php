<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeliraPay\WooCommerce\Webhooks\Signature;

final class SignatureTest extends TestCase
{
    private const SECRET = 'whsec_2b7Qf9LkXw3NcR8vT1mZ5pYs0HdJ4gUe6AoKiVbE';

    private const PAYLOAD = '{"id":"5f0c6f5e-8d1b-4a53-9a57-3c2b1d0e9f87","event":"invoice.paid","mode":"live","data":{"invoice":{"code":"w7c2h5j9d3kx"}}}';

    public function test_a_signature_made_with_the_secret_is_accepted(): void
    {
        $header = Signature::header(self::PAYLOAD, self::SECRET);

        $this->assertTrue(Signature::verify(self::PAYLOAD, $header, [self::SECRET]));
    }

    public function test_a_signature_made_by_velirapay_is_accepted(): void
    {
        $payload = '{"id":"5f0c6f5e-8d1b-4a53-9a57-3c2b1d0e9f87","event_id":"9b1f7a3e-2c4d-4e8f-a6b0-1d2c3e4f5a6b","event":"charge.paid","mode":"live","created_at":"2026-09-20T10:05:42.000000Z","data":{"charge":{"code":"k3v9x2m7q8wz","status":"paid"}}}';
        $header = 't=1790000000,v1=a7a0dd3b10e4302cf840472806bb1c3a75453b7f8a6975c6475f92d58826df35';

        $this->assertTrue(Signature::verify($payload, $header, [self::SECRET], now: 1790000060));
        $this->assertSame($header, Signature::header($payload, self::SECRET, 1790000000));
    }

    public function test_a_signature_made_with_another_secret_is_rejected(): void
    {
        $header = Signature::header(self::PAYLOAD, 'whsec_other');

        $this->assertFalse(Signature::verify(self::PAYLOAD, $header, [self::SECRET]));
    }

    public function test_a_changed_payload_is_rejected(): void
    {
        $header = Signature::header(self::PAYLOAD, self::SECRET);

        $this->assertFalse(Signature::verify(str_replace('live', 'test', self::PAYLOAD), $header, [self::SECRET]));
    }

    public function test_any_of_the_listed_secrets_is_enough(): void
    {
        $header = Signature::header(self::PAYLOAD, self::SECRET);

        $this->assertTrue(Signature::verify(self::PAYLOAD, $header, ['whsec_live', self::SECRET, 'whsec_test']));
        $this->assertFalse(Signature::verify(self::PAYLOAD, $header, ['whsec_live', 'whsec_test']));
        $this->assertFalse(Signature::verify(self::PAYLOAD, $header, []));
    }

    public function test_one_matching_signature_among_several_is_enough(): void
    {
        [$timestamp, $signature] = explode(',', Signature::header(self::PAYLOAD, self::SECRET));

        $header = "{$timestamp},v1=".str_repeat('0', 64).",{$signature}";

        $this->assertTrue(Signature::verify(self::PAYLOAD, $header, [self::SECRET]));
    }

    public function test_a_stale_signature_is_rejected(): void
    {
        $header = Signature::header(self::PAYLOAD, self::SECRET, 1790000000);

        $this->assertTrue(Signature::verify(self::PAYLOAD, $header, [self::SECRET], now: 1790000300));
        $this->assertFalse(Signature::verify(self::PAYLOAD, $header, [self::SECRET], now: 1790000301));
        $this->assertFalse(Signature::verify(self::PAYLOAD, $header, [self::SECRET], now: 1789999699));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function malformedHeaders(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'garbage' => ['garbage'];
        yield 'timestamp that is not a number' => ['t=yesterday,v1='.str_repeat('a', 64)];
        yield 'no signature' => ['t=1790000000'];
        yield 'empty signature' => ['t=1790000000,v1='];
        yield 'another scheme only' => ['t=1790000000,v0='.str_repeat('a', 64)];
    }

    #[DataProvider('malformedHeaders')]
    public function test_a_malformed_header_is_rejected(?string $header): void
    {
        $this->assertFalse(Signature::verify(self::PAYLOAD, $header, [self::SECRET], now: 1790000000));
    }

    public function test_an_empty_secret_never_matches(): void
    {
        $header = Signature::header(self::PAYLOAD, '');

        $this->assertFalse(Signature::verify(self::PAYLOAD, $header, ['']));
    }

    public function test_secrets_are_read_from_a_comma_separated_list(): void
    {
        $this->assertSame(['whsec_a', 'whsec_b'], Signature::parseSecrets(' whsec_a, whsec_b ,, '));
        $this->assertSame([], Signature::parseSecrets(''));
    }
}
