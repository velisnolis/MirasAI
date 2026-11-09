<?php
/**
 * Test: what goes into the activity log for an MCP write, on both hosts.
 * Writing to Simple History and the User Actions Log is checked on real sites
 * (docs/wootsup-comparison-proposal-2026-11-09.md).
 *
 * Run from the repo root:
 *   php docker/test-action-log.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

require_once $root . '/packages/mirasai-wp/src/Mcp/McpActionRecord.php';
require_once $root . '/packages/mirasai-wp/src/Mcp/PreviewToken.php';
require_once $root . '/packages/mirasai-joomla/packages/lib_mirasai/src/Mcp/McpActionRecord.php';
require_once $root . '/packages/mirasai-joomla/packages/lib_mirasai/src/Mcp/PreviewToken.php';

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

$router = ['x-mcp-client' => 'claude-code/2.1.0 via @miras/mirasai-mcp', 'user-agent' => 'node'];

foreach (['wordpress' => \Mirasai\WordPress\Mcp\McpActionRecord::class, 'joomla' => \Mirasai\Library\Mcp\McpActionRecord::class] as $host => $R) {
    $write = $R::build('template/element-update-props', 'guarded_write', [
        'key' => 'abc123', 'path' => 'root>section[0]>headline[0]', 'props' => ['content' => 'Secret draft text'],
        'if_match' => 'e1', 'dry_run' => false, 'confirm_guarded_write' => true,
    ], ['action' => 'updated', 'old_etag' => str_repeat('a', 64), 'new_etag' => str_repeat('b', 64)], $router);

    check($write === [
        'tool' => 'template/element-update-props',
        'risk_level' => 'guarded_write',
        'target' => 'key=abc123, path=root>section[0]>headline[0]',
        'client' => 'claude-code/2.1.0 via @miras/mirasai-mcp',
        'old_etag' => 'aaaaaaaaaaaa',
        'new_etag' => 'bbbbbbbbbbbb',
    ], "$host: a write is recorded with target, client and short etags");
    check(!str_contains((string) json_encode($write), 'Secret draft'), "$host: argument values beyond the target stay out");

    check($R::build('template/read', 'read', ['key' => 'a'], ['layout' => []], $router) === null, "$host: reads are not logged");
    check($R::build('template/element-add', 'guarded_write', ['key' => 'a', 'dry_run' => true], ['action' => 'preview'], $router) === null, "$host: dry runs are not logged");
    check($R::build('content/translate', 'guarded_write', ['post_id' => 3], ['dry_run' => true], $router) === null, "$host: a dry run reported by the tool is not logged");
    check($R::build('template/element-add', 'guarded_write', ['key' => 'a'], ['action' => 'preview'], $router) === null, "$host: previews are not logged");
    check($R::build('template/element-add', 'guarded_write', ['key' => 'a', 'confirm_guarded_write' => true], ['error' => 'x', 'code' => 'stale_etag'], $router) === null, "$host: errors are not logged");

    $create = $R::build('template/create', 'guarded_write', ['name' => 'X', 'type' => 'single-post', 'if_match' => 'c'], ['action' => 'created', 'key' => 'new1', 'etag' => 'e2e2e2e2e2e2e2e2'], ['user-agent' => 'Claude-User/1.0']);
    check($create['target'] === '-' && $create['old_etag'] === '' && $create['new_etag'] === 'e2e2e2e2e2e2', "$host: a create has no old etag");
    check($create['client'] === 'Claude-User/1.0', "$host: User-Agent when the router header is missing");

    $purge = $R::build('site/cache-purge', 'safe_write', [], ['dry_run' => false, 'state' => 'pass'], []);
    check($purge['client'] === 'unknown' && $purge['target'] === '-' && $purge['new_etag'] === '', "$host: a safe write without target or etag is still recorded");

    $odd = $R::build('content/translate', 'guarded_write', ['post_id' => 7, 'url' => "https://x\r\ny"], ['action' => 'updated'], ['x-mcp-client' => str_repeat('z', 300) . "\n"]);
    check(!preg_match('/[\r\n]/', $odd['target'] . $odd['client']), "$host: control characters removed");
    check(mb_strlen($odd['client']) === 121, "$host: long client name clipped");
    check(str_starts_with($odd['target'], 'post_id=7'), "$host: integer targets kept");
}

$wp = file_get_contents($root . '/packages/mirasai-wp/src/Mcp/McpActionRecord.php');
$joomla = file_get_contents($root . '/packages/mirasai-joomla/packages/lib_mirasai/src/Mcp/McpActionRecord.php');
$strip = static fn (string $s): string => preg_replace(['/^namespace .*$/m', '/^ \* Mirrored in .*$/m'], '', $s);
check($strip($wp) === $strip($joomla), 'record copies are identical apart from namespace and mirror note');

echo $fails === 0 ? "OK: $count checks\n" : "$fails of $count checks failed\n";
exit($fails === 0 ? 0 : 1);
