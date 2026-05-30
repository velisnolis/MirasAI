<?php
/**
 * Test: MCP tools/call result wrapping.
 *
 * Run from the repo root:
 *   php docker/test-mcp-handler.php
 */

declare(strict_types=1);

$libSrc = dirname(__DIR__) . '/packages/mirasai-joomla/packages/lib_mirasai/src';

require_once $libSrc . '/Tool/ToolInterface.php';
require_once $libSrc . '/Tool/AbstractTool.php';
require_once $libSrc . '/Tool/ToolRegistry.php';
require_once $libSrc . '/Mcp/McpHandler.php';

use Mirasai\Library\Mcp\McpHandler;
use Mirasai\Library\Tool\AbstractTool;
use Mirasai\Library\Tool\ToolRegistry;

$passed = 0;
$failed = 0;

function expectHandler(string $label, mixed $actual, mixed $expected): void
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

final class SuccessResultTool extends AbstractTool
{
    public function __construct() {}
    public function getName(): string { return 'test/success'; }
    public function getDescription(): string { return 'test'; }
    public function getInputSchema(): array { return ['type' => 'object', 'properties' => []]; }
    public function handle(array $arguments): array { return ['ok' => true, 'value' => $arguments['value'] ?? null]; }
}

final class ErrorResultTool extends AbstractTool
{
    public function __construct() {}
    public function getName(): string { return 'test/error'; }
    public function getDescription(): string { return 'test'; }
    public function getInputSchema(): array { return ['type' => 'object', 'properties' => []]; }
    public function handle(array $arguments): array { return ['error' => 'Missing value.', 'code' => 'missing_value']; }
}

echo "\n=== MCP tools/call result wrapping ===\n";

$registry = new ToolRegistry();
$registry->register(new SuccessResultTool());
$registry->register(new ErrorResultTool());
$handler = new McpHandler($registry);

$success = $handler->handleRequest([
    'jsonrpc' => '2.0',
    'method' => 'tools/call',
    'params' => [
        'name' => 'test/success',
        'arguments' => ['value' => 42],
    ],
    'id' => 1,
]);
expectHandler('success has no protocol error', $success['error'] ?? null, null);
expectHandler('success omits isError', $success['result']['isError'] ?? null, null);
expectHandler('success structuredContent mirrors payload', $success['result']['structuredContent'] ?? null, ['ok' => true, 'value' => 42]);
expectHandler('success text remains JSON', json_decode($success['result']['content'][0]['text'] ?? '', true), ['ok' => true, 'value' => 42]);

$toolError = $handler->handleRequest([
    'jsonrpc' => '2.0',
    'method' => 'tools/call',
    'params' => [
        'name' => 'test/error',
        'arguments' => [],
    ],
    'id' => 2,
]);
expectHandler('tool error has no protocol error', $toolError['error'] ?? null, null);
expectHandler('tool error sets isError', $toolError['result']['isError'] ?? null, true);
expectHandler('tool error exposes structuredContent', $toolError['result']['structuredContent'] ?? null, ['error' => 'Missing value.', 'code' => 'missing_value']);
expectHandler('tool error text remains JSON', json_decode($toolError['result']['content'][0]['text'] ?? '', true), ['error' => 'Missing value.', 'code' => 'missing_value']);

$protocolError = $handler->handleRequest([
    'jsonrpc' => '2.0',
    'method' => 'tools/call',
    'params' => [
        'name' => 'test/missing',
        'arguments' => [],
    ],
    'id' => 3,
]);
expectHandler('unknown tool is protocol error', $protocolError['error']['code'] ?? null, -32602);
expectHandler('unknown tool has no tool result', $protocolError['result'] ?? null, null);

if ($failed > 0) {
    echo "\n{$failed} MCP handler test(s) failed.\n";
    exit(1);
}

echo "\nAll {$passed} MCP handler tests passed.\n";
