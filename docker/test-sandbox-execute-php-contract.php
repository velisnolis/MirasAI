<?php
/**
 * Test: sandbox/execute-php requires explicit confirmation and still executes
 * confirmed code through the transaction wrapper.
 *
 * Run from the repo root:
 *   php docker/test-sandbox-execute-php-contract.php
 */

declare(strict_types=1);

namespace Joomla\Database {
    interface DatabaseInterface {}
}

namespace Joomla\CMS {
    final class Factory
    {
        public static FakeContainer $container;

        public static function getContainer(): FakeContainer
        {
            return self::$container;
        }
    }

    final class FakeContainer
    {
        public function __construct(private object $db) {}

        public function get(string $id): object
        {
            return $this->db;
        }
    }
}

namespace {
    final class FakeDatabase implements \Joomla\Database\DatabaseInterface
    {
        public int $starts = 0;
        public int $commits = 0;
        public int $rollbacks = 0;

        public function transactionStart(): void
        {
            $this->starts++;
        }

        public function transactionCommit(): void
        {
            $this->commits++;
        }

        public function transactionRollback(): void
        {
            $this->rollbacks++;
        }
    }

    $libSrc = dirname(__DIR__) . '/packages/mirasai-joomla/packages/lib_mirasai/src';

    require_once $libSrc . '/Tool/ToolInterface.php';
    require_once $libSrc . '/Tool/AbstractTool.php';
    require_once $libSrc . '/Tool/SandboxExecutePhpTool.php';

    use Joomla\CMS\Factory;
    use Joomla\CMS\FakeContainer;
    use Mirasai\Library\Tool\SandboxExecutePhpTool;

    $passed = 0;
    $failed = 0;

    function expectSandbox(string $label, mixed $actual, mixed $expected): void
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

    $db = new FakeDatabase();
    Factory::$container = new FakeContainer($db);

    $tool = new SandboxExecutePhpTool();

    $blocked = $tool->handle(['code' => 'return 42;']);
    expectSandbox('missing confirmation is rejected', $blocked['code'] ?? null, 'execute_php_confirmation_required');
    expectSandbox('missing confirmation does not start transaction', $db->starts, 0);

    $schema = $tool->getInputSchema();
    expectSandbox('confirm flag is required in schema', in_array('confirm_execute_php', $schema['required'] ?? [], true), true);

    $confirmed = $tool->handle([
        'code' => 'return 42;',
        'confirm_execute_php' => true,
    ]);

    expectSandbox('confirmed execution returns value', $confirmed['return_value'] ?? null, 42);
    expectSandbox('confirmed execution starts transaction', $db->starts, 1);
    expectSandbox('confirmed execution commits transaction', $db->commits, 1);
    expectSandbox('confirmed execution does not rollback', $db->rollbacks, 0);

    if ($failed > 0) {
        echo "\n{$failed} sandbox execute-php contract test(s) failed.\n";
        exit(1);
    }

    echo "\nAll {$passed} sandbox execute-php contract tests passed.\n";
}
