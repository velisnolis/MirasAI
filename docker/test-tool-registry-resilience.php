<?php
/**
 * Test: tools/list skips a broken tool instead of failing the whole list.
 *
 * Run from the repo root:
 *   php docker/test-tool-registry-resilience.php
 */

declare(strict_types=1);

$libSrc = dirname(__DIR__) . '/pkg_mirasai/packages/lib_mirasai/src';

require_once $libSrc . '/Tool/ToolInterface.php';
require_once $libSrc . '/Tool/AbstractTool.php';
require_once $libSrc . '/Tool/ToolRegistry.php';

use Mirasai\Library\Tool\ToolInterface;
use Mirasai\Library\Tool\ToolRegistry;

$passed = 0;
$failed = 0;

function expectRegistry(string $label, mixed $actual, mixed $expected): void
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

final class EssentialRegistryTool implements ToolInterface
{
    public function getName(): string { return 'system/info'; }
    public function getDescription(): string { return 'essential'; }
    public function getInputSchema(): array { return ['type' => 'object']; }
    public function getPermissions(): array { return []; }
    public function getAuditSummary(array $args): string { return ''; }
    public function handle(array $args): array { return []; }
    public function toMcpTool(): array
    {
        return [
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'inputSchema' => $this->getInputSchema(),
        ];
    }
}

final class AdvancedRegistryTool implements ToolInterface
{
    public function getName(): string { return 'test/advanced'; }
    public function getDescription(): string { return 'advanced'; }
    public function getInputSchema(): array { return ['type' => 'object']; }
    public function getPermissions(): array { return []; }
    public function getAuditSummary(array $args): string { return ''; }
    public function handle(array $args): array { return []; }
    public function toMcpTool(): array
    {
        return [
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'inputSchema' => $this->getInputSchema(),
        ];
    }
}

final class BrokenRegistryTool implements ToolInterface
{
    public function getName(): string { return 'test/broken'; }
    public function getDescription(): string { return 'broken'; }
    public function getInputSchema(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getAuditSummary(array $args): string { return ''; }
    public function handle(array $args): array { return []; }
    public function toMcpTool(): array
    {
        throw new RuntimeException('schema unavailable');
    }
}

set_error_handler(static function (): bool {
    return true;
});

try {
    $registry = new ToolRegistry();
    $registry->register(new EssentialRegistryTool(), 'test');
    $registry->register(new AdvancedRegistryTool(), 'test');
    $registry->register(new BrokenRegistryTool(), 'test');

    $tools = $registry->toMcpToolsList();

    expectRegistry('healthy tools remain in tools/list', array_column($tools, 'name'), ['system/info', 'test/advanced']);
    expectRegistry('broken tool warning is recorded', count($registry->getWarnings()), 1);
    expectRegistry('warning names the broken tool', str_contains($registry->getWarnings()[0] ?? '', 'test/broken'), true);
    expectRegistry('essential surface filters tools/list', array_column($registry->toMcpToolsList('essential'), 'name'), ['system/info']);
    expectRegistry('advanced surface filters tools/list', array_column($registry->toMcpToolsList('advanced'), 'name'), ['test/advanced']);
    expectRegistry('surface metadata is attached', $tools[0]['metadata']['surface'] ?? null, 'essential');
} finally {
    restore_error_handler();
}

if ($failed > 0) {
    echo "\n{$failed} registry resilience test(s) failed.\n";
    exit(1);
}

echo "\nAll {$passed} registry resilience tests passed.\n";
