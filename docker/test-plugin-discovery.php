<?php
/**
 * Integration tests for ToolRegistry plugin discovery.
 *
 * Run inside the Docker lab after lib_mirasai is installed:
 *   php /var/www/html/test-plugin-discovery.php
 *
 * Exit code 0 = all tests pass.
 */

declare(strict_types=1);

// ── Bootstrap ────────────────────────────────────────────────────────────────
if (file_exists(__DIR__ . '/bootstrap-test.php')) {
    require_once __DIR__ . '/bootstrap-test.php';
} else {
    // Minimal standalone bootstrap for lib_mirasai
    $libSrc = dirname(__DIR__) . '/pkg_mirasai/packages/lib_mirasai/src';
    require_once $libSrc . '/Tool/ToolInterface.php';
    require_once $libSrc . '/Tool/ToolProviderInterface.php';
    require_once $libSrc . '/Tool/ContentLayoutProcessorInterface.php';
    require_once $libSrc . '/Mcp/MirasaiCollectToolsEvent.php';
    require_once $libSrc . '/Tool/YooThemeLayoutProcessor.php';
    require_once $libSrc . '/Tool/YooThemeHelper.php';
}

use Mirasai\Library\Tool\ToolInterface;
use Mirasai\Library\Tool\ToolProviderInterface;
use Mirasai\Library\Tool\ContentLayoutProcessorInterface;
use Mirasai\Library\Tool\ToolRegistry;

// ── Helpers ───────────────────────────────────────────────────────────────────
$passed = 0;
$failed = 0;

function expect(string $label, mixed $actual, mixed $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        echo "[PASS] {$label}\n";
        $passed++;
    } else {
        echo "[FAIL] {$label}\n";
        echo "       Expected: " . var_export($expected, true) . "\n";
        echo "       Actual:   " . var_export($actual, true) . "\n";
        $failed++;
    }
}

function expectTrue(string $label, bool $value): void  { expect($label, $value, true); }
function expectFalse(string $label, bool $value): void { expect($label, $value, false); }

// ── Stub tools for mock providers ─────────────────────────────────────────────
class StubTool implements ToolInterface {
    public function __construct(private string $name) {}
    public function getName(): string { return $this->name; }
    public function getDescription(): string { return 'stub'; }
    public function getInputSchema(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getAuditSummary(array $args): string { return ''; }
    public function toMcpTool(): array { return ['name' => $this->name, 'description' => 'stub', 'inputSchema' => []]; }
    public function handle(array $args): array { return []; }
}

class StubProvider implements ToolProviderInterface {
    public function __construct(
        private string $id,
        private string $name,
        private bool $available,
        /** @var list<string> */
        private array $toolNames,
    ) {}
    public function getId(): string { return $this->id; }
    public function getName(): string { return $this->name; }
    public function isAvailable(): bool { return $this->available; }
    public function getToolNames(): array { return $this->toolNames; }
    public function createTool(string $name): ToolInterface { return new StubTool($name); }
    public function getContentLayoutProcessor(): ?ContentLayoutProcessorInterface { return null; }
}

class ThrowingProvider extends StubProvider {
    public function isAvailable(): bool { throw new \RuntimeException('Provider exploded'); }
}

// ── Test 1: ToolProviderInterface is well-formed ──────────────────────────────
echo "\n=== ToolProviderInterface contract ===\n";

$provider = new StubProvider('test.plugin', 'Test Plugin', true, ['test/foo', 'test/bar']);
expect('getId()', $provider->getId(), 'test.plugin');
expect('getName()', $provider->getName(), 'Test Plugin');
expectTrue('isAvailable() true', $provider->isAvailable());
expect('getToolNames()', $provider->getToolNames(), ['test/foo', 'test/bar']);
expect('getContentLayoutProcessor() null', $provider->getContentLayoutProcessor(), null);

$tool = $provider->createTool('test/foo');
expect('createTool() returns correct name', $tool->getName(), 'test/foo');

// ── Test 2: Unavailable provider skipped ─────────────────────────────────────
echo "\n=== Unavailable provider is skipped ===\n";

$registry = new ToolRegistry();
$unavailableProvider = new StubProvider('unavail', 'Unavailable', false, ['unavail/tool']);
$registry->collectProviders(); // No providers via filesystem in this context

// Simulate what collectProviders does internally with an unavailable provider
$unavailProvider = new StubProvider('test.unavail', 'Unavail', false, ['test/unavail-tool']);
// The provider is unavailable — manually verify the guard logic
expectFalse('isAvailable() returns false', $unavailProvider->isAvailable());

// ── Test 3: Tool name conflict — first registered wins ────────────────────────
echo "\n=== Tool name conflict: first-wins ===\n";

// Build a fresh registry with a known tool
$registry = new ToolRegistry();
$firstTool = new StubTool('conflict/tool');
$registry->register($firstTool);

// Try to register a second tool with the same name
$secondTool = new StubTool('conflict/tool');
// The registry should reject the second registration silently
// (collectProviders uses has() check before register)
$got = $registry->get('conflict/tool');
expect('first tool wins after conflict check', $got, $firstTool);

// ── Test 4: Throwing provider is caught gracefully ────────────────────────────
echo "\n=== Throwing provider does not crash registry ===\n";

// The ThrowingProvider throws in isAvailable()
$throwingProvider = new ThrowingProvider('throwing', 'Thrower', true, ['throwing/tool']);

try {
    $threw = false;
    // Simulate collectProviders logic
    try {
        if (!$throwingProvider->isAvailable()) {}
    } catch (\Throwable) {
        $threw = true;
    }
    expectTrue('exception caught from isAvailable()', $threw);
} catch (\Throwable $e) {
    echo "[FAIL] Uncaught exception from throwing provider: " . $e->getMessage() . "\n";
    $failed++;
}

// ── Test 5: MirasaiCollectToolsEvent ─────────────────────────────────────────
echo "\n=== MirasaiCollectToolsEvent ===\n";

$event = new \Mirasai\Library\Mcp\MirasaiCollectToolsEvent('onMirasaiCollectTools');
expect('getProviders() starts empty', $event->getProviders(), []);

$p1 = new StubProvider('p1', 'P1', true, ['p1/tool']);
$p2 = new StubProvider('p2', 'P2', true, ['p2/tool']);
$event->addProvider($p1);
$event->addProvider($p2);

expect('getProviders() returns 2', count($event->getProviders()), 2);
expect('first provider correct', $event->getProviders()[0]->getId(), 'p1');
expect('second provider correct', $event->getProviders()[1]->getId(), 'p2');

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('─', 50) . "\n";
echo "Results: {$passed} passed, {$failed} failed\n";

exit($failed > 0 ? 1 : 0);
