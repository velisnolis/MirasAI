<?php
/**
 * Test: YOOtheme Style read tools.
 *
 * Covers the three facts that make this layer easy to get wrong:
 *   - Style config lives in theme_mods_yootheme.config, not the yootheme option.
 *   - That JSON carries yootheme_apikey, which must never leave the host.
 *   - Compiled CSS can silently fall behind its Less sources, because YOOtheme
 *     compiles in the browser and the server only stores the result.
 *
 * Run from the repo root:
 *   php docker/test-wp-yootheme-style-read.php
 */

declare(strict_types=1);

$failures = 0;
function check(string $label, bool $ok): void
{
    global $failures;
    if ($ok) {
        echo "[PASS] {$label}\n";
        return;
    }
    $failures++;
    fwrite(STDERR, "[FAIL] {$label}\n");
}

// ---------------------------------------------------------------- fixture ---

$root = sys_get_temp_dir() . '/mirasai-style-test-' . bin2hex(random_bytes(4));
$themeDir = $root . '/wp-content/themes/yootheme';
$childDir = $root . '/wp-content/themes/yootheme-child';

mkdir($themeDir . '/less', 0777, true);
mkdir($themeDir . '/css', 0777, true);
mkdir($themeDir . '/fonts', 0777, true);
mkdir($themeDir . '/vendor/assets', 0777, true);

file_put_contents($themeDir . '/less/theme.flow.less', <<<LESS
/*

Name: Flow
Background: White
Color: Purple
Type: Material

Style: white-pink
Name: White Pink
Background: White
Color: Pink

Style: white-blue
Name: White Blue
Background: White
Color: Blue

*/

@import "../vendor/assets/uikit/src/less/uikit.less";
@import (optional) "../vendor/assets/uikit-themes/master-flow/styles/@{internal-style}.less";
@internal-style: ~'';
LESS);

file_put_contents($themeDir . '/less/theme.fuse.less', "/*\n\nName: Fuse\n\n*/\n");
file_put_contents($themeDir . '/fonts/varelaround-1f86b7a1.woff2', 'x');
file_put_contents($themeDir . '/fonts/varelaround-9c2a71bb.woff2', 'x');
file_put_contents($themeDir . '/fonts/montserrat-126d7ad9.woff2', 'x');

file_put_contents(
    $themeDir . '/css/theme.1.css',
    "/* YOOtheme Pro v5.0.30 compiled on 2026-07-11T14:00:06+00:00 */\n.uk-x{color:red}"
);

// The Less is newer than the compiled CSS: the silent-staleness case. This is
// what happens in the wild when a plugin contributing Less updates itself and
// nobody reopens the customizer.
touch($themeDir . '/css/theme.1.css', 1_752_000_000);
touch($themeDir . '/less/theme.flow.less', 1_752_000_000 + 100);
touch($themeDir . '/less/theme.fuse.less', 1_752_000_000 + 100);

$GLOBALS['test_apikey'] = str_repeat('a', 40);
$GLOBALS['test_config'] = [
    'style' => 'flow:white-pink',
    'less' => ['@global-primary-background' => '#e85039'],
    'custom_less' => '.iv-point { border-end-end-radius: 50cqh; }',
    'version' => '5.0.37',
    'yootheme_apikey' => $GLOBALS['test_apikey'],
];
$GLOBALS['test_stylesheet'] = 'yootheme';

// ------------------------------------------------------------- WP stubs ----

define('ABSPATH', $root . '/');

function get_template(): string { return 'yootheme'; }
function get_stylesheet(): string { return $GLOBALS['test_stylesheet']; }
function get_template_directory(): string { return ABSPATH . 'wp-content/themes/yootheme'; }
function get_stylesheet_directory(): string { return ABSPATH . 'wp-content/themes/' . $GLOBALS['test_stylesheet']; }
function wp_json_encode(mixed $v, int $flags = 0): string|false { return json_encode($v, $flags); }
function get_theme_mod(string $key, mixed $default = null): mixed
{
    return $key === 'config' ? (string) json_encode($GLOBALS['test_config']) : $default;
}
function get_option(string $key, mixed $default = false): mixed
{
    return $key === 'theme_mods_yootheme'
        ? ['config' => (string) json_encode($GLOBALS['test_config'])]
        : $default;
}
function wp_get_theme(string $slug = ''): object
{
    return new class {
        public function exists(): bool { return true; }
        public function get(string $key): string { return $key === 'Version' ? '5.0.37' : ''; }
    };
}

require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/ToolInterface.php';
require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/AbstractTool.php';
require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/YoothemeStyleHelper.php';
require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/TemplateStyleReadTool.php';
require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/TemplateStyleSourcesTool.php';

use Mirasai\WordPress\Tool\TemplateStyleReadTool;
use Mirasai\WordPress\Tool\TemplateStyleSourcesTool;
use Mirasai\WordPress\Tool\YoothemeStyleHelper;

// ----------------------------------------------------------------- tests ---

$read = (new TemplateStyleReadTool())->handle(['include_custom_less' => true]);
$encoded = (string) json_encode($read);

check('style-read never leaks the YOOtheme API key', !str_contains($encoded, $GLOBALS['test_apikey']));
check('style-read reads from theme_mods_yootheme, not the yootheme option', ($read['storage']['option'] ?? '') === 'theme_mods_yootheme');
check('style-read splits style id from variation', ($read['active']['style_id'] ?? '') === 'flow' && ($read['active']['variation'] ?? '') === 'white-pink');
check('style-read reports the variable overrides', ($read['overrides']['less']['@global-primary-background'] ?? '') === '#e85039');
check('style-read reports custom Less when asked', str_contains((string) ($read['overrides']['custom_less'] ?? ''), 'iv-point'));
check('style-read marks the style as customised', ($read['overrides']['customised'] ?? false) === true);
check('style-read lists both styles found on disk', count($read['available'] ?? []) === 2);

$flow = null;
foreach ($read['available'] ?? [] as $style) {
    if (($style['id'] ?? '') === 'flow') { $flow = $style; }
}
check('style-read parses style metadata', ($flow['name'] ?? '') === 'Flow');
check('style-read parses the declared variations', count($flow['variations'] ?? []) === 2);
check('style-read names a variation', ($flow['variations'][0]['name'] ?? '') === 'White Pink');
check('style-read reports the style source directory', ($flow['source'] ?? '') === 'theme');

check('style-read detects CSS older than its Less sources', ($read['compiled']['stale_sources'] ?? null) === true);
check('style-read detects a CSS compiled by an older YOOtheme', ($read['compiled']['stale_version'] ?? null) === true);
check('style-read warns about the stale CSS', (bool) array_filter($read['warnings'] ?? [], static fn($w) => str_contains($w, 'predates its own Less sources')));
check('style-read warns about the missing child theme', (bool) array_filter($read['warnings'] ?? [], static fn($w) => str_contains($w, 'No child theme')));
check('style-read groups the local font families', ($read['fonts']['families'] ?? []) === ['montserrat', 'varelaround']);
check('style-read reports no child theme', ($read['child_theme']['present'] ?? true) === false);
check('style-read returns an etag', is_string($read['etag'] ?? null) && strlen((string) $read['etag']) === 32);

// custom Less and overrides must be opt-out-able
$lean = (new TemplateStyleReadTool())->handle(['include_overrides' => false]);
check('style-read omits custom Less by default', !array_key_exists('custom_less', $lean['overrides']));
check('style-read can omit the override map', !array_key_exists('less', $lean['overrides']));
check('style-read still reports override counts when omitted', ($lean['overrides']['less_count'] ?? null) === 1);

// a fresh CSS must not be reported as stale
touch($themeDir . '/css/theme.1.css', 1_752_000_500);
$fresh = (new TemplateStyleReadTool())->handle([]);
check('style-read does not cry wolf on fresh CSS', ($fresh['compiled']['stale_sources'] ?? null) === false);

// the etag must move when the stored config moves
$etagBefore = $fresh['etag'];
$GLOBALS['test_config']['less']['@global-primary-background'] = '#c63b28';
$after = (new TemplateStyleReadTool())->handle([]);
check('etag changes when the config changes', $etagBefore !== $after['etag']);

// child theme detection
$GLOBALS['test_stylesheet'] = 'yootheme-child';
mkdir($childDir . '/less', 0777, true);
file_put_contents($childDir . '/less/theme.industria-viva.less', "/*\n\nName: Indústria Viva\n\n*/\n");
$withChild = (new TemplateStyleReadTool())->handle([]);
$ids = array_column($withChild['available'] ?? [], 'source', 'id');
check('a child theme style joins the library', ($ids['industria-viva'] ?? '') === 'child');
check('child theme is reported as present', ($withChild['child_theme']['present'] ?? false) === true);
check('no missing-child-theme warning once one exists', !array_filter($withChild['warnings'] ?? [], static fn($w) => str_contains($w, 'No child theme')));
$GLOBALS['test_stylesheet'] = 'yootheme';

// style-sources must refuse rather than guess when the container is absent
$sources = (new TemplateStyleSourcesTool())->handle([]);
check('style-sources refuses without the YOOtheme container', ($sources['code'] ?? '') === 'container_unavailable');
check('style-sources does not invent an import tree', !isset($sources['imports']));

$bad = (new TemplateStyleSourcesTool())->handle(['style_id' => '../../etc/passwd']);
check('style-sources rejects path traversal in style_id', ($bad['code'] ?? '') === 'invalid_style_id');

$badType = (new TemplateStyleSourcesTool())->handle(['style_id' => 123]);
check('style-sources rejects a non-string style_id', ($badType['code'] ?? '') === 'invalid_style_id');

// redaction is applied to any shape of the key
$helper = new YoothemeStyleHelper();
check('redact() masks a populated key', ($helper->redact(['yootheme_apikey' => 'abc'])['yootheme_apikey'] ?? '') === '__redacted__');
check('redact() leaves an empty key null', $helper->redact(['yootheme_apikey' => ''])['yootheme_apikey'] === null);
check('redact() keeps other keys intact', ($helper->redact(['style' => 'flow'])['style'] ?? '') === 'flow');

// tool metadata
$tools = [new TemplateStyleReadTool(), new TemplateStyleSourcesTool()];
foreach ($tools as $tool) {
    $mcp = $tool->toMcpTool();
    check("{$mcp['name']} is annotated read-only", ($mcp['annotations']['readOnlyHint'] ?? false) === true);
    check("{$mcp['name']} declares read risk", ($mcp['metadata']['risk_level'] ?? '') === 'read');
}

// ---------------------------------------------------------------- cleanup ---

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $item) {
    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
}
@rmdir($root);

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} YOOtheme Style read test(s) failed.\n");
    exit(1);
}

echo "All YOOtheme Style read tests passed.\n";
