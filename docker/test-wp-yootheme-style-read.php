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

$accountRoot = sys_get_temp_dir() . '/mirasai-style-test-' . bin2hex(random_bytes(4));
$root = $accountRoot . '/public_html';
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
$GLOBALS['test_active_theme_mod_config_present'] = true;
$GLOBALS['test_update_option_calls'] = 0;
$GLOBALS['test_clean_themes_cache_calls'] = 0;
$GLOBALS['test_theme_mod_extras'] = ['nav_menu_locations' => ['primary' => 17]];
$GLOBALS['test_force_cas_conflict'] = false;
$GLOBALS['test_force_cas_conflict_on_query'] = null;
$GLOBALS['test_cas_query_calls'] = 0;
$GLOBALS['test_options'] = [];

// ------------------------------------------------------------- WP stubs ----

define('ABSPATH', $root . '/');

function get_template(): string { return 'yootheme'; }
function get_stylesheet(): string { return $GLOBALS['test_stylesheet']; }
function get_template_directory(): string { return ABSPATH . 'wp-content/themes/yootheme'; }
function get_stylesheet_directory(): string { return ABSPATH . 'wp-content/themes/' . $GLOBALS['test_stylesheet']; }
function get_theme_root(): string { return ABSPATH . 'wp-content/themes'; }
function admin_url(string $path = ''): string { return 'https://example.test/wp-admin/' . ltrim($path, '/'); }
function wp_clean_themes_cache(): void { $GLOBALS['test_clean_themes_cache_calls']++; }
function wp_json_encode(mixed $v, int $flags = 0): string|false { return json_encode($v, $flags); }
function get_theme_mod(string $key, mixed $default = null): mixed
{
    if ($key === 'config' && !$GLOBALS['test_active_theme_mod_config_present']) {
        return $default;
    }

    return $key === 'config' ? (string) json_encode($GLOBALS['test_config']) : $default;
}
function get_option(string $key, mixed $default = false): mixed
{
    $stylesheetMods = 'theme_mods_' . $GLOBALS['test_stylesheet'];
    if ($key === $stylesheetMods) {
        if (!$GLOBALS['test_active_theme_mod_config_present']) {
            return $GLOBALS['test_theme_mod_extras'];
        }

        return ['config' => (string) json_encode($GLOBALS['test_config'])] + $GLOBALS['test_theme_mod_extras'];
    }
    if ($key === 'theme_mods_yootheme' && $GLOBALS['test_stylesheet'] !== 'yootheme') {
        return ['config' => (string) json_encode(['style' => 'flow'])];
    }
    if (array_key_exists($key, $GLOBALS['test_options'])) {
        return $GLOBALS['test_options'][$key];
    }
    return $default;
}
function update_option(string $key, mixed $value, mixed $autoload = null): bool
{
    $GLOBALS['test_update_option_calls']++;
    $stylesheetMods = 'theme_mods_' . $GLOBALS['test_stylesheet'];
    if (($key === $stylesheetMods || $key === 'theme_mods_yootheme') && is_array($value) && is_string($value['config'] ?? null)) {
        $decoded = json_decode($value['config'], true);
        if (is_array($decoded) && $key === $stylesheetMods) {
            $GLOBALS['test_config'] = $decoded;
        }
        $extras = $value;
        unset($extras['config']);
        $GLOBALS['test_theme_mod_extras'] = $extras;
    } else {
        $GLOBALS['test_options'][$key] = $value;
    }
    return true;
}
function maybe_serialize(mixed $value): string
{
    return is_array($value) || is_object($value) ? serialize($value) : (string) $value;
}

final class TestStyleWpdb
{
    public string $options = 'wp_options';

    public function prepare(string $query, mixed ...$arguments): array
    {
        return ['query' => $query, 'arguments' => $arguments];
    }

    public function query(mixed $prepared): int
    {
        $GLOBALS['test_cas_query_calls']++;

        if ($GLOBALS['test_force_cas_conflict']
            || $GLOBALS['test_force_cas_conflict_on_query'] === $GLOBALS['test_cas_query_calls']) {
            return 0;
        }

        $arguments = is_array($prepared) ? ($prepared['arguments'] ?? []) : [];
        [$candidate, $optionName, $expected] = $arguments + [null, null, null];

        if (!is_string($optionName)
            || !str_starts_with($optionName, 'theme_mods_')
            || !is_string($candidate)
            || !is_string($expected)
            || maybe_serialize(get_option($optionName, [])) !== $expected) {
            return 0;
        }

        $value = unserialize($candidate, ['allowed_classes' => false]);
        if (!is_array($value)) {
            return 0;
        }

        update_option($optionName, $value);

        return 1;
    }
}

$GLOBALS['wpdb'] = new TestStyleWpdb();
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
require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/TemplateStyleCreateTool.php';
require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/TemplateStyleUpdateTool.php';

use Mirasai\WordPress\Tool\TemplateStyleCreateTool;
use Mirasai\WordPress\Tool\TemplateStyleReadTool;
use Mirasai\WordPress\Tool\TemplateStyleSourcesTool;
use Mirasai\WordPress\Tool\TemplateStyleUpdateTool;
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
check('style-read does not claim config staleness is detectable', ($read['compiled']['stale_config_detectable'] ?? true) === false);
check('style-read reports unknown config freshness without router provenance', ($read['compiled']['config_freshness']['state'] ?? null) === 'unknown');
check('style-read warns that stale_sources ignores config', str_contains((string) ($read['compiled']['freshness_caveat'] ?? ''), 'ignores Style config'));
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

$activeStyle = ['style_id' => 'flow', 'variation' => 'white-pink'];
$activeCompile = $helper->compilationOverrides($GLOBALS['test_config'], $activeStyle, 'flow');
check('active style compilation receives its stored variation', ($activeCompile['internal_style'] ?? null) === 'white-pink');
check('active style compilation receives its stored variables', ($activeCompile['less']['@global-primary-background'] ?? '') === '#c63b28');

$otherCompile = $helper->compilationOverrides($GLOBALS['test_config'], $activeStyle, 'fuse');
check(
    'non-active style compilation starts without the active variation',
    array_key_exists('internal_style', $otherCompile) && $otherCompile['internal_style'] === null
);
check('non-active style compilation starts without active overrides', ($otherCompile['less'] ?? ['unexpected']) === []);
check('non-active style compilation starts without active custom Less', ($otherCompile['custom_less'] ?? 'unexpected') === '');
check('non-active style compilation identifies style defaults as its source', ($otherCompile['source'] ?? '') === 'style_defaults');

$carriedCompile = $helper->compilationOverrides($GLOBALS['test_config'], $activeStyle, 'fuse', true);
check('non-active style can explicitly carry active overrides', ($carriedCompile['source'] ?? '') === 'active_config');
check('explicitly carried overrides retain the active variation', ($carriedCompile['internal_style'] ?? null) === 'white-pink');

$secret = $GLOBALS['test_config']['yootheme_apikey'];
$patched = $helper->patchConfig(
    $GLOBALS['test_config'],
    'flow',
    'white-pink',
    ['@global-primary-background' => '#123456', '@new-token' => '7px'],
    ['@new-token'],
    null,
    false
);
check('style config patch preserves the YOOtheme API key', ($patched['yootheme_apikey'] ?? null) === $secret);
check('omitted custom Less is preserved by the patch', ($patched['custom_less'] ?? null) === $GLOBALS['test_config']['custom_less']);

$beforeDryRunWrites = $GLOBALS['test_update_option_calls'];
$currentRead = (new TemplateStyleReadTool())->handle([]);
$dryRunCss = '.x{color:red}';
$dryRunRtl = '.x{color:red;direction:rtl}';
$dryRun = (new TemplateStyleUpdateTool())->handle([
    'if_match' => $currentRead['etag'],
    'vars' => ['@global-primary-background' => '#123456'],
    'compiled_css' => $dryRunCss,
    'compiled_rtl' => $dryRunRtl,
    'compiled_css_sha256' => hash('sha256', $dryRunCss),
    'compiled_rtl_sha256' => hash('sha256', $dryRunRtl),
    'dry_run' => true,
]);
check('style-update dry-run validates successfully', ($dryRun['action'] ?? null) === 'preview');
check('style-update dry-run does not write theme mods', $GLOBALS['test_update_option_calls'] === $beforeDryRunWrites);
check('style-update dry-run reports secret preservation', ($dryRun['secret_preserved'] ?? false) === true);

$invalidProvenance = (new TemplateStyleUpdateTool())->handle([
    'if_match' => $currentRead['etag'],
    'compiled_css' => $dryRunCss,
    'compiled_rtl' => $dryRunRtl,
    'compiled_css_sha256' => hash('sha256', $dryRunCss),
    'compiled_rtl_sha256' => hash('sha256', $dryRunRtl),
    'compile_provenance' => [
        'worker_sha256' => 'not-a-sha256',
        'sources_sha256' => str_repeat('b', 64),
    ],
    'dry_run' => true,
]);
check(
    'style-update rejects malformed compile provenance before any write',
    ($invalidProvenance['code'] ?? null) === 'invalid_compile_provenance'
        && $GLOBALS['test_update_option_calls'] === $beforeDryRunWrites
);

$createTarget = $root . '/wp-content/themes/yootheme-brand';
$createArguments = [
    'if_match' => $currentRead['etag'],
    'style_id' => 'industria-viva',
    'name' => 'Indústria Viva',
    'background' => 'White',
    'color' => 'Pink',
    'variations' => [
        ['id' => 'white-pink', 'name' => 'White Pink', 'background' => 'White', 'color' => 'Pink'],
    ],
    'less_source' => "@global-primary-background: #e85039;\n",
    'child_theme_slug' => 'yootheme-brand',
    'child_theme_name' => 'YOOtheme Brand',
];
$createPreview = (new TemplateStyleCreateTool())->handle($createArguments + ['dry_run' => true]);
check('style-create dry-run validates successfully', ($createPreview['action'] ?? null) === 'preview');
check('style-create dry-run does not create the child theme', !is_dir($createTarget));
check('style-create explicitly leaves activation for a separate operation', ($createPreview['child_theme']['will_activate'] ?? true) === false);

$created = (new TemplateStyleCreateTool())->handle($createArguments + ['confirm_guarded_write' => true]);
$createdStylePath = $createTarget . '/less/theme.industria-viva.less';
$createdStyle = is_file($createdStylePath) ? (string) file_get_contents($createdStylePath) : '';
check('style-create writes the versionable child-theme Style source', ($created['action'] ?? null) === 'created' && is_file($createdStylePath));
check('style-create scaffolds a declared YOOtheme child theme', str_contains((string) file_get_contents($createTarget . '/style.css'), 'Template: yootheme'));
check('style-create writes structured Style metadata', str_contains($createdStyle, "Name: Indústria Viva") && str_contains($createdStyle, "Style: white-pink"));
check('style-create preserves the supplied Less body', str_contains($createdStyle, '@global-primary-background: #e85039;'));
check('style-create creates a private snapshot before writing', ($created['snapshot_created'] ?? false) === true);
check('style-create does not make an inactive child Style runtime-visible', ($created['runtime_visible'] ?? true) === false);

$unchanged = (new TemplateStyleCreateTool())->handle($createArguments + ['confirm_guarded_write' => true]);
check('style-create is idempotent when the source already matches', ($unchanged['action'] ?? null) === 'unchanged');

$beforeCommitConfig = $helper->loadConfig();
$beforeCommitEtag = $helper->etag($beforeCommitConfig, $helper->compiledState());
$commitCandidate = $helper->patchConfig(
    $beforeCommitConfig,
    'flow',
    '',
    ['@global-primary-background' => '#654321'],
    [],
    null,
    false
);
$ltrTarget = $themeDir . '/css/theme.1.css';
$rtlTarget = $themeDir . '/css/theme.1.rtl.css';
$originalLtr = (string) file_get_contents($ltrTarget);

mkdir($rtlTarget);
$failedCommit = $helper->commitStyleUpdate(
    $commitCandidate,
    '.x{color:#654321}',
    '.x{color:#654321;direction:rtl}',
    $beforeCommitEtag
);
check('style-update reports a write failure after a partial file replacement', ($failedCommit['code'] ?? null) === 'style_write_failed');
check('style-update verifies config and file rollback separately', ($failedCommit['rollback']['restored'] ?? false) === true);
check('style-update restores config after a partial file replacement', $helper->loadConfig() === $beforeCommitConfig);
check('style-update restores the first CSS file after the second rename fails', (string) file_get_contents($ltrTarget) === $originalLtr);
rmdir($rtlTarget);

$beforeCommitEtag = $helper->etag($beforeCommitConfig, $helper->compiledState());
mkdir($rtlTarget);
$GLOBALS['test_force_cas_conflict_on_query'] = $GLOBALS['test_cas_query_calls'] + 2;
$incompleteRollback = $helper->commitStyleUpdate(
    $commitCandidate,
    '.x{color:#654321}',
    '.x{color:#654321;direction:rtl}',
    $beforeCommitEtag
);
$GLOBALS['test_force_cas_conflict_on_query'] = null;
check('style-update exposes an incomplete rollback instead of claiming recovery', ($incompleteRollback['rollback']['restored'] ?? true) === false);
check('style-update incomplete rollback message requires snapshot recovery', str_contains((string) ($incompleteRollback['error'] ?? ''), 'rollback is incomplete'));

update_option('theme_mods_yootheme', [
    'config' => (string) json_encode($beforeCommitConfig),
] + $GLOBALS['test_theme_mod_extras']);
file_put_contents($ltrTarget, $originalLtr);
rmdir($rtlTarget);

$beforeCommitEtag = $helper->etag($beforeCommitConfig, $helper->compiledState());
$committed = $helper->commitStyleUpdate(
    $commitCandidate,
    '.x{color:#654321}',
    '.x{color:#654321;direction:rtl}',
    $beforeCommitEtag
);
check('style-update commits config and CSS through compare-and-swap', !isset($committed['error']) && is_string($committed['new_etag'] ?? null));
check('style-update compare-and-swap preserves unrelated theme mods', ($GLOBALS['test_theme_mod_extras']['nav_menu_locations']['primary'] ?? null) === 17);

$cas = new ReflectionMethod(YoothemeStyleHelper::class, 'compareAndSwapThemeMods');
$currentMods = get_option('theme_mods_yootheme', []);
$conflictingMods = $currentMods;
$conflictingMods['config'] = (string) json_encode($beforeCommitConfig);
$GLOBALS['test_force_cas_conflict'] = true;
$conflict = $cas->invoke($helper, $currentMods, $conflictingMods, 'theme_mods_yootheme');
$GLOBALS['test_force_cas_conflict'] = false;
check('style-update rejects a lost-update race at the database gate', ($conflict['ok'] ?? true) === false);
check('a rejected compare-and-swap leaves the current config untouched', $helper->loadConfig() === $commitCandidate);

$previousStylesheet = $GLOBALS['test_stylesheet'];
$GLOBALS['test_stylesheet'] = 'yootheme-industria-viva';
$childRead = (new TemplateStyleReadTool())->handle([]);
check('style-read storage option follows the child stylesheet', ($childRead['storage']['option'] ?? '') === 'theme_mods_yootheme-industria-viva');
$childHelper = new YoothemeStyleHelper();
check('styleModsOptionName uses child theme mods', $childHelper->styleModsOptionName() === 'theme_mods_yootheme-industria-viva');
$childConfig = $childHelper->loadConfig();
$childEtag = $childHelper->etag($childConfig, $childHelper->compiledState());
$childCandidate = $childHelper->patchConfig($childConfig, 'flow', 'white-pink', ['@global-background' => '#fff'], [], null, false);
$childCommit = $childHelper->commitStyleUpdate(
    $childCandidate,
    '.x{color:#fff}',
    '.x{color:#fff;direction:rtl}',
    $childEtag
);
check('style-update commits against child theme_mods, not the parent row', !isset($childCommit['error']) && is_string($childCommit['new_etag'] ?? null));
check('child style-update stored @global-background as #fff', ($childHelper->loadConfig()['less']['@global-background'] ?? null) === '#fff');

$childSnapshotDir = $accountRoot . '/mirasai-backups/style/' . ($childCommit['snapshot_id'] ?? 'missing');
$childSnapshotManifest = is_file($childSnapshotDir . '/manifest.json')
    ? json_decode((string) file_get_contents($childSnapshotDir . '/manifest.json'), true)
    : null;
check(
    'child style-update snapshot names the exact theme_mods option',
    is_file($childSnapshotDir . '/theme_mods_yootheme-industria-viva.serialized')
);
check(
    'child style-update manifest records the exact theme_mods option',
    ($childSnapshotManifest['theme_mods_option'] ?? null) === 'theme_mods_yootheme-industria-viva'
);

$provenanceRead = (new TemplateStyleReadTool())->handle([]);
$provenanceCss = '.x{color:#112233}';
$provenanceRtl = '.x{color:#112233;direction:rtl}';
$provenanceUpdate = (new TemplateStyleUpdateTool())->handle([
    'if_match' => $provenanceRead['etag'],
    'vars' => ['@global-background' => '#112233'],
    'compiled_css' => $provenanceCss,
    'compiled_rtl' => $provenanceRtl,
    'compiled_css_sha256' => hash('sha256', $provenanceCss),
    'compiled_rtl_sha256' => hash('sha256', $provenanceRtl),
    'compile_provenance' => [
        'worker_sha256' => str_repeat('a', 64),
        'sources_sha256' => str_repeat('b', 64),
    ],
    'dry_run' => false,
    'confirm_guarded_write' => true,
]);
$provenanceFresh = (new TemplateStyleReadTool())->handle([]);
check('style-update stores compile provenance after a guarded router write', ($provenanceUpdate['provenance']['stored'] ?? false) === true);
$storedProvenance = $GLOBALS['test_options']['mirasai_yootheme_style_compile_state'] ?? [];
check(
    'stored compile provenance contains hashes, never config or secret fields',
    is_array($storedProvenance)
        && !array_key_exists('config', $storedProvenance)
        && !array_key_exists('yootheme_apikey', $storedProvenance)
);
check('style-read proves the router-written config and CSS are fresh', ($provenanceFresh['compiled']['config_freshness']['state'] ?? null) === 'fresh');
check('style-read marks config freshness as detectable with matching router provenance', ($provenanceFresh['compiled']['stale_config_detectable'] ?? false) === true);
check('style-read exposes the pinned worker hash without storing config content', ($provenanceFresh['compiled']['config_freshness']['worker_sha256'] ?? null) === str_repeat('a', 64));
$validStoredProvenance = $GLOBALS['test_options']['mirasai_yootheme_style_compile_state'];
$GLOBALS['test_options']['mirasai_yootheme_style_compile_state']['config_sha256'] = 'broken';
$invalidStoredProvenance = (new TemplateStyleReadTool())->handle([]);
check(
    'style-read treats corrupt persisted provenance as unknown',
    ($invalidStoredProvenance['compiled']['config_freshness']['state'] ?? null) === 'unknown'
        && ($invalidStoredProvenance['compiled']['config_freshness']['reason'] ?? null) === 'invalid_router_provenance'
);
$GLOBALS['test_options']['mirasai_yootheme_style_compile_state'] = $validStoredProvenance;
$GLOBALS['test_options']['mirasai_yootheme_style_compile_state']['theme_mods_option'] = 'theme_mods_other';
$mismatchedStoredProvenance = (new TemplateStyleReadTool())->handle([]);
check(
    'style-read rejects provenance recorded for a different Style option',
    ($mismatchedStoredProvenance['compiled']['config_freshness']['state'] ?? null) === 'unknown'
        && ($mismatchedStoredProvenance['compiled']['config_freshness']['reason'] ?? null) === 'style_storage_changed'
);
$GLOBALS['test_options']['mirasai_yootheme_style_compile_state'] = $validStoredProvenance;
$freshApiKey = $GLOBALS['test_config']['yootheme_apikey'];
$GLOBALS['test_config']['yootheme_apikey'] = str_repeat('c', 40);
$secretOnlyChange = (new TemplateStyleReadTool())->handle([]);
check('style-read ignores non-compilation secrets when hashing Style freshness', ($secretOnlyChange['compiled']['config_freshness']['state'] ?? null) === 'fresh');
$GLOBALS['test_config']['yootheme_apikey'] = $freshApiKey;

$freshConfig = $GLOBALS['test_config'];
$freshLtr = (string) file_get_contents($ltrTarget);
$GLOBALS['test_config']['less']['@global-background'] = '#445566';
$provenanceStale = (new TemplateStyleReadTool())->handle([]);
check('style-read detects config-only drift from the last router compile', ($provenanceStale['compiled']['config_freshness']['state'] ?? null) === 'stale');
check('style-read warns about config-only drift', (bool) array_filter($provenanceStale['warnings'] ?? [], static fn($w) => str_contains($w, 'changed after the last router-controlled compile')));

file_put_contents($ltrTarget, $freshLtr . "\n/* out-of-band compile */");
$provenanceUnknown = (new TemplateStyleReadTool())->handle([]);
check('style-read treats out-of-band CSS changes as unknown, not stale', ($provenanceUnknown['compiled']['config_freshness']['state'] ?? null) === 'unknown');
check('style-read no longer claims detectability after an out-of-band CSS change', ($provenanceUnknown['compiled']['stale_config_detectable'] ?? true) === false);
$GLOBALS['test_config'] = $freshConfig;
file_put_contents($ltrTarget, $freshLtr);

$freshRtl = (string) file_get_contents($rtlTarget);
unlink($rtlTarget);
$missingCompiledArtifact = (new TemplateStyleReadTool())->handle([]);
check(
    'style-read treats a missing recorded CSS artefact as unknown',
    ($missingCompiledArtifact['compiled']['config_freshness']['state'] ?? null) === 'unknown'
        && ($missingCompiledArtifact['compiled']['config_freshness']['reason'] ?? null) === 'compiled_css_changed_outside_router'
);
file_put_contents($rtlTarget, $freshRtl);

$GLOBALS['test_active_theme_mod_config_present'] = false;
$uninitializedRead = (new TemplateStyleReadTool())->handle([]);
check(
    'style-read marks an active child without config as unsafe to write',
    ($uninitializedRead['storage']['write_safe'] ?? true) === false
);
check(
    'style-read identifies the uninitialized child option and parent fallback separately',
    ($uninitializedRead['storage']['active_option'] ?? null) === 'theme_mods_yootheme-industria-viva'
        && ($uninitializedRead['storage']['source_option'] ?? null) === 'theme_mods_yootheme'
);
$uninitializedUpdate = (new TemplateStyleUpdateTool())->handle([
    'if_match' => $uninitializedRead['etag'],
    'compiled_css' => $dryRunCss,
    'compiled_rtl' => $dryRunRtl,
    'compiled_css_sha256' => hash('sha256', $dryRunCss),
    'compiled_rtl_sha256' => hash('sha256', $dryRunRtl),
    'dry_run' => false,
    'confirm_guarded_write' => true,
]);
check(
    'style-update blocks an active child whose Style config is not initialized',
    ($uninitializedUpdate['code'] ?? null) === 'style_storage_uninitialized'
);
$GLOBALS['test_stylesheet'] = 'yootheme';
$uninitializedParentRead = (new TemplateStyleReadTool())->handle([]);
check(
    'style-read also blocks an active parent theme whose Style config is not initialized',
    ($uninitializedParentRead['storage']['write_safe'] ?? true) === false
        && ($uninitializedParentRead['storage']['inherited_from_parent'] ?? true) === false
        && ($uninitializedParentRead['storage']['source_option'] ?? null) === 'theme_mods_yootheme'
);
$GLOBALS['test_active_theme_mod_config_present'] = true;
$GLOBALS['test_stylesheet'] = $previousStylesheet;

// YOOtheme's StyleFontLoader stores downloaded files in its own fonts cache,
// then makes their URLs relative to the CSS destination directory passed to
// css(). Passing the fonts directory here would produce bare filenames and
// make the browser look for them under /css instead of /fonts.
$GLOBALS['test_font_base_path'] = null;
$GLOBALS['test_font_relative_url'] = '../fonts/varelaround-1f86b7a1.woff2';
$GLOBALS['test_yootheme_app'] = new class {
    public function __invoke(string $service): object
    {
        return new class {
            public function parse(string $source): array
            {
                return [
                    '@import url(https://fonts.googleapis.com/css?family=Varela+Round);',
                    'https://fonts.googleapis.com/css?family=Varela+Round',
                ];
            }

            public function css(string $url, string $basePath): string
            {
                $GLOBALS['test_font_base_path'] = $basePath;

                return "@font-face{src:url({$GLOBALS['test_font_relative_url']})}\n";
            }
        };
    }
};
eval('namespace YOOtheme { function app(): object { return $GLOBALS["test_yootheme_app"]; } }');
$prepareCss = new ReflectionMethod(YoothemeStyleHelper::class, 'prepareCompiledCss');
$preparedCss = $prepareCss->invoke(
    $helper,
    '@import url(https://fonts.googleapis.com/css?family=Varela+Round);.x{color:red}'
);
check('style-update resolves localized fonts relative to the CSS directory', $GLOBALS['test_font_base_path'] === $themeDir . '/css');
check('style-update preserves the ../fonts URL needed by CSS files', str_contains($preparedCss, 'url(../fonts/varelaround-1f86b7a1.woff2)'));

$GLOBALS['test_font_relative_url'] = 'varelaround-missing.woff2';
$missingAssetRejected = false;
try {
    $prepareCss->invoke(
        $helper,
        '@import url(https://fonts.googleapis.com/css?family=Varela+Round);.x{color:red}'
    );
} catch (RuntimeException $exception) {
    $missingAssetRejected = str_contains($exception->getMessage(), 'varelaround-missing.woff2');
}
check('style-update rejects a missing relative CSS asset before staging', $missingAssetRejected);

// tool metadata
$tools = [
    new TemplateStyleReadTool(),
    new TemplateStyleSourcesTool(),
    new TemplateStyleCreateTool(),
    new TemplateStyleUpdateTool(),
];
foreach ($tools as $tool) {
    $mcp = $tool->toMcpTool();
    if (in_array($mcp['name'], ['template/style-create', 'template/style-update'], true)) {
        check("{$mcp['name']} is annotated guarded", ($mcp['annotations']['destructiveHint'] ?? false) === true);
        check("{$mcp['name']} declares guarded risk", ($mcp['metadata']['risk_level'] ?? '') === 'guarded_write');
    } else {
        check("{$mcp['name']} is annotated read-only", ($mcp['annotations']['readOnlyHint'] ?? false) === true);
        check("{$mcp['name']} declares read risk", ($mcp['metadata']['risk_level'] ?? '') === 'read');
    }
}

// ---------------------------------------------------------------- cleanup ---

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($accountRoot, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $item) {
    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
}
@rmdir($accountRoot);

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} YOOtheme Style read test(s) failed.\n");
    exit(1);
}

echo "All YOOtheme Style read tests passed.\n";
