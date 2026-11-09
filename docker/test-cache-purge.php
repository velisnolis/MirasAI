<?php
/**
 * Test: site/cache-purge on both hosts, with WordPress cache plugins stubbed.
 *
 * Run from the repo root:
 *   php docker/test-cache-purge.php
 */

declare(strict_types=1);

namespace {
    $root = dirname(__DIR__);

    require_once $root . '/packages/mirasai-wp/src/Tool/ToolInterface.php';
    require_once $root . '/packages/mirasai-wp/src/Tool/AbstractTool.php';
    require_once $root . '/packages/mirasai-wp/src/Checks/CachePurger.php';
    require_once $root . '/packages/mirasai-wp/src/Tool/SiteCachePurgeTool.php';
    require_once $root . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/ToolInterface.php';
    require_once $root . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/AbstractTool.php';
    require_once $root . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/SiteCachePurgeTool.php';

    $content = sys_get_temp_dir() . '/mirasai-cache-purge-' . bin2hex(random_bytes(4));
    mkdir($content, 0700, true);
    define('WP_CONTENT_DIR', $content);
    define('WP_CACHE', true);

    $calls = [];
    $extObjectCache = false;

    function wp_using_ext_object_cache(): bool
    {
        return $GLOBALS['extObjectCache'];
    }

    function wp_cache_flush(): bool
    {
        $GLOBALS['calls'][] = 'wp_cache_flush';

        return true;
    }

    $fails = 0;
    $count = 0;
    function check(bool $ok, string $name): void
    {
        global $fails, $count;
        $count++;
        if (!$ok) {
            $fails++;
            echo "FAIL: $name\n";
        }
    }

    $tool = new \Mirasai\WordPress\Tool\SiteCachePurgeTool();
    check($tool->getName() === 'site/cache-purge', 'wordpress: tool name');
    $mcp = $tool->toMcpTool();
    check($mcp['metadata']['risk_level'] === 'safe_write' && $mcp['annotations']['readOnlyHint'] === false, 'wordpress: safe_write');
    check($mcp['annotations']['destructiveHint'] === false, 'wordpress: not destructive');

    // No cache plugin, no drop-in.
    $r = $tool->handle(['dry_run' => true]);
    check($r === ['dry_run' => true, 'version' => 1, 'page_caches' => [], 'unsupported_drop_in' => false, 'object_cache' => false], 'wordpress: nothing to purge');
    $r = $tool->handle([]);
    check($r['state'] === 'pass' && $r['page'] === 'none' && $r['purged'] === [] && $calls === [], 'wordpress: purge with nothing is a pass and calls nothing');

    // An unknown drop-in is reported, not hidden.
    file_put_contents($content . '/advanced-cache.php', '<?php // some cache');
    $r = $tool->handle(['dry_run' => true]);
    check($r['unsupported_drop_in'] === true, 'wordpress: unknown drop-in seen in dry run');
    $r = $tool->handle([]);
    check($r['state'] === 'unknown' && $r['page'] === 'unsupported', 'wordpress: unknown drop-in makes state unknown');

    // WP Rocket present, object cache present. Declared conditionally so PHP does
    // not hoist them to the top of the file.
    if (!function_exists('rocket_clean_domain')) {
        function rocket_clean_domain(): void
        {
            $GLOBALS['calls'][] = 'rocket_clean_domain';
        }
        function rocket_clean_minify(): void
        {
            $GLOBALS['calls'][] = 'rocket_clean_minify';
        }
    }
    $extObjectCache = true;

    $r = $tool->handle(['dry_run' => true]);
    check($r['page_caches'] === ['wp_rocket'] && $r['unsupported_drop_in'] === false && $r['object_cache'] === true, 'wordpress: dry run lists WP Rocket and object cache');
    check($calls === [], 'wordpress: dry run empties nothing');

    $r = $tool->handle(['dry_run' => false]);
    check($r['state'] === 'pass' && $r['page'] === 'purged' && $r['purged'] === ['wp_rocket'] && $r['object'] === 'purged', 'wordpress: WP Rocket and object cache purged');
    check($calls === ['rocket_clean_domain', 'rocket_clean_minify', 'wp_cache_flush'], 'wordpress: WP Rocket purged like its own button, then object cache');

    // Joomla: the parts that run without Joomla.
    $J = \Mirasai\Library\Tool\SiteCachePurgeTool::class;
    check($J::pageState(false, false, true) === 'purged', 'joomla: core page cache purged');
    check($J::pageState(false, false, false) === 'none', 'joomla: no page cache');
    check($J::pageState(false, true, true) === 'unsupported', 'joomla: other page cache is not hidden');
    check($J::pageState(true, true, true) === 'error', 'joomla: an error wins');

    $joomla = (new \ReflectionClass($J))->newInstanceWithoutConstructor();
    check($joomla->getName() === 'site/cache-purge', 'joomla: tool name');
    $mcp = $joomla->toMcpTool();
    check(($mcp['metadata']['risk_level'] ?? null) === 'safe_write' && ($mcp['annotations']['readOnlyHint'] ?? null) === false, 'joomla: safe_write');
    check(array_keys((array) $mcp['inputSchema']['properties']) === ['dry_run'], 'joomla: only dry_run');

    @unlink($content . '/advanced-cache.php');
    @rmdir($content);

    echo $fails === 0 ? "OK: $count checks\n" : "$fails of $count checks failed\n";
    exit($fails === 0 ? 0 : 1);
}
