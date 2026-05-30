<?php
/**
 * Test: WordPress sandbox/execute-php is gated, requires explicit confirmation,
 * and wraps confirmed execution in a DB transaction.
 *
 * Run from the repo root:
 *   php docker/test-wp-sandbox-execute-php-contract.php
 */

declare(strict_types=1);

define('WP_CONTENT_DIR', sys_get_temp_dir() . '/mirasai-wp-sandbox-test-content');

$GLOBALS['mirasai_wp_test_options'] = [];

function get_option(string $name, mixed $default = false): mixed
{
    return $GLOBALS['mirasai_wp_test_options'][$name] ?? $default;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    $GLOBALS['mirasai_wp_test_options'][$name] = $value;

    return true;
}

function delete_option(string $name): bool
{
    unset($GLOBALS['mirasai_wp_test_options'][$name]);

    return true;
}

function get_current_user_id(): int
{
    return 123;
}

function home_url(): string
{
    return 'https://example.test';
}

function wp_parse_url(string $url, int $component = -1): mixed
{
    return parse_url($url, $component);
}

function wp_mkdir_p(string $target): bool
{
    return is_dir($target) || mkdir($target, 0777, true);
}

final class FakeWpdb
{
    public string $last_error = '';

    /** @var list<string> */
    public array $queries = [];

    public function query(string $sql): int|false
    {
        $this->queries[] = $sql;

        return 1;
    }
}

$src = dirname(__DIR__) . '/packages/mirasai-wp/src';

require_once $src . '/Tool/ToolInterface.php';
require_once $src . '/Tool/AbstractTool.php';
require_once $src . '/Tool/RuntimeSettings.php';
require_once $src . '/Tool/SandboxExecutePhpTool.php';

use Mirasai\WordPress\Tool\AbstractTool;
use Mirasai\WordPress\Tool\RuntimeSettings;
use Mirasai\WordPress\Tool\SandboxExecutePhpTool;

$passed = 0;
$failed = 0;

function expectWpSandbox(string $label, mixed $actual, mixed $expected): void
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

function removeWpSandboxTestDir(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $entries = scandir($path);
    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child)) {
            removeWpSandboxTestDir($child);
        } else {
            unlink($child);
        }
    }

    rmdir($path);
}

if (is_dir(WP_CONTENT_DIR)) {
    removeWpSandboxTestDir(WP_CONTENT_DIR);
}

$wpdb = new FakeWpdb();
$tool = new SandboxExecutePhpTool();

$disabled = $tool->handle([
    'code' => 'return 42;',
    'confirm_execute_php' => true,
]);
expectWpSandbox('disabled dangerous_exec gate rejects execution', $disabled['code'] ?? null, 'dangerous_exec_not_enabled');
expectWpSandbox('disabled gate does not start transaction', $wpdb->queries, []);

RuntimeSettings::enableDangerousExec();

$schema = $tool->getInputSchema();
expectWpSandbox('confirm flag is required in schema', in_array('confirm_execute_php', $schema['required'] ?? [], true), true);

$permissions = AbstractTool::normalizePermissions($tool->getPermissions());
expectWpSandbox('tool risk is dangerous_exec', $permissions['risk_level'], AbstractTool::RISK_DANGEROUS_EXEC);
expectWpSandbox('dangerous_exec gate is available after enable', RuntimeSettings::isDangerousExecEnabled(), true);

$missingConfirm = $tool->handle(['code' => 'return 42;']);
expectWpSandbox('missing confirmation is rejected', $missingConfirm['code'] ?? null, 'execute_php_confirmation_required');
expectWpSandbox('missing confirmation does not start transaction', $wpdb->queries, []);

$confirmed = $tool->handle([
    'code' => 'return 42;',
    'confirm_execute_php' => true,
]);
expectWpSandbox('confirmed execution returns value', $confirmed['return_value'] ?? null, 42);
expectWpSandbox('confirmed execution commits transaction', $confirmed['transaction'] ?? null, 'committed');
expectWpSandbox('confirmed execution query sequence', $wpdb->queries, ['START TRANSACTION', 'COMMIT']);

$wpdb->queries = [];
$exception = $tool->handle([
    'code' => 'throw new \RuntimeException("boom");',
    'confirm_execute_php' => true,
]);
expectWpSandbox('exception marks tool result as failed', $exception['code'] ?? null, 'execution_failed');
expectWpSandbox('exception rolls back transaction', $exception['transaction'] ?? null, 'rolled_back');
expectWpSandbox('exception query sequence', $wpdb->queries, ['START TRANSACTION', 'ROLLBACK']);

if ($failed > 0) {
    echo "\n{$failed} WordPress sandbox execute-php contract test(s) failed.\n";
    exit(1);
}

echo "\nAll {$passed} WordPress sandbox execute-php contract tests passed.\n";
