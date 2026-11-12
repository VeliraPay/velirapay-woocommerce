<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce;

/**
 * Offers new versions of the plugin from its GitHub releases on WordPress's own update screens. The plugin's Update URI
 * header names github.com, so WordPress asks the update_plugins_github.com filter rather than WordPress.org.
 */
final class Updater
{
    /**
     * The plugin's Update URI, as in its header.
     */
    public const UPDATE_URI = 'https://github.com/VeliraPay/velirapay-woocommerce';

    /**
     * Where GitHub describes the plugin's latest release.
     */
    public const LATEST_RELEASE_URL = 'https://api.github.com/repos/VeliraPay/velirapay-woocommerce/releases/latest';

    /**
     * Where the plugin's release zips are downloaded from: a package anywhere else is never offered.
     */
    public const DOWNLOADS = 'https://github.com/VeliraPay/velirapay-woocommerce/releases/download/';

    /**
     * The site transient holding the latest release, or nothing when it could not be read.
     */
    private const CACHE = 'velirapay_latest_release';

    /**
     * How many seconds the latest release, or a failure to read it, is remembered for: twelve hours.
     */
    private const CACHE_SECONDS = 43200;

    /**
     * How many seconds GitHub is given to answer.
     */
    private const TIMEOUT = 5;

    /**
     * Create an updater for the plugin.
     *
     * @param  string  $basename  The plugin's file relative to the plugins folder, such as "velirapay-woocommerce/velirapay-woocommerce.php".
     */
    public function __construct(private string $basename) {}

    /**
     * Answer WordPress's update check for the plugins hosted on github.com: for this plugin, its latest release when it
     * is newer than the installed version and carries the plugin's zip, and for any other, what it had so far.
     *
     * @param  mixed  $update  The update found so far, false for none.
     * @param  mixed  $pluginData  The plugin's headers.
     * @param  mixed  $pluginFile  The plugin's file relative to the plugins folder.
     */
    public function check(mixed $update, mixed $pluginData = null, mixed $pluginFile = null): mixed
    {
        if ($pluginFile !== $this->basename) {
            return $update;
        }

        $headers = is_array($pluginData) ? $pluginData : [];
        $installed = is_string($headers['Version'] ?? null) ? $headers['Version'] : Plugin::VERSION;
        $offer = self::offer($this->latestRelease(), $installed);

        if ($offer === null) {
            return $update;
        }

        if (is_string($headers['RequiresPHP'] ?? null) && $headers['RequiresPHP'] !== '') {
            $offer['requires_php'] = $headers['RequiresPHP'];
        }

        return $offer;
    }

    /**
     * Get the update a release offers, or null when it is no newer than the installed version, or carries no zip of the
     * plugin downloaded from its own releases.
     *
     * @param  array<array-key, mixed>  $release  The release as GitHub's API describes it.
     * @return array{id: string, slug: string, version: string, url: string, package: string, requires_php?: string}|null
     */
    public static function offer(array $release, string $installed): ?array
    {
        $tag = is_string($release['tag_name'] ?? null) ? $release['tag_name'] : '';

        if (preg_match('/^v(\d+\.\d+\.\d+)$/', $tag, $matches) !== 1 || ! version_compare($matches[1], $installed, '>')) {
            return null;
        }

        $zip = 'velirapay-woocommerce-'.$matches[1].'.zip';
        $package = self::DOWNLOADS.$tag.'/'.$zip;

        foreach (is_array($release['assets'] ?? null) ? $release['assets'] : [] as $asset) {
            if (is_array($asset) && ($asset['name'] ?? null) === $zip && ($asset['browser_download_url'] ?? null) === $package) {
                return [
                    'id' => self::UPDATE_URI,
                    'slug' => 'velirapay-woocommerce',
                    'version' => $matches[1],
                    'url' => self::UPDATE_URI.'/releases/tag/'.$tag,
                    'package' => $package,
                ];
            }
        }

        return null;
    }

    /**
     * Get the plugin's latest release from GitHub, remembered for twelve hours, a failure to read it as well, or an
     * empty array when it could not be read. The request is short, follows no redirect and names only the plugin.
     *
     * @return array<array-key, mixed>
     */
    private function latestRelease(): array
    {
        $cached = get_site_transient(self::CACHE);

        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get(self::LATEST_RELEASE_URL, [
            'timeout' => self::TIMEOUT,
            'redirection' => 0,
            'user-agent' => Plugin::userAgent(),
            'headers' => ['Accept' => 'application/vnd.github+json'],
        ]);

        $release = [];

        if (! is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200) {
            $decoded = json_decode(wp_remote_retrieve_body($response), true);
            $release = is_array($decoded) ? self::essentials($decoded) : [];
        }

        set_site_transient(self::CACHE, $release, self::CACHE_SECONDS);

        return $release;
    }

    /**
     * Keep what an update needs of a release: its tag and its files' names and download addresses.
     *
     * @param  array<array-key, mixed>  $release
     * @return array{tag_name: mixed, assets: list<array{name: mixed, browser_download_url: mixed}>}
     */
    private static function essentials(array $release): array
    {
        $assets = [];

        foreach (is_array($release['assets'] ?? null) ? $release['assets'] : [] as $asset) {
            if (is_array($asset)) {
                $assets[] = ['name' => $asset['name'] ?? null, 'browser_download_url' => $asset['browser_download_url'] ?? null];
            }
        }

        return ['tag_name' => $release['tag_name'] ?? null, 'assets' => $assets];
    }
}
