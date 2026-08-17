<?php
/**
 * Test: WordPress and Joomla hosts expose the contract metadata used by the
 * local router to detect drift.
 *
 * Run from the repo root:
 *   php docker/test-host-contract.php
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
use Mirasai\Library\Tool\ToolRegistry as JoomlaToolRegistry;
use Mirasai\WordPress\Mcp\McpHandler as WordPressMcpHandler;
use Mirasai\WordPress\Tool\ToolRegistry as WordPressToolRegistry;

$passed = 0;
$failed = 0;

function expectHostContract(string $label, mixed $actual, mixed $expected): void
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

$wp = new WordPressMcpHandler(new WordPressToolRegistry());
$wpResponse = $wp->handleRequest([
    'jsonrpc' => '2.0',
    'method' => 'initialize',
    'id' => 1,
]);
$wpInfo = $wpResponse['result']['serverInfo'] ?? [];

expectHostContract('WordPress initialize reports host platform', $wpInfo['host_platform'] ?? null, 'wordpress');
expectHostContract('WordPress initialize reports contract version', $wpInfo['host_contract_version'] ?? null, '1');

$joomla = new JoomlaMcpHandler(new JoomlaToolRegistry());
$joomlaResponse = $joomla->handleRequest([
    'jsonrpc' => '2.0',
    'method' => 'initialize',
    'id' => 2,
]);
$joomlaInfo = $joomlaResponse['result']['serverInfo'] ?? [];

expectHostContract('Joomla initialize reports host platform', $joomlaInfo['host_platform'] ?? null, 'joomla');
expectHostContract('Joomla initialize reports contract version', $joomlaInfo['host_contract_version'] ?? null, '1');
expectHostContract(
    'WordPress initialize points agents at diagnose',
    str_contains((string) ($wpResponse['result']['instructions'] ?? ''), 'system/diagnose'),
    true
);
expectHostContract(
    'Joomla initialize points agents at diagnose',
    str_contains((string) ($joomlaResponse['result']['instructions'] ?? ''), 'system/diagnose'),
    true
);

if ($failed > 0) {
    echo "\n{$failed} host contract test(s) failed.\n";
    exit(1);
}

echo "\nAll {$passed} host contract tests passed.\n";
