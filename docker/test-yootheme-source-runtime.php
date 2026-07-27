<?php

declare(strict_types=1);

namespace YOOtheme {
    function app(?string $service = null): object
    {
        return $GLOBALS['test_yootheme_source_service'];
    }
}

namespace {
    require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/ToolInterface.php';
    require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/AbstractTool.php';
    require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/TemplateSourceTypesTool.php';

    use Mirasai\WordPress\Tool\TemplateSourceTypesTool;

    $GLOBALS['test_yootheme_source_service'] = new class {
        public function queryIntrospection(): object
        {
            return new class {
                public function toArray(): array
                {
                    return [
                        'data' => [
                            '__schema' => [
                                'queryType' => ['name' => 'Query'],
                                'types' => [
                                    [
                                        'kind' => 'OBJECT',
                                        'name' => 'Query',
                                        'fields' => [],
                                    ],
                                ],
                            ],
                        ],
                    ];
                }
            };
        }
    };

    $passed = 0;
    $failed = 0;
    $check = static function (string $label, bool $condition) use (&$passed, &$failed): void {
        if ($condition) {
            echo "[PASS] {$label}\n";
            $passed++;
            return;
        }

        echo "[FAIL] {$label}\n";
        $failed++;
    };

    $check(
        'test fixture deliberately has no autoloadable Builder Source class',
        !class_exists('YOOtheme\\Builder\\Source')
    );

    $tool = new TemplateSourceTypesTool();
    $runtime = new \ReflectionMethod($tool, 'ensureYooThemeSourceRuntime');
    $result = $runtime->invoke($tool);

    $check(
        'source runtime trusts successful container resolution without class_exists',
        is_array($result) && !isset($result['error']) && ($result['attempted'] ?? false) === true
    );

    $live = $tool->handle([]);
    $check(
        'source-types uses live introspection when the container service resolves',
        ($live['mode'] ?? null) === 'live_introspection'
    );

    if ($failed > 0) {
        fwrite(STDERR, "\n{$failed} YOOtheme Source runtime test(s) failed.\n");
        exit(1);
    }

    echo "All {$passed} YOOtheme Source runtime tests passed.\n";
}
