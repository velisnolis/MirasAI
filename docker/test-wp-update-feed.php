<?php
/**
 * Test: GitHubFeedUpdater refreshes the feed on forced checks and refuses
 * release packages whose sha256 does not match the feed.
 *
 * Run from the repo root:
 *   php docker/test-wp-update-feed.php
 */

declare(strict_types=1);

const HOUR_IN_SECONDS = 3600;
const ABSPATH = '/tmp/wordpress/';
const MIRASAI_WP_VERSION = '0.10.0';
const MIRASAI_WP_PLUGIN_FILE = '/var/www/wp-content/plugins/mirasai-wp/mirasai-wp.php';
const MIRASAI_WP_UPDATE_FEED_URL = 'https://raw.githubusercontent.com/velisnolis/MirasAI/main/updates/mirasai-wp.json';

const TEST_BASENAME = 'mirasai-wp/mirasai-wp.php';

$GLOBALS['test_hooks'] = [];
$GLOBALS['test_site_transients'] = [];
$GLOBALS['test_transient_ttls'] = [];
$GLOBALS['test_remote_feed'] = [];
$GLOBALS['test_remote_calls'] = 0;
$GLOBALS['test_download_body'] = '';
$GLOBALS['test_download_calls'] = 0;
$GLOBALS['test_downloaded_files'] = [];

class WP_Error
{
    public function __construct(public string $code = '', public string $message = '')
    {
    }

    public function get_error_code(): string
    {
        return $this->code;
    }
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
{
    $GLOBALS['test_hooks'][$hook][$priority][] = [$callback, $acceptedArgs];
    ksort($GLOBALS['test_hooks'][$hook]);

    return true;
}

function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
{
    return add_filter($hook, $callback, $priority, $acceptedArgs);
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    foreach ($GLOBALS['test_hooks'][$hook] ?? [] as $callbacks) {
        foreach ($callbacks as [$callback, $acceptedArgs]) {
            $value = $callback(...array_slice([$value, ...$args], 0, $acceptedArgs));
        }
    }

    return $value;
}

function do_action(string $hook, mixed ...$args): void
{
    foreach ($GLOBALS['test_hooks'][$hook] ?? [] as $callbacks) {
        foreach ($callbacks as [$callback, $acceptedArgs]) {
            $callback(...array_slice($args, 0, $acceptedArgs));
        }
    }
}

function get_site_transient(string $key): mixed
{
    return $GLOBALS['test_site_transients'][$key] ?? false;
}

function set_site_transient(string $key, mixed $value, int $expiration = 0): bool
{
    // Mirrors core: pre_set_site_transient_{$transient} runs before the value is stored.
    $value = apply_filters("pre_set_site_transient_{$key}", $value, $expiration, $key);
    $GLOBALS['test_site_transients'][$key] = $value;
    $GLOBALS['test_transient_ttls'][$key] = $expiration;

    return true;
}

function delete_site_transient(string $key): bool
{
    // Mirrors core: delete_site_transient_{$transient} fires before deletion.
    do_action("delete_site_transient_{$key}", $key);
    unset($GLOBALS['test_site_transients'][$key]);

    return true;
}

function wp_remote_get(string $url, array $args = []): array
{
    $GLOBALS['test_remote_calls']++;

    return ['code' => 200, 'body' => json_encode($GLOBALS['test_remote_feed'])];
}

function wp_remote_retrieve_response_code(array $response): int
{
    return $response['code'];
}

function wp_remote_retrieve_body(array $response): string
{
    return $response['body'];
}

function is_wp_error(mixed $value): bool
{
    return $value instanceof WP_Error;
}

function plugin_basename(string $file): string
{
    return TEST_BASENAME;
}

function download_url(string $url, int $timeout = 300): string|WP_Error
{
    $GLOBALS['test_download_calls']++;
    $file = tempnam(sys_get_temp_dir(), 'mirasai-test-');
    file_put_contents($file, $GLOBALS['test_download_body']);
    $GLOBALS['test_downloaded_files'][] = $file;

    return $file;
}

require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Updater/GitHubFeedUpdater.php';

use Mirasai\WordPress\Updater\GitHubFeedUpdater;

$failures = 0;

function check(bool $condition, string $label): void
{
    global $failures;
    if ($condition) {
        echo "[PASS] {$label}\n";
        return;
    }

    $failures++;
    fwrite(STDERR, "[FAIL] {$label}\n");
}

function feed(string $version, ?string $sha256 = null): array
{
    $feed = [
        'version' => $version,
        'download_url' => "https://github.com/velisnolis/MirasAI/releases/download/v{$version}/mirasai-wp-{$version}.zip",
    ];
    if ($sha256 !== null) {
        $feed['sha256'] = $sha256;
    }

    return $feed;
}

/** Equivalent of wp_update_plugins() persisting a fresh check. */
function run_update_check(): object
{
    $transient = new stdClass();
    $transient->last_checked = time();
    $transient->checked = [TEST_BASENAME => MIRASAI_WP_VERSION];
    $transient->response = [];
    $transient->no_update = [];
    set_site_transient('update_plugins', $transient);

    return get_site_transient('update_plugins');
}

function offered_version(object $transient): ?string
{
    return $transient->response[TEST_BASENAME]->new_version ?? null;
}

function pre_download(string $package, array $hookExtra = []): mixed
{
    return apply_filters('upgrader_pre_download', false, $package, null, $hookExtra);
}

GitHubFeedUpdater::register();

// --- Feed cache -------------------------------------------------------------

$GLOBALS['test_remote_feed'] = feed('0.10.0', str_repeat('a', 64));
$first = run_update_check();
check(offered_version($first) === null && isset($first->no_update[TEST_BASENAME]), 'same version is reported as no_update');
check($GLOBALS['test_remote_calls'] === 1, 'first check fetches the feed');
check(($GLOBALS['test_transient_ttls']['mirasai_wp_update_feed'] ?? null) === HOUR_IN_SECONDS, 'feed cache TTL is one hour');

$GLOBALS['test_remote_feed'] = feed('0.10.1', str_repeat('b', 64));
$cached = run_update_check();
check(offered_version($cached) === null && $GLOBALS['test_remote_calls'] === 1, 'unforced check reuses the cached feed');

// WP-CLI: `wp transient delete update_plugins --network` then `wp plugin update`.
delete_site_transient('update_plugins');
$afterDelete = run_update_check();
check(offered_version($afterDelete) === '0.10.1', 'deleting update_plugins makes the next check see the new release');
check($GLOBALS['test_remote_calls'] === 2, 'deleting update_plugins refetches the feed once');
check(($afterDelete->response[TEST_BASENAME]->sha256 ?? null) === str_repeat('b', 64), 'update offer carries the feed sha256');

// wp-admin: Dashboard > Updates without "Check again".
$GLOBALS['test_remote_feed'] = feed('0.10.2', str_repeat('c', 64));
$_GET = [];
do_action('load-update-core.php');
check(offered_version(run_update_check()) === '0.10.1', 'plain update-core.php load keeps the cached feed');

// wp-admin: "Check again" (update-core.php?force-check=1).
$_GET = ['force-check' => '1'];
do_action('load-update-core.php');
$forced = run_update_check();
check(offered_version($forced) === '0.10.2', 'force-check on update-core.php bypasses the feed cache');
check($GLOBALS['test_remote_calls'] === 3, 'force-check fetches the feed exactly once more');
$_GET = [];

// --- Package checksum -------------------------------------------------------

$release = 'PK fake mirasai-wp release zip';
$GLOBALS['test_download_body'] = $release;
$GLOBALS['test_remote_feed'] = feed('0.10.3', hash('sha256', $release));
delete_site_transient('update_plugins');
run_update_check();
$package = feed('0.10.3')['download_url'];

$ok = pre_download($package, ['plugin' => TEST_BASENAME, 'type' => 'plugin', 'action' => 'update']);
check(is_string($ok) && is_file($ok) && file_get_contents($ok) === $release, 'matching sha256 hands the verified file to the upgrader');

$okInstall = pre_download($package, ['type' => 'plugin', 'action' => 'install']);
check(is_string($okInstall) && is_file($okInstall), 'feed package is verified even without hook_extra plugin (Install Now)');

$GLOBALS['test_download_body'] = 'PK tampered zip';
$downloadsBefore = count($GLOBALS['test_downloaded_files']);
$mismatch = pre_download($package, ['plugin' => TEST_BASENAME]);
check($mismatch instanceof WP_Error && $mismatch->get_error_code() === 'mirasai_package_checksum_mismatch', 'sha256 mismatch is rejected with a WP_Error');
$tampered = $GLOBALS['test_downloaded_files'][$downloadsBefore] ?? null;
check($tampered !== null && !file_exists($tampered), 'rejected download is deleted');

$callsBefore = $GLOBALS['test_download_calls'];
$foreign = pre_download('https://downloads.wordpress.org/plugin/akismet.5.3.zip', ['plugin' => 'akismet/akismet.php']);
check($foreign === false && $GLOBALS['test_download_calls'] === $callsBefore, 'other plugins\' packages are left untouched');

$local = pre_download('/tmp/uploaded-plugin.zip', ['type' => 'plugin', 'action' => 'install']);
check($local === false && $GLOBALS['test_download_calls'] === $callsBefore, 'local ZIP uploads are left untouched');

$handled = apply_filters('upgrader_pre_download', '/tmp/already-downloaded.zip', $package, null, ['plugin' => TEST_BASENAME]);
check($handled === '/tmp/already-downloaded.zip', 'a reply from an earlier filter is preserved');

// Offer built from an older feed; the feed has since moved on to a newer release.
$GLOBALS['test_download_body'] = $release;
$GLOBALS['test_remote_feed'] = feed('0.10.4', str_repeat('d', 64));
delete_site_transient('mirasai_wp_update_feed');
$fromOffer = pre_download($package, ['plugin' => TEST_BASENAME]);
check(is_string($fromOffer) && is_file($fromOffer), 'package offered by update_plugins is verified with the offer sha256');

// Own plugin but no usable checksum: fail closed.
$GLOBALS['test_remote_feed'] = feed('0.10.5');
delete_site_transient('update_plugins');
run_update_check();
$missing = pre_download(feed('0.10.5')['download_url'], ['plugin' => TEST_BASENAME]);
check($missing instanceof WP_Error && $missing->get_error_code() === 'mirasai_package_checksum_missing', 'own package without feed sha256 is refused');

$missingInstall = pre_download(feed('0.10.5')['download_url'], ['type' => 'plugin', 'action' => 'install']);
check($missingInstall instanceof WP_Error, 'feed package without sha256 is refused on install too');

foreach ($GLOBALS['test_downloaded_files'] as $file) {
    @unlink($file);
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} update feed check(s) failed.\n");
    exit(1);
}

echo "GitHubFeedUpdater checks passed.\n";
