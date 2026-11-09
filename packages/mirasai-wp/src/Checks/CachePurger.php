<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Checks;

/**
 * Empties the caches a post-update check would otherwise read from, with each plugin's own entry point.
 * Only the caches found on the sites we look after are known here: a page cache drop-in that nothing
 * here can empty is reported as unsupported, so the check says its pages may be stale.
 */
final class CachePurger
{
    /**
     * What purge() would empty, without emptying anything.
     *
     * @return array<string, mixed>
     */
    public static function detect(): array
    {
        $found = array_keys(array_filter(self::known(), static fn (array $cache): bool => $cache['available']()));

        return [
            'version' => 1,
            'page_caches' => $found,
            'unsupported_drop_in' => $found === [] && self::hasDropIn(),
            'object_cache' => (bool) wp_using_ext_object_cache(),
        ];
    }

    /** @return array<string, mixed> */
    public static function purge(): array
    {
        $purged = [];
        $errors = 0;

        foreach (self::known() as $name => $cache) {
            try {
                if ($cache['available']()) {
                    $cache['purge']();
                    $purged[] = $name;
                }
            } catch (\Throwable) {
                $errors++;
            }
        }

        $page = self::pageCacheState($purged, $errors, self::hasDropIn());
        $object = 'none';

        if (wp_using_ext_object_cache()) {
            $object = wp_cache_flush() ? 'purged' : 'error';
        }

        return [
            'version' => 1,
            'state' => $page === 'unsupported' || $page === 'error' || $object === 'error' ? 'unknown' : 'pass',
            'page' => $page,
            'purged' => $purged,
            'object' => $object,
        ];
    }

    /** @return array<string, array{available: callable(): bool, purge: callable(): void}> */
    private static function known(): array
    {
        return [
            'wp_rocket' => [
                'available' => static fn (): bool => function_exists('rocket_clean_domain'),
                'purge' => static function (): void {
                    // Same three steps as WP Rocket's own «Clear cache» button.
                    rocket_clean_domain();

                    if (function_exists('rocket_clean_minify')) {
                        rocket_clean_minify();
                    }

                    if (function_exists('rocket_clean_cache_busting')) {
                        rocket_clean_cache_busting();
                    }
                },
            ],
            'ionos_performance' => [
                'available' => static fn (): bool => is_callable(['\\Ionos\\Performance\\Caching\\Caching', 'flush_total_cache']),
                'purge' => static function (): void {
                    \Ionos\Performance\Caching\Caching::flush_total_cache();
                },
            ],
        ];
    }

    /** @param list<string> $purged */
    public static function pageCacheState(array $purged, int $errors, bool $dropIn): string
    {
        if ($errors > 0) {
            return 'error';
        }

        if ($purged !== []) {
            return 'purged';
        }

        return $dropIn ? 'unsupported' : 'none';
    }

    /** An empty advanced-cache.php is what cache plugins leave behind when they are switched off. */
    private static function hasDropIn(): bool
    {
        $path = WP_CONTENT_DIR . '/advanced-cache.php';

        return defined('WP_CACHE') && WP_CACHE && @is_file($path) && (int) @filesize($path) > 0;
    }
}
