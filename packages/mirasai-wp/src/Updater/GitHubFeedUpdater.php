<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Updater;

class GitHubFeedUpdater
{
    private const SLUG = 'mirasai';
    private const FEED_CACHE_KEY = 'mirasai_wp_update_feed';
    private const FEED_CACHE_TTL = HOUR_IN_SECONDS;
    private const DOWNLOAD_TIMEOUT = 300;

    public static function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'checkForUpdates']);
        add_filter('plugins_api', [self::class, 'pluginInformation'], 10, 3);
        add_filter('upgrader_pre_download', [self::class, 'verifyPackageDownload'], 10, 4);
        add_action('upgrader_process_complete', [self::class, 'clearCache']);
        // Anything that throws away the update_plugins transient (wp-admin, WP-CLI
        // `wp transient delete update_plugins --network`, wp_clean_plugins_cache())
        // wants a fresh check, so the feed cache must not outlive it.
        add_action('delete_site_transient_update_plugins', [self::class, 'clearCache']);
        // "Check again" on Dashboard > Updates. Runs before core's wp_update_plugins (priority 10).
        add_action('load-update-core.php', [self::class, 'clearCacheOnForcedCheck'], 1);
    }

    /**
     * @param object|mixed $transient
     * @return object|mixed
     */
    public static function checkForUpdates($transient)
    {
        if (!is_object($transient) || empty($transient->checked) || !isset($transient->checked[self::pluginBasename()])) {
            return $transient;
        }

        $feed = self::fetchFeed();
        if ($feed === null || !isset($feed['version'], $feed['download_url'])) {
            return $transient;
        }

        $version = (string) $feed['version'];
        if (!version_compare($version, MIRASAI_WP_VERSION, '>')) {
            $transient->no_update[self::pluginBasename()] = self::updatePayload($feed);
            return $transient;
        }

        $transient->response[self::pluginBasename()] = self::updatePayload($feed);

        return $transient;
    }

    /**
     * @param mixed $result
     * @param mixed $args
     * @return mixed
     */
    public static function pluginInformation($result, string $action, $args)
    {
        if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? '') !== self::SLUG) {
            return $result;
        }

        $feed = self::fetchFeed();
        if ($feed === null) {
            return $result;
        }

        $sections = is_array($feed['sections'] ?? null) ? $feed['sections'] : [];

        return (object) [
            'name' => (string) ($feed['name'] ?? 'MirasAI'),
            'slug' => self::SLUG,
            'version' => (string) ($feed['version'] ?? MIRASAI_WP_VERSION),
            'author' => '<a href="https://miras.pro">Miras</a>',
            'homepage' => (string) ($feed['homepage'] ?? 'https://github.com/velisnolis/MirasAI'),
            'requires' => (string) ($feed['requires'] ?? '6.0'),
            'tested' => (string) ($feed['tested'] ?? ''),
            'requires_php' => (string) ($feed['requires_php'] ?? '8.0'),
            'last_updated' => (string) ($feed['last_updated'] ?? ''),
            'sections' => [
                'description' => (string) ($sections['description'] ?? 'MirasAI host endpoint for WordPress.'),
                'installation' => (string) ($sections['installation'] ?? 'Install the release ZIP from GitHub, then create a WordPress Application Password from the MirasAI dashboard.'),
                'changelog' => (string) ($sections['changelog'] ?? ''),
            ],
            'download_link' => (string) ($feed['download_url'] ?? ''),
        ];
    }

    public static function clearCache(): void
    {
        delete_site_transient(self::FEED_CACHE_KEY);
    }

    public static function clearCacheOnForcedCheck(): void
    {
        if (!empty($_GET['force-check'])) {
            self::clearCache();
        }
    }

    /**
     * Downloads this plugin's release ZIP and refuses it unless it matches the
     * sha256 published in the update feed. Other packages are left untouched.
     *
     * @param mixed $reply
     * @param mixed $package
     * @param mixed $upgrader
     * @param mixed $hookExtra
     * @return mixed
     */
    public static function verifyPackageDownload($reply, $package, $upgrader = null, $hookExtra = [])
    {
        if ($reply !== false || !is_string($package) || !preg_match('#^https?://#i', $package)) {
            return $reply;
        }

        $expected = self::expectedChecksum($package);
        $isOwnPlugin = is_array($hookExtra) && ($hookExtra['plugin'] ?? null) === self::pluginBasename();

        if ($expected === null && !$isOwnPlugin) {
            return $reply;
        }

        if ($expected === null || preg_match('/^[a-f0-9]{64}$/', $expected) !== 1) {
            return new \WP_Error(
                'mirasai_package_checksum_missing',
                'MirasAI update refused: the update feed has no valid sha256 for this package.'
            );
        }

        if (is_object($upgrader) && isset($upgrader->skin) && is_object($upgrader->skin) && method_exists($upgrader->skin, 'feedback')) {
            $upgrader->skin->feedback('downloading_package', $package);
        }

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $file = download_url($package, self::DOWNLOAD_TIMEOUT);
        if (is_wp_error($file)) {
            return $file;
        }

        $actual = hash_file('sha256', $file);
        if (!is_string($actual) || !hash_equals($expected, strtolower($actual))) {
            @unlink($file);

            return new \WP_Error(
                'mirasai_package_checksum_mismatch',
                sprintf(
                    'MirasAI update refused: package sha256 %s does not match the update feed (%s).',
                    is_string($actual) ? $actual : 'unavailable',
                    $expected
                )
            );
        }

        return $file;
    }

    /**
     * Returns null when the package is not one of ours, and '' when it is ours
     * but no checksum was published. The feed is checked first; the
     * update_plugins entry covers an offer built from an older feed that has
     * since moved on to a newer release.
     */
    private static function expectedChecksum(string $package): ?string
    {
        $feed = self::fetchFeed();
        if ($feed !== null && (string) ($feed['download_url'] ?? '') === $package) {
            return strtolower(trim((string) ($feed['sha256'] ?? '')));
        }

        $updates = get_site_transient('update_plugins');
        $offer = is_object($updates) && isset($updates->response) && is_array($updates->response)
            ? ($updates->response[self::pluginBasename()] ?? null)
            : null;
        if (is_object($offer) && ($offer->package ?? '') === $package) {
            return strtolower(trim((string) ($offer->sha256 ?? '')));
        }

        return null;
    }

    /**
     * @param array<string, mixed> $feed
     */
    private static function updatePayload(array $feed): object
    {
        return (object) [
            'slug' => self::SLUG,
            'plugin' => self::pluginBasename(),
            'new_version' => (string) ($feed['version'] ?? MIRASAI_WP_VERSION),
            'url' => (string) ($feed['homepage'] ?? 'https://github.com/velisnolis/MirasAI'),
            'package' => (string) ($feed['download_url'] ?? ''),
            'sha256' => (string) ($feed['sha256'] ?? ''),
            'requires' => (string) ($feed['requires'] ?? '6.0'),
            'tested' => (string) ($feed['tested'] ?? ''),
            'requires_php' => (string) ($feed['requires_php'] ?? '8.0'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function fetchFeed(): ?array
    {
        $cached = get_site_transient(self::FEED_CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get(MIRASAI_WP_UPDATE_FEED_URL, [
            'headers' => ['Accept' => 'application/json'],
            'timeout' => 8,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return null;
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($decoded) || !isset($decoded['version'], $decoded['download_url'])) {
            return null;
        }

        set_site_transient(self::FEED_CACHE_KEY, $decoded, self::FEED_CACHE_TTL);

        return $decoded;
    }

    private static function pluginBasename(): string
    {
        return plugin_basename(MIRASAI_WP_PLUGIN_FILE);
    }
}
