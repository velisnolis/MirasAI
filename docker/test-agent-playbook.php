<?php
/**
 * Test: agent playbook is present on both hosts and initialize points at it.
 *
 * Run from the repo root:
 *   php docker/test-agent-playbook.php
 */

declare(strict_types=1);

putenv('MIRASAI_ENV=staging');
$_SERVER['MIRASAI_ENV'] = 'staging';

$package = json_decode((string) file_get_contents(dirname(__DIR__) . '/package.json'), true, flags: JSON_THROW_ON_ERROR);
define('MIRASAI_WP_VERSION', (string) $package['version']);
define('MIRASAI_WP_CONTRACT_VERSION', '1');

require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/ToolInterface.php';
require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/AbstractTool.php';
require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/AgentPlaybook.php';
require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/ToolRegistry.php';
require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Mcp/McpHandler.php';

require_once dirname(__DIR__) . '/packages/mirasai-joomla/packages/lib_mirasai/src/Mirasai.php';
require_once dirname(__DIR__) . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/ToolInterface.php';
require_once dirname(__DIR__) . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/AbstractTool.php';
require_once dirname(__DIR__) . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/AgentPlaybook.php';
require_once dirname(__DIR__) . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/ToolRegistry.php';
require_once dirname(__DIR__) . '/packages/mirasai-joomla/packages/lib_mirasai/src/Sandbox/EnvironmentGuard.php';
require_once dirname(__DIR__) . '/packages/mirasai-joomla/packages/lib_mirasai/src/Mcp/McpHandler.php';

use Mirasai\Library\Mcp\McpHandler as JoomlaMcpHandler;
use Mirasai\Library\Tool\AgentPlaybook as JoomlaPlaybook;
use Mirasai\Library\Tool\ToolRegistry as JoomlaToolRegistry;
use Mirasai\WordPress\Mcp\McpHandler as WordPressMcpHandler;
use Mirasai\WordPress\Tool\AgentPlaybook as WordPressPlaybook;
use Mirasai\WordPress\Tool\ToolRegistry as WordPressToolRegistry;

$passed = 0;
$failed = 0;

function expectPlaybook(string $label, mixed $actual, mixed $expected): void
{
    global $passed, $failed;

    if ($actual === $expected) {
        echo "[PASS] {$label}\n";
        $passed++;

        return;
    }

    echo "[FAIL] {$label}\n";
    echo "       Expected: " . var_export($expected, true) . "\n";
    echo "       Actual:   " . var_export($actual, true) . "\n";
    $failed++;
}

function expectPlaybookContains(string $label, string $haystack, string $needle): void
{
    expectPlaybook($label, str_contains($haystack, $needle), true);
}

$requiredJobs = [
    'inspect_site',
    'content',
    'yootheme_builder',
    'yootheme_style_read',
    'yootheme_style_write',
    'verify_css_on_disk',
    'purge_page_cache',
    'files_db_sandbox',
];
$requiredLoops = [
    'customizer_save_noop',
    'host_style_update_missing_css',
    'stale_sources_false_negative',
    'mcp2cli_host_is_not_router',
    'router_wrong_site_or_unpinned_worker',
    'mcp2cli_dry_run_omitted',
    'hex_minify_same_css_bytes',
    'unauthenticated_host',
];

$playbooks = [
    'wordpress' => WordPressPlaybook::build(),
    'joomla' => JoomlaPlaybook::build(),
];

foreach ($playbooks as $platform => $playbook) {
    expectPlaybook("{$platform} playbook version is 2", $playbook['version'] ?? null, 2);
    expectPlaybook(
        "{$platform} playbook names host auth as a dependency",
        is_array($playbook['depends_on']['host_http'] ?? null),
        true
    );
    expectPlaybookContains(
        "{$platform} router depends on site_id",
        json_encode($playbook['depends_on']['router'] ?? []),
        'site_id'
    );
    expectPlaybook("{$platform} host does not compile LESS", $playbook['compiler_on_this_endpoint'] ?? true, false);
    expectPlaybook(
        "{$platform} tells agents to inspect tools/list for the compiler",
        str_contains((string) ($playbook['compiler_present_iff'] ?? ''), 'mirasai/style-preview'),
        true
    );

    $jobIds = array_values(array_map(
        static fn (array $job): string => (string) ($job['id'] ?? ''),
        is_array($playbook['jobs'] ?? null) ? $playbook['jobs'] : []
    ));
    foreach ($requiredJobs as $jobId) {
        expectPlaybook("{$platform} playbook has job {$jobId}", in_array($jobId, $jobIds, true), true);
    }

    $styleWrite = null;
    foreach ($playbook['jobs'] as $job) {
        if (($job['id'] ?? '') === 'yootheme_style_write') {
            $styleWrite = $job;
            break;
        }
    }
    expectPlaybookContains(
        "{$platform} style write stops on host-only",
        (string) ($styleWrite['if_only_this_host'] ?? ''),
        'STOP'
    );
    expectPlaybookContains(
        "{$platform} style write prefers router update",
        (string) ($styleWrite['best_if_router_tools_listed'] ?? ''),
        'mirasai/style-update'
    );
    expectPlaybookContains(
        "{$platform} style write names site_id",
        (string) ($styleWrite['best_if_router_tools_listed'] ?? ''),
        'site_id'
    );
    expectPlaybookContains(
        "{$platform} style write says hex minify is not failure",
        (string) ($styleWrite['proof'] ?? ''),
        'compiled on'
    );

    $loopIds = array_values(array_map(
        static fn (array $loop): string => (string) ($loop['id'] ?? ''),
        is_array($playbook['anti_loops'] ?? null) ? $playbook['anti_loops'] : []
    ));
    foreach ($requiredLoops as $loopId) {
        expectPlaybook("{$platform} playbook has anti-loop {$loopId}", in_array($loopId, $loopIds, true), true);
    }
}

$wpInstructions = WordPressPlaybook::initializeInstructions();
expectPlaybookContains('WordPress initialize names diagnose', $wpInstructions, 'system/diagnose');
expectPlaybookContains('WordPress initialize denies host compile', $wpInstructions, 'does not compile');
expectPlaybookContains('WordPress initialize names router compiler tool', $wpInstructions, 'mirasai/style-preview');
expectPlaybookContains('WordPress initialize names Customizer no-op', $wpInstructions, 'dirty=false');
expectPlaybookContains('WordPress initialize names child theme_mods', $wpInstructions, 'theme_mods_yootheme');

$wpLoops = array_values(array_map(
    static fn (array $loop): string => (string) ($loop['id'] ?? ''),
    is_array($playbooks['wordpress']['anti_loops'] ?? null) ? $playbooks['wordpress']['anti_loops'] : []
));
expectPlaybook('wordpress playbook has anti-loop child_theme_parent_mods', in_array('child_theme_parent_mods', $wpLoops, true), true);
expectPlaybookContains(
    'wordpress host storage names child theme_mods',
    (string) ($playbooks['wordpress']['channels']['this_host']['storage'] ?? ''),
    'theme_mods_{get_stylesheet()}'
);

$joomlaInstructions = JoomlaPlaybook::initializeInstructions();
expectPlaybookContains('Joomla initialize names diagnose', $joomlaInstructions, 'system/diagnose');
expectPlaybookContains('Joomla initialize denies host compile', $joomlaInstructions, 'does not compile');
expectPlaybookContains('Joomla initialize names router compiler tool', $joomlaInstructions, 'mirasai/style-preview');

$wp = new WordPressMcpHandler(new WordPressToolRegistry());
$wpInit = $wp->handleRequest([
    'jsonrpc' => '2.0',
    'method' => 'initialize',
    'id' => 1,
]);
expectPlaybookContains(
    'WordPress initialize payload includes playbook pointer',
    (string) ($wpInit['result']['instructions'] ?? ''),
    'system/diagnose'
);

$joomla = new JoomlaMcpHandler(new JoomlaToolRegistry());
$joomlaInit = $joomla->handleRequest([
    'jsonrpc' => '2.0',
    'method' => 'initialize',
    'id' => 2,
]);
expectPlaybookContains(
    'Joomla initialize payload starts with playbook pointer',
    (string) ($joomlaInit['result']['instructions'] ?? ''),
    'does not compile YOOtheme LESS'
);

if ($failed > 0) {
    echo "\n{$failed} agent playbook test(s) failed.\n";
    exit(1);
}

echo "\nAll {$passed} agent playbook tests passed.\n";
