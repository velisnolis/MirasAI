<?php
/**
 * Test: WordPress MCP handler enforces central risk gates before tool execution.
 *
 * Run from the repo root:
 *   php docker/test-wp-mcp-handler-contract.php
 */

declare(strict_types=1);

$GLOBALS['mirasai_wp_options'] = [];

function wp_json_encode(mixed $value, int $flags = 0): string|false
{
    return json_encode($value, $flags);
}

function wp_check_invalid_utf8(string $value, bool $strip = false): string
{
    return $value;
}

function get_option(string $name, mixed $default = false): mixed
{
    return $GLOBALS['mirasai_wp_options'][$name] ?? $default;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    $GLOBALS['mirasai_wp_options'][$name] = $value;

    return true;
}

function delete_option(string $name): bool
{
    unset($GLOBALS['mirasai_wp_options'][$name]);

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

function wp_get_environment_type(): string
{
    return 'production';
}

require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/ToolInterface.php';
require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/AbstractTool.php';
require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/ToolArgumentValidator.php';
require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/RuntimeSettings.php';
require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/ToolRegistry.php';
require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Mcp/McpHandler.php';

use Mirasai\WordPress\Mcp\McpHandler;
use Mirasai\WordPress\Tool\AbstractTool;
use Mirasai\WordPress\Tool\RuntimeSettings;
use Mirasai\WordPress\Tool\ToolRegistry;

$passed = 0;
$failed = 0;

function expectWpMcpHandler(string $label, mixed $actual, mixed $expected): void
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

abstract class TestWpHandlerTool extends AbstractTool
{
    public int $calls = 0;

    public function getDescription(): string
    {
        return 'test';
    }

    public function getInputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function handle(array $arguments): array
    {
        $this->calls++;

        return [
            'ok' => true,
            'arguments' => $arguments,
        ];
    }
}

final class TestWpGuardedTool extends TestWpHandlerTool
{
    public function getName(): string
    {
        return 'test/guarded';
    }

    public function getPermissions(): array
    {
        return ['risk_level' => self::RISK_GUARDED_WRITE];
    }
}

final class TestWpDangerousTool extends TestWpHandlerTool
{
    public function getName(): string
    {
        return 'test/dangerous';
    }

    public function getPermissions(): array
    {
        return ['risk_level' => self::RISK_DANGEROUS_EXEC];
    }
}

function callWpMcpTool(McpHandler $handler, string $name, array $arguments): array
{
    return $handler->handleRequest([
        'jsonrpc' => '2.0',
        'method' => 'tools/call',
        'params' => [
            'name' => $name,
            'arguments' => $arguments,
        ],
        'id' => 1,
    ])['result'];
}

$registry = new ToolRegistry();
$guarded = new TestWpGuardedTool();
$dangerous = new TestWpDangerousTool();
$registry->register($guarded);
$registry->register($dangerous);
$handler = new McpHandler($registry);

$blockedGuarded = callWpMcpTool($handler, 'test/guarded', []);
expectWpMcpHandler('guarded write without dry_run or confirmation is blocked centrally', $blockedGuarded['structuredContent']['code'] ?? null, 'guarded_write_confirmation_required');
expectWpMcpHandler('blocked guarded write is not executed', $guarded->calls, 0);

$dryRunGuarded = callWpMcpTool($handler, 'test/guarded', ['dry_run' => true]);
expectWpMcpHandler('guarded write dry_run reaches tool', $dryRunGuarded['structuredContent']['ok'] ?? null, true);
expectWpMcpHandler('guarded write dry_run executes once', $guarded->calls, 1);

$stringFalseGuarded = callWpMcpTool($handler, 'test/guarded', ['confirm_guarded_write' => 'false']);
expectWpMcpHandler('string false is not accepted as guarded confirmation', $stringFalseGuarded['structuredContent']['code'] ?? null, 'guarded_write_confirmation_required');
expectWpMcpHandler('string false does not execute guarded write', $guarded->calls, 1);

$confirmedGuarded = callWpMcpTool($handler, 'test/guarded', ['confirm_guarded_write' => true]);
expectWpMcpHandler('literal true guarded confirmation reaches tool', $confirmedGuarded['structuredContent']['ok'] ?? null, true);
expectWpMcpHandler('literal true executes guarded write', $guarded->calls, 2);

$blockedDangerous = callWpMcpTool($handler, 'test/dangerous', ['confirm_execute_php' => true]);
expectWpMcpHandler('dangerous exec without enabled controls is blocked centrally', $blockedDangerous['structuredContent']['code'] ?? null, 'dangerous_exec_not_enabled');
expectWpMcpHandler('blocked dangerous exec is not executed', $dangerous->calls, 0);

RuntimeSettings::enableDangerousExec();
$allowedDangerous = callWpMcpTool($handler, 'test/dangerous', ['confirm_execute_php' => true]);
expectWpMcpHandler('dangerous exec reaches tool when controls are enabled for domain', $allowedDangerous['structuredContent']['ok'] ?? null, true);
expectWpMcpHandler('dangerous exec executes once after enable', $dangerous->calls, 1);

if ($failed > 0) {
    echo "\n{$failed} WordPress MCP handler contract test(s) failed.\n";
    exit(1);
}

echo "\nAll {$passed} WordPress MCP handler contract tests passed.\n";
