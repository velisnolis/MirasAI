<?php
/**
 * Tests for addressing a YOOtheme dynamic source by path.
 *
 * The arguments of a source query were always in `template/source-types`. What
 * was missing was any way to get to them: a binding stores the source as
 * `source_name` plus `query_field`, so the name people reach for is a segment
 * rather than a path, `source_name: "customIvCurss"` failed to resolve, and the
 * failure was reported as a footnote inside an otherwise successful response
 * carrying all 47 types. That is how "there is no tool for this" became true
 * about a tool that answered the question.
 *
 * The schema below mirrors the shape of live introspection on Indústria Viva.
 *
 * Run from the repo root:
 *   php docker/test-yootheme-source-paths.php
 */

declare(strict_types=1);

$passed = 0;
$failed = 0;

function check(string $label, mixed $actual, mixed $expected): void
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

$root = dirname(__DIR__);

// The WordPress tool is the one under test; the Joomla twin is compared for
// structural drift rather than instantiated, since it needs Joomla's database.
require_once $root . '/packages/mirasai-wp/src/Tool/ToolInterface.php';
require_once $root . '/packages/mirasai-wp/src/Tool/AbstractTool.php';
require_once $root . '/packages/mirasai-wp/src/Tool/TemplateSourceTypesTool.php';

/**
 * The two helpers under test are private, and deliberately so: they are
 * implementation of the tool's contract, not API. Reflection reaches them
 * without widening that contract just to make it testable.
 */
function callPrivate(object $instance, string $method, mixed ...$args): mixed
{
    return (new \ReflectionMethod($instance, $method))->invoke($instance, ...$args);
}

$field = static fn (string $name, string $typeName, string $kind = 'OBJECT', bool $list = false): array => [
    'name' => $name,
    'type' => $list
        ? ['kind' => 'LIST', 'ofType' => ['kind' => $kind, 'name' => $typeName]]
        : ['kind' => $kind, 'name' => $typeName],
    'args' => [],
];

$typeMap = [
    'Query' => ['name' => 'Query', 'fields' => [
        $field('posts', 'PostsQuery'),
        $field('ivCurss', 'IvCurssQuery'),
        $field('ivOfertas', 'IvOfertasQuery'),
    ]],
    'IvCurssQuery' => ['name' => 'IvCurssQuery', 'fields' => [
        [
            'name' => 'customIvCurss',
            'type' => ['kind' => 'LIST', 'ofType' => ['kind' => 'OBJECT', 'name' => 'IvCurs']],
            'args' => [
                ['name' => 'terms', 'type' => ['kind' => 'LIST', 'ofType' => ['kind' => 'SCALAR', 'name' => 'Int']]],
                ['name' => 'iv_ambit_include_children', 'type' => ['kind' => 'SCALAR', 'name' => 'String']],
            ],
        ],
        $field('singleIvCurs', 'IvCurs'),
    ]],
    'IvOfertasQuery' => ['name' => 'IvOfertasQuery', 'fields' => [
        $field('customIvOfertas', 'IvOferta', 'OBJECT', true),
    ]],
    'IvCurs' => ['name' => 'IvCurs', 'fields' => [
        [
            'name' => 'iv_ambitString',
            'type' => ['kind' => 'SCALAR', 'name' => 'String'],
            'args' => [
                ['name' => 'show_link', 'type' => ['kind' => 'SCALAR', 'name' => 'Boolean']],
                ['name' => 'separator', 'type' => ['kind' => 'SCALAR', 'name' => 'String']],
            ],
        ],
    ]],
    'IvOferta' => ['name' => 'IvOferta', 'fields' => []],
    'PostsQuery' => ['name' => 'PostsQuery', 'fields' => []],
];

$probe = new \Mirasai\WordPress\Tool\TemplateSourceTypesTool();

// The exact name from the backlog. It is a query_field, not a path.
check(
    'the bare query_field is resolved to its full path',
    callPrivate($probe, 'suggestSourcePaths', 'customIvCurss', 'Query', $typeMap),
    ['ivCurss.customIvCurss']
);

check(
    'a field name reachable by several routes reports each of them',
    callPrivate($probe, 'suggestSourcePaths', 'iv_ambitString', 'Query', $typeMap),
    ['ivCurss.customIvCurss.iv_ambitString', 'ivCurss.singleIvCurs.iv_ambitString']
);

check(
    'a name that exists nowhere gets no invented suggestion',
    callPrivate($probe, 'suggestSourcePaths', 'nonexistentThing', 'Query', $typeMap),
    []
);

check('an empty name suggests nothing', callPrivate($probe, 'suggestSourcePaths', '', 'Query', $typeMap), []);

// Resolution itself, which was always correct once given a real path.
$hints = callPrivate($probe, 'resolveSourceBindingHints', 'ivCurss.customIvCurss', 'Query', $typeMap);

check('a full path resolves', isset($hints['error']), false);
check('the resolved query reports its result type', $hints['query']['result_type'] ?? null, 'IvCurs');
check('a list result is reported as multiple', $hints['query']['is_multiple'] ?? null, true);
check(
    'the query arguments carry their GraphQL types',
    array_column($hints['query']['args'] ?? [], 'type', 'name'),
    ['terms' => '[Int]', 'iv_ambit_include_children' => 'String']
);

// The recurrence: the argument that broke a table lived on a *field*, not on
// the entry query, and reading PostType.php was the only way to find it.
$fieldArgs = [];

foreach ($hints['mappable_fields'] ?? [] as $mappable) {
    if (($mappable['name'] ?? '') === 'iv_ambitString') {
        $fieldArgs = array_column($mappable['args'] ?? [], 'type', 'name');
    }
}

check(
    'result field arguments are reported too',
    $fieldArgs,
    ['show_link' => 'Boolean', 'separator' => 'String']
);

$missing = callPrivate($probe, 'resolveSourceBindingHints', 'ivCurss.noSuchField', 'Query', $typeMap);
check('an unresolvable segment is an error', $missing['code'] ?? null, 'source_field_not_found');
check(
    'the error names what the type does offer',
    in_array('customIvCurss', $missing['available_fields'] ?? [], true),
    true
);

// Both hosts must answer the same way.
$joomla = file_get_contents($root . '/packages/mirasai-joomla/packages/plg_mirasai_yootheme/src/Tool/TemplateSourceTypesTool.php');
check(
    'the Joomla twin also suggests source paths',
    str_contains((string) $joomla, 'private function suggestSourcePaths'),
    true
);
check(
    'the Joomla twin also refuses to fall back on a caller error',
    str_contains((string) $joomla, '$runtimeFailures'),
    true
);

if ($failed > 0) {
    echo "\n{$failed} source path test(s) failed.\n";
    exit(1);
}

echo "\nAll {$passed} source path tests passed.\n";
