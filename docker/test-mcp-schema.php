<?php
/**
 * Test: MCP tool schema serialization.
 *
 * Run from the repo root:
 *   php docker/test-mcp-schema.php
 */

declare(strict_types=1);

$libSrc = dirname(__DIR__) . '/pkg_mirasai/packages/lib_mirasai/src';

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

echo "\n=== MCP schema normalization ===\n";

$emptyJson = json_encode((new EmptyPropertiesTool())->toMcpTool(), JSON_THROW_ON_ERROR);
$emptyDecoded = json_decode($emptyJson, true, flags: JSON_THROW_ON_ERROR);
expect('empty properties serializes as JSON object', $emptyDecoded['inputSchema']['properties'], []);
expect('empty properties raw JSON contains object', str_contains($emptyJson, '"properties":{}'), true);

$missingJson = json_encode((new MissingPropertiesTool())->toMcpTool(), JSON_THROW_ON_ERROR);
$missingDecoded = json_decode($missingJson, true, flags: JSON_THROW_ON_ERROR);
expect('missing properties is added as JSON object', $missingDecoded['inputSchema']['properties'], []);
expect('missing properties raw JSON contains object', str_contains($missingJson, '"properties":{}'), true);

if ($failed > 0) {
    echo "\n{$failed} schema test(s) failed.\n";
    exit(1);
}

echo "\nAll {$passed} schema tests passed.\n";
