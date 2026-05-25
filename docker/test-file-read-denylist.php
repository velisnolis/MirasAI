<?php
/**
 * Test: file/read denies common secret-bearing files.
 *
 * Run from the repo root:
 *   php docker/test-file-read-denylist.php
 */

declare(strict_types=1);

namespace Joomla\Database {
    interface DatabaseInterface {}
}

namespace Joomla\CMS {
    final class Factory
    {
        public static function getContainer(): object
        {
            return new class {
                public function get(string $id): object
                {
                    return new class implements \Joomla\Database\DatabaseInterface {};
                }
            };
        }
    }
}

namespace {
    $root = sys_get_temp_dir() . '/mirasai_file_read_' . bin2hex(random_bytes(4));

    if (!mkdir($root, 0777, true) && !is_dir($root)) {
        throw new RuntimeException('Failed to create temp root: ' . $root);
    }

    $root = realpath($root);

    if ($root === false) {
        throw new RuntimeException('Failed to resolve temp root.');
    }

    define('JPATH_ROOT', $root);

    $libSrc = dirname(__DIR__) . '/pkg_mirasai/packages/lib_mirasai/src';

    require_once $libSrc . '/Tool/ToolInterface.php';
    require_once $libSrc . '/Tool/AbstractTool.php';
    require_once $libSrc . '/Sandbox/PathValidator.php';
    require_once $libSrc . '/Tool/FileReadTool.php';

    use Mirasai\Library\Sandbox\PathValidator;
    use Mirasai\Library\Tool\FileReadTool;

    $passed = 0;
    $failed = 0;

    function expectFileRead(string $label, mixed $actual, mixed $expected): void
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

    function rrmdir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . '/' . $item;

            if (is_dir($child)) {
                rrmdir($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }

    try {
        mkdir($root . '/language/en-GB', 0777, true);
        mkdir($root . '/templates/yootheme', 0777, true);
        mkdir($root . '/certs', 0777, true);
        mkdir($root . '/.ssh', 0777, true);

        file_put_contents($root . '/language/en-GB/en-GB.ini', 'COM_EXAMPLE="Example"');
        file_put_contents($root . '/templates/yootheme/config.php', '<?php return [];');
        file_put_contents($root . '/configuration.php', '<?php public $password = "secret";');
        file_put_contents($root . '/configuration.php.bak', '<?php public $password = "secret";');
        file_put_contents($root . '/configuration.old.php', '<?php public $password = "secret";');
        file_put_contents($root . '/configuration.origin-before-ub-2026-05-04.php', '<?php public $password = "secret";');
        file_put_contents($root . '/db-backup.sql', 'CREATE TABLE secrets;');
        file_put_contents($root . '/db-backup.sql.gz', 'compressed dump');
        file_put_contents($root . '/.env.local', 'DB_PASSWORD=secret');
        file_put_contents($root . '/certs/private.pem', 'private key');
        file_put_contents($root . '/.ssh/id_ed25519', 'private key');

        $tool = new FileReadTool(new PathValidator(abspath: $root));

        $allowedIni = $tool->handle(['path' => 'language/en-GB/en-GB.ini']);
        expectFileRead('language file is readable', $allowedIni['content'] ?? null, 'COM_EXAMPLE="Example"');

        $allowedTemplateConfig = $tool->handle(['path' => 'templates/yootheme/config.php']);
        expectFileRead('non-root config.php is still readable', $allowedTemplateConfig['encoding'] ?? null, 'utf-8');

        $blockedConfiguration = $tool->handle(['path' => 'configuration.php']);
        expectFileRead('configuration.php is blocked', str_starts_with($blockedConfiguration['error'] ?? '', 'Read access denied'), true);

        $blockedConfigurationBak = $tool->handle(['path' => 'configuration.php.bak']);
        expectFileRead('configuration.php.bak is blocked', str_starts_with($blockedConfigurationBak['error'] ?? '', 'Read access denied'), true);

        $blockedConfigurationOld = $tool->handle(['path' => 'configuration.old.php']);
        expectFileRead('configuration.old.php is blocked', str_starts_with($blockedConfigurationOld['error'] ?? '', 'Read access denied'), true);

        $blockedConfigurationMigration = $tool->handle(['path' => 'configuration.origin-before-ub-2026-05-04.php']);
        expectFileRead('migration configuration copy is blocked', str_starts_with($blockedConfigurationMigration['error'] ?? '', 'Read access denied'), true);

        $blockedSql = $tool->handle(['path' => 'db-backup.sql']);
        expectFileRead('SQL dumps are blocked', str_starts_with($blockedSql['error'] ?? '', 'Read access denied'), true);

        $blockedCompressedSql = $tool->handle(['path' => 'db-backup.sql.gz']);
        expectFileRead('compressed SQL dumps are blocked', str_starts_with($blockedCompressedSql['error'] ?? '', 'Read access denied'), true);

        $blockedEnv = $tool->handle(['path' => '.env.local']);
        expectFileRead('.env variants are blocked', str_starts_with($blockedEnv['error'] ?? '', 'Read access denied'), true);

        $blockedPem = $tool->handle(['path' => 'certs/private.pem']);
        expectFileRead('private key extensions are blocked', str_starts_with($blockedPem['error'] ?? '', 'Read access denied'), true);

        $blockedSsh = $tool->handle(['path' => '.ssh/id_ed25519']);
        expectFileRead('.ssh paths are blocked', str_starts_with($blockedSsh['error'] ?? '', 'Read access denied'), true);
    } finally {
        rrmdir($root);
    }

    if ($failed > 0) {
        echo "\n{$failed} file/read denylist test(s) failed.\n";
        exit(1);
    }

    echo "\nAll {$passed} file/read denylist tests passed.\n";
}
