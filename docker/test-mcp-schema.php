<?php
/**
 * Test: MCP tool schema serialization.
 *
 * Run from the repo root:
 *   php docker/test-mcp-schema.php
 */

declare(strict_types=1);

$libSrc = dirname(__DIR__) . '/packages/mirasai-joomla/packages/lib_mirasai/src';

require_once $libSrc . '/Tool/ToolInterface.php';
require_once $libSrc . '/Tool/AbstractTool.php';

use Mirasai\Library\Tool\AbstractTool;

$passed = 0;
$failed = 0;

function expect(string $label, mixed $actual, mixed $expected): void
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

final class EmptyPropertiesTool extends AbstractTool
{
    public function __construct() {}
    public function getName(): string { return 'test/empty-properties'; }
    public function getDescription(): string { return 'test'; }
    public function getInputSchema(): array { return ['type' => 'object', 'properties' => []]; }
    public function handle(array $arguments): array { return []; }
}

final class MissingPropertiesTool extends AbstractTool
{
    public function __construct() {}
    public function getName(): string { return 'test/missing-properties'; }
    public function getDescription(): string { return 'test'; }
    public function getInputSchema(): array { return ['type' => 'object']; }
    public function handle(array $arguments): array { return []; }
}

final class GuardedWriteTool extends AbstractTool
{
    public function __construct() {}
    public function getName(): string { return 'test/guarded-write'; }
    public function getDescription(): string { return 'test'; }
    public function getInputSchema(): array { return ['type' => 'object', 'properties' => []]; }
    public function getPermissions(): array { return ['risk_level' => self::RISK_GUARDED_WRITE, 'idempotent' => false]; }
    public function handle(array $arguments): array { return []; }
}

final class DangerousExecTool extends AbstractTool
{
    public function __construct() {}
    public function getName(): string { return 'test/dangerous-exec'; }
    public function getDescription(): string { return 'test'; }
    public function getInputSchema(): array { return ['type' => 'object', 'properties' => []]; }
    public function getPermissions(): array { return ['risk_level' => self::RISK_DANGEROUS_EXEC, 'idempotent' => false]; }
    public function handle(array $arguments): array { return []; }
}

echo "\n=== MCP schema normalization ===\n";

$emptyJson = json_encode((new EmptyPropertiesTool())->toMcpTool(), JSON_THROW_ON_ERROR);
$emptyDecoded = json_decode($emptyJson, true, flags: JSON_THROW_ON_ERROR);
expect('empty properties serializes as JSON object', $emptyDecoded['inputSchema']['properties'], []);
expect('empty properties raw JSON contains object', str_contains($emptyJson, '"properties":{}'), true);

$missingJson = json_encode((new MissingPropertiesTool())->toMcpTool(), JSON_THROW_ON_ERROR);
$missingDecoded = json_decode($missingJson, true, flags: JSON_THROW_ON_ERROR);
expect('missing properties is added as JSON object', $missingDecoded['inputSchema']['properties'], []);
expect('missing properties raw JSON contains object', str_contains($missingJson, '"properties":{}'), true);

$guardedDecoded = (new GuardedWriteTool())->toMcpTool();
expect('guarded write exposes risk level', $guardedDecoded['metadata']['risk_level'] ?? null, AbstractTool::RISK_GUARDED_WRITE);
expect('guarded write exposes workflow hint', $guardedDecoded['metadata']['workflow_hint'] ?? null, 'dry_run_confirm_if_match');
expect('guarded write omits legacy destructive hint', $guardedDecoded['metadata']['destructive'] ?? null, null);
expect('guarded write does not require elevation', $guardedDecoded['metadata']['requires_elevation'] ?? null, null);
expect('guarded write exposes annotations readOnlyHint', $guardedDecoded['annotations']['readOnlyHint'] ?? null, false);
expect('guarded write exposes annotations destructiveHint', $guardedDecoded['annotations']['destructiveHint'] ?? null, true);
expect('guarded write exposes annotations idempotentHint', $guardedDecoded['annotations']['idempotentHint'] ?? null, false);
expect('guarded write exposes annotations openWorldHint', $guardedDecoded['annotations']['openWorldHint'] ?? null, true);

$dangerousDecoded = (new DangerousExecTool())->toMcpTool();
expect('dangerous exec exposes risk level', $dangerousDecoded['metadata']['risk_level'] ?? null, AbstractTool::RISK_DANGEROUS_EXEC);
expect('dangerous exec exposes workflow hint', $dangerousDecoded['metadata']['workflow_hint'] ?? null, 'elevation_required');
expect('dangerous exec requires elevation', $dangerousDecoded['metadata']['requires_elevation'] ?? null, true);
expect('dangerous exec exposes annotations destructiveHint', $dangerousDecoded['annotations']['destructiveHint'] ?? null, true);

$readOnlyDecoded = (new EmptyPropertiesTool())->toMcpTool();
expect('read tool exposes annotations readOnlyHint', $readOnlyDecoded['annotations']['readOnlyHint'] ?? null, true);
expect('read tool exposes annotations destructiveHint', $readOnlyDecoded['annotations']['destructiveHint'] ?? null, false);
expect('read tool exposes annotations idempotentHint', $readOnlyDecoded['annotations']['idempotentHint'] ?? null, true);

if ($failed > 0) {
    echo "\n{$failed} schema test(s) failed.\n";
    exit(1);
}

echo "\nAll {$passed} schema tests passed.\n";
