<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Updater;

class GitHubFeedUpdater
{
    private const SLUG = 'mirasai';
    private const FEED_CACHE_KEY = 'mirasai_wp_update_feed';
    private const FEED_CACHE_TTL = 6 * HOUR_IN_SECONDS;

    public static function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'checkForUpdates']);
        add_filter('plugins_api', [self::class, 'pluginInformation'], 10, 3);
        add_action('upgrader_process_complete', [self::class, 'clearCache']);
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
