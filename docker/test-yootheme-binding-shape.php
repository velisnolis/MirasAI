<?php
/**
 * Tests that MirasAI reads and writes the same binding shape as the Builder.
 *
 * The Builder stores a query as one dotted name with its arguments attached to
 * the query itself. MirasAI used to store the name and the field in separate
 * keys with the arguments nested under `query.field`. Two consequences, both
 * verified against the Indústria Viva front page on 2 August 2026:
 *
 *  - reading a Builder-made binding reported `query_arguments: []` even though
 *    the query carried five, and those are the arguments that are hardest to
 *    get right;
 *  - a page could end up holding both shapes for the same query.
 *
 * The fixtures below are the real nodes from that page.
 *
 * Run from the repo root:
 *   php docker/test-yootheme-binding-shape.php
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

require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/TemplateElementSourceSupportTrait.php';

/**
 * The trait carries both halves of the contract under test.
 */
final class BindingShapeProbe
{
    use \Mirasai\WordPress\Tool\TemplateElementSourceSupportTrait;

    /** @param array<string, mixed> $node */
    public function read(array $node): array
    {
        return $this->summarizeBinding($node);
    }

    /** @param array<string, mixed> $arguments */
    public function write(array $arguments): array
    {
        return $this->buildSourceFromShorthand($arguments);
    }
}

$probe = new BindingShapeProbe();

// Exactly as the Builder wrote it on the front page.
$builderNode = [
    'type' => 'text',
    'source' => [
        'query' => [
            'name' => 'ivCurss.customIvCurss',
            'arguments' => [
                'terms' => [2],
                'iv_ambit_include_children' => 'include',
                'date_column' => 'field:iv_data_inici',
                'order_direction' => 'ASC',
                'limit' => 99,
            ],
        ],
        'props' => [
            '_condition' => [
                'arguments' => [],
                'name' => '#index',
                'filters' => ['condition' => '!!', 'show_empty' => true],
            ],
        ],
    ],
];

$read = $probe->read($builderNode);

check('a Builder binding is recognised', $read['has_binding'] ?? null, true);
check('its dotted name is reported whole', $read['source_name'] ?? null, 'ivCurss.customIvCurss');
check('its shape is named', $read['query_shape'] ?? null, 'dotted');
check('the query path is ready to pass to source-types', $read['query_path'] ?? null, 'ivCurss.customIvCurss');

// The regression this test exists for.
check(
    'query arguments on the query itself are reported',
    $read['query_arguments'] ?? null,
    [
        'terms' => [2],
        'iv_ambit_include_children' => 'include',
        'date_column' => 'field:iv_data_inici',
        'order_direction' => 'ASC',
        'limit' => 99,
    ]
);

// The native visibility condition is a prop mapping like any other. Nothing
// special is needed to read it, but nothing said so either.
$condition = null;

foreach ($read['field_mappings'] ?? [] as $mapping) {
    if (($mapping['prop'] ?? '') === '_condition') {
        $condition = $mapping;
    }
}

check('the visibility condition reads back', $condition['field'] ?? null, '#index');
check(
    'the condition keeps its filters',
    $condition['filters'] ?? null,
    ['condition' => '!!', 'show_empty' => true]
);

// The older nested shape must keep reading correctly.
$nestedNode = [
    'type' => 'text',
    'source' => [
        'query' => [
            'name' => 'ivCurss',
            'field' => ['name' => 'customIvCurss', 'arguments' => ['limit' => 5]],
        ],
        'props' => ['content' => ['name' => 'title']],
    ],
];

$readNested = $probe->read($nestedNode);

check('the nested shape is still recognised', $readNested['query_shape'] ?? null, 'nested');
check('its two halves join into one path', $readNested['query_path'] ?? null, 'ivCurss.customIvCurss');
check('its nested arguments are still reported', $readNested['query_arguments'] ?? null, ['limit' => 5]);

// Writing must now produce what the Builder produces.
$written = $probe->write([
    'source_name' => 'ivCurss',
    'query_field' => 'customIvCurss',
    'query_arguments' => ['terms' => [2], 'iv_ambit_include_children' => 'include'],
    'field_mappings' => [
        'content' => 'title',
        '_condition' => ['name' => '#index', 'filters' => ['condition' => '!!', 'show_empty' => true]],
    ],
]);

check('the written query is one dotted name', $written['query']['name'] ?? null, 'ivCurss.customIvCurss');
check('the field is not split into its own key', isset($written['query']['field']), false);
check(
    'the arguments sit on the query, as the Builder puts them',
    $written['query']['arguments'] ?? null,
    ['terms' => [2], 'iv_ambit_include_children' => 'include']
);
check(
    'a visibility condition can be written as a plain mapping',
    $written['props']['_condition'] ?? null,
    ['name' => '#index', 'filters' => ['condition' => '!!', 'show_empty' => true]]
);

// A caller who already has the dotted path should not have to take it apart.
$fromPath = $probe->write([
    'source_name' => 'ivCurss.customIvCurss',
    'field_mappings' => ['content' => 'title'],
]);
check('a dotted source_name is written unchanged', $fromPath['query']['name'] ?? null, 'ivCurss.customIvCurss');

// What MirasAI writes must read back as what MirasAI wrote.
$roundTrip = $probe->read(['type' => 'text', 'source' => $written]);
check('a written binding round-trips its path', $roundTrip['query_path'] ?? null, 'ivCurss.customIvCurss');
check(
    'a written binding round-trips its arguments',
    $roundTrip['query_arguments'] ?? null,
    ['terms' => [2], 'iv_ambit_include_children' => 'include']
);
check('a written binding reads as the Builder shape', $roundTrip['query_shape'] ?? null, 'dotted');

// Both hosts must agree.
$joomla = (string) file_get_contents(
    dirname(__DIR__) . '/packages/mirasai-joomla/packages/plg_mirasai_yootheme/src/Tool/TemplateElementSourceSupportTrait.php'
);
check('the Joomla twin reports the query shape', str_contains($joomla, "'query_shape'"), true);
check(
    'the Joomla twin writes the dotted form',
    str_contains($joomla, '$sourceName . \'.\' . $queryField'),
    true
);

if ($failed > 0) {
    echo "\n{$failed} binding shape test(s) failed.\n";
    exit(1);
}

echo "\nAll {$passed} binding shape tests passed.\n";
