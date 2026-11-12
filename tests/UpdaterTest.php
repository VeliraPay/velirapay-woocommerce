<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeliraPay\WooCommerce\Plugin;
use VeliraPay\WooCommerce\Updater;
use WP_Error;

final class UpdaterTest extends TestCase
{
    private const PLUGIN = 'velirapay-woocommerce/velirapay-woocommerce.php';

    /**
     * The URL and arguments of each request GitHub's double answered.
     *
     * @var list<array{string, array<array-key, mixed>}>
     */
    private array $requests = [];

    public function test_a_newer_release_with_the_plugin_zip_is_offered_and_github_is_asked_once(): void
    {
        $this->github(200, self::release('v0.4.1'));
        $updater = new Updater(self::PLUGIN);

        $update = $updater->check(false, ['Version' => '0.4.0', 'RequiresPHP' => '8.1'], self::PLUGIN);
        $again = $updater->check(false, ['Version' => '0.4.0', 'RequiresPHP' => '8.1'], self::PLUGIN);

        $this->assertSame([
            'id' => 'https://github.com/VeliraPay/velirapay-woocommerce',
            'slug' => 'velirapay-woocommerce',
            'version' => '0.4.1',
            'url' => 'https://github.com/VeliraPay/velirapay-woocommerce/releases/tag/v0.4.1',
            'package' => 'https://github.com/VeliraPay/velirapay-woocommerce/releases/download/v0.4.1/velirapay-woocommerce-0.4.1.zip',
            'requires_php' => '8.1',
        ], $update);
        $this->assertSame($update, $again);
        $this->assertCount(1, $this->requests);
        $this->assertSame('https://api.github.com/repos/VeliraPay/velirapay-woocommerce/releases/latest', $this->requests[0][0]);
        $this->assertSame(0, $this->requests[0][1]['redirection'] ?? null);
        $this->assertSame(5, $this->requests[0][1]['timeout'] ?? null);
        $this->assertSame(Plugin::userAgent(), $this->requests[0][1]['user-agent'] ?? null);
    }

    /**
     * Releases that are no update for version 0.4.0.
     *
     * @return iterable<string, array{string}>
     */
    public static function releasesNotToOffer(): iterable
    {
        yield 'the same version' => [self::release('v0.4.0')];
        yield 'an older version' => [self::release('v0.3.1')];
        yield 'no zip' => [(string) json_encode(['tag_name' => 'v0.4.1', 'assets' => []])];
        yield 'a zip of another name' => [self::release('v0.4.1', name: 'source.zip')];
        yield 'a zip from elsewhere' => [self::release('v0.4.1', url: 'https://example.com/velirapay-woocommerce/releases/download/v0.4.1/velirapay-woocommerce-0.4.1.zip')];
        yield 'a zip outside the release' => [self::release('v0.4.1', url: 'https://github.com/VeliraPay/velirapay-woocommerce/releases/download/v0.4.1/../../../other/velirapay-woocommerce-0.4.1.zip')];
        yield 'a tag that is not a version' => [self::release('nightly')];
        yield 'not a release' => ['<html>rate limited</html>'];
    }

    #[DataProvider('releasesNotToOffer')]
    public function test_a_release_that_is_not_newer_or_carries_no_plugin_zip_is_not_offered(string $body): void
    {
        $this->github(200, $body);

        $this->assertFalse((new Updater(self::PLUGIN))->check(false, ['Version' => '0.4.0'], self::PLUGIN));
    }

    public function test_a_failure_to_ask_github_is_ignored_and_remembered(): void
    {
        $this->github(403, '{"message":"API rate limit exceeded"}');
        $updater = new Updater(self::PLUGIN);

        $this->assertFalse($updater->check(false, ['Version' => '0.4.0'], self::PLUGIN));
        $this->assertFalse($updater->check(false, ['Version' => '0.4.0'], self::PLUGIN));
        $this->assertCount(1, $this->requests);
    }

    public function test_github_that_cannot_be_reached_is_ignored(): void
    {
        $GLOBALS['velirapay_test_http'] = static fn (): WP_Error => new WP_Error('cURL error 28');

        $this->assertFalse((new Updater(self::PLUGIN))->check(false, ['Version' => '0.4.0'], self::PLUGIN));
    }

    public function test_another_plugins_update_is_left_as_it_was(): void
    {
        $this->github(200, self::release('v9.9.9'));
        $theirs = ['version' => '2.0.0', 'package' => 'https://github.com/someone/else/releases/download/v2.0.0/else.zip'];

        $this->assertSame($theirs, (new Updater(self::PLUGIN))->check($theirs, ['Version' => '1.0.0'], 'else/else.php'));
        $this->assertSame([], $this->requests);
    }

    /**
     * Answer every request the same way, recording each URL and its arguments.
     */
    private function github(int $status, string $body): void
    {
        $GLOBALS['velirapay_test_http'] = function (string $url, array $args) use ($status, $body): array {
            $this->requests[] = [$url, $args];

            return ['status' => $status, 'body' => $body];
        };
    }

    /**
     * Build the latest release as GitHub's API describes it, with the plugin's zip.
     */
    private static function release(string $tag, ?string $name = null, ?string $url = null): string
    {
        $version = ltrim($tag, 'v');

        return (string) json_encode([
            'tag_name' => $tag,
            'html_url' => 'https://github.com/VeliraPay/velirapay-woocommerce/releases/tag/'.$tag,
            'body' => 'Release notes.',
            'assets' => [[
                'name' => $name ?? 'velirapay-woocommerce-'.$version.'.zip',
                'browser_download_url' => $url ?? 'https://github.com/VeliraPay/velirapay-woocommerce/releases/download/'.$tag.'/velirapay-woocommerce-'.$version.'.zip',
            ]],
        ]);
    }

    /**
     * Forget the remembered release and the HTTP double after each check.
     */
    protected function tearDown(): void
    {
        unset($GLOBALS['velirapay_test_http'], $GLOBALS['velirapay_test_transients']);

        parent::tearDown();
    }
}
