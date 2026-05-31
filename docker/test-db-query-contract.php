<?php
/**
 * Test: db/query remains observational on Joomla and WordPress by blocking
 * read-looking assignment forms such as SELECT ... INTO and :=.
 *
 * Run from the repo root:
 *   php docker/test-db-query-contract.php
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
    final class FakeJoomlaDatabase implements \Joomla\Database\DatabaseInterface
    {
        public function getPrefix(): string
        {
            return 'jos_';
        }
    }

    require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/ToolInterface.php';
    require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/AbstractTool.php';
    require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/DbQueryTool.php';

    require_once dirname(__DIR__) . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/ToolInterface.php';
    require_once dirname(__DIR__) . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/AbstractTool.php';
    require_once dirname(__DIR__) . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/DbQueryTool.php';

    use Joomla\CMS\Factory;
    use Joomla\CMS\FakeContainer;
    use Mirasai\Library\Tool\DbQueryTool as JoomlaDbQueryTool;
    use Mirasai\WordPress\Tool\DbQueryTool as WordPressDbQueryTool;

    $passed = 0;
    $failed = 0;

    function expectDbQuery(string $label, bool $condition): void
    {
        global $passed, $failed;

        if ($condition) {
            echo "[PASS] {$label}\n";
            $passed++;

            return;
        }

        echo "[FAIL] {$label}\n";
        $failed++;
    }

    function validationError(object $tool, string $sql): ?string
    {
        $method = new \ReflectionMethod($tool, 'validateQuery');
        $result = $method->invoke($tool, $sql);

        return is_string($result) ? $result : null;
    }

    Factory::$container = new FakeContainer(new FakeJoomlaDatabase());

    $tools = [
        'WordPress' => new WordPressDbQueryTool(),
        'Joomla' => new JoomlaDbQueryTool(),
    ];

    foreach ($tools as $platform => $tool) {
        expectDbQuery("{$platform} allows plain SELECT", validationError($tool, 'SELECT id FROM #__content') === null);
        expectDbQuery("{$platform} blocks SELECT INTO variables", validationError($tool, 'SELECT id INTO @x FROM #__content') !== null);
        expectDbQuery("{$platform} blocks := assignment", validationError($tool, 'SELECT @x := id FROM #__content') !== null);
    }

    if ($failed > 0) {
        echo "\n{$failed} db/query contract test(s) failed.\n";
        exit(1);
    }

    echo "\nAll {$passed} db/query contract tests passed.\n";
}
