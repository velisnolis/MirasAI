<?php
/**
 * Form tests for the batch (leaves) form of template/element-source-set.
 *
 * Run from the repo root:
 *   php docker/test-yootheme-source-batch.php
 */

declare(strict_types=1);

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text, bool $remove_breaks = false): string
    {
        $stripped = preg_replace('/\s+/', ' ', strip_tags($text)) ?? '';

        return trim($stripped);
    }
}

require_once dirname(__DIR__) . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/YooThemeElementNavigator.php';
require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/YoothemeElementNavigator.php';
require_once dirname(__DIR__) . '/packages/mirasai-joomla/packages/plg_mirasai_yootheme/src/Tool/TemplateElementSourceSupportTrait.php';
require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/TemplateElementSourceSupportTrait.php';

use Mirasai\Library\Tool\YooThemeElementNavigator;
use Mirasai\WordPress\Tool\YoothemeElementNavigator as WpYoothemeElementNavigator;

$passed = 0;
$failed = 0;

function expectBatch(string $label, mixed $actual, mixed $expected): void
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

$batch = new class {
    use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateElementSourceSupportTrait;

    /**
     * @param array<string, mixed> $layout
     * @param list<mixed> $leaves
     * @return array<string, mixed>
     */
    public function run(object $navigator, array $layout, array $leaves, bool $rebindDisabled = false): array
    {
        return $this->applyLeafBatch($navigator, $layout, $leaves, $rebindDisabled);
    }

    /**
     * @param array<string, mixed> $layout
     * @return list<array<string, mixed>>
     */
    public function rows(object $navigator, array $layout): array
    {
        return $this->bindingsOnlyFromLayout($navigator, $layout);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return $this->leafBatchInputProperties();
    }
};

$wpBatch = new class {
    use Mirasai\WordPress\Tool\TemplateElementSourceSupportTrait;

    /**
     * @param array<string, mixed> $layout
     * @param list<mixed> $leaves
     * @return array<string, mixed>
     */
    public function run(object $navigator, array $layout, array $leaves, bool $rebindDisabled = false): array
    {
        return $this->applyLeafBatch($navigator, $layout, $leaves, $rebindDisabled);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return $this->leafBatchInputProperties();
    }
};

// Indústria Viva in section[0]: a list bound by query arguments plus the empty
// visibility text that was forgotten on 18/08. BIT Vic in section[1]: a
// disabled row still holding last edition's gallery source as a placeholder.
$layout = [
    'type' => 'layout',
    'children' => [
        [
            'type' => 'section',
            'props' => ['title' => 'Cursos'],
            'children' => [[
                'type' => 'row',
                'children' => [[
                    'type' => 'column',
                    'children' => [
                        [
                            'type' => 'fs_grid',
                            'children' => [[
                                'type' => 'fs_grid_item',
                                'source' => [
                                    'query' => ['name' => 'ivCurss', 'field' => ['name' => 'customIvCurss', 'arguments' => ['terms' => [7]]]],
                                    'props' => ['title' => ['name' => 'title'], 'meta' => ['name' => 'iv_ambitString']],
                                ],
                            ]],
                        ],
                        [
                            'type' => 'text',
                            'props' => ['content' => ''],
                            'source' => [
                                'query' => ['name' => 'ivCurss', 'field' => ['name' => 'customIvCurss', 'arguments' => ['terms' => [7]]]],
                                'props' => ['_condition' => ['name' => '#index', 'filters' => ['condition' => '!!']]],
                            ],
                        ],
                        ['type' => 'headline', 'props' => ['content' => 'no binding']],
                    ],
                ]],
            ]],
        ],
        [
            'type' => 'section',
            'props' => ['title' => 'Arxiu'],
            'children' => [
                [
                    'type' => 'row',
                    'props' => ['id' => 'visual', 'status' => 'disabled'],
                    'children' => [[
                        'type' => 'column',
                        'children' => [[
                            'type' => 'gallery',
                            'source' => [
                                'query' => ['name' => 'docmansource.edicio-2024-vic-vt'],
                                'props' => ['images' => ['name' => 'files']],
                            ],
                        ]],
                    ]],
                ],
                [
                    'type' => 'row',
                    'children' => [[
                        'type' => 'column',
                        'children' => [[
                            'type' => 'fragment',
                            'source' => [
                                'query' => ['name' => 'docmansource.edicio-2025-vic-pres'],
                                'props' => ['content' => ['name' => 'body']],
                            ],
                        ]],
                    ]],
                ],
            ],
        ],
    ],
];

$GRID = 'root>section[0]>row[0]>column[0]>fs_grid[0]>fs_grid_item[0]';
$TEXT = 'root>section[0]>row[0]>column[0]>text[1]';
$HEADLINE = 'root>section[0]>row[0]>column[0]>headline[2]';
$GALLERY = 'root>section[1]>row[0]>column[0]>gallery[0]';
$FRAGMENT = 'root>section[1]>row[1]>column[0]>fragment[0]';

$joomla = new YooThemeElementNavigator();
$wp = new WpYoothemeElementNavigator();

/**
 * @param list<array<string, mixed>> $leaves
 * @return array<string, array<string, mixed>>
 */
$byPath = static function (array $leaves): array {
    $indexed = [];

    foreach ($leaves as $leaf) {
        $indexed[$leaf['path']] = $leaf;
    }

    return $indexed;
};

// The Indústria Viva rebind, this time with the visibility leaf in the map.
$result = $batch->run($joomla, $layout, [
    ['match' => ['path' => $GRID], 'set' => ['query_arguments' => ['terms' => [9]]]],
    ['match' => ['path' => $TEXT], 'set' => ['keep' => true]],
]);

expectBatch('batch succeeds', isset($result['error']), false);

$report = $byPath($result['leaves']);
$after = $byPath(array_map(
    static fn (array $row): array => $row + ['binding' => $row['binding']],
    $batch->rows($joomla, $result['layout'])
));

expectBatch('report covers every bound node', count($result['leaves']), 4);
expectBatch('the named list is rebound', $report[$GRID]['state'], 'rebound');
expectBatch('the asserted leaf is kept', $report[$TEXT]['state'], 'kept');
expectBatch('a binding nobody named is visible as untouched', $report[$FRAGMENT]['state'], 'untouched');
expectBatch('a placeholder under a disabled row is skipped', $report[$GALLERY]['state'], 'skipped_disabled');
expectBatch('the skipped row names its disabled ancestor', $report[$GALLERY]['disabled_by'], 'root>section[1]>row[0]');
expectBatch('rebound arguments land where the reader looks', $after[$GRID]['binding']['query_arguments'], ['terms' => [9]]);
expectBatch('a kept leaf is not touched', $after[$TEXT]['binding']['query_arguments'], ['terms' => [7]]);
expectBatch(
    'rebinding arguments keeps the field mappings',
    array_column($after[$GRID]['binding']['field_mappings'], 'prop'),
    ['title', 'meta']
);
expectBatch('only the rebound row reports before/after', array_key_exists('before', $report[$TEXT]), false);
expectBatch('the rebound row reports before', $report[$GRID]['before']['query_arguments'], ['terms' => [7]]);
expectBatch('before/after never carries the raw payload', array_key_exists('raw_source', $report[$GRID]['after']), false);
expectBatch('hosts agree on the batch result', $result, $wpBatch->run($wp, $layout, [
    ['match' => ['path' => $GRID], 'set' => ['query_arguments' => ['terms' => [9]]]],
    ['match' => ['path' => $TEXT], 'set' => ['keep' => true]],
]));

// Fail-closed: one bad entry refuses the whole set.
$unmatched = $batch->run($joomla, $layout, [
    ['match' => ['path' => $GRID], 'set' => ['query_arguments' => ['terms' => [9]]]],
    ['match' => ['path' => 'root>section[0]>row[9]'], 'set' => ['keep' => true]],
]);

expectBatch('an unmatched leaf refuses the batch', $unmatched['code'] ?? null, 'leaf_unmatched');
expectBatch('nothing is written when a leaf fails', array_key_exists('layout', $unmatched), false);

expectBatch(
    'two leaves on one node conflict',
    $batch->run($joomla, $layout, [
        ['match' => ['path' => $GRID], 'set' => ['query_arguments' => ['terms' => [9]]]],
        ['match' => ['path' => $GRID], 'set' => ['keep' => true]],
    ])['code'] ?? null,
    'leaf_conflict'
);

expectBatch(
    'a node with no binding is refused',
    $batch->run($joomla, $layout, [
        ['match' => ['path' => $HEADLINE], 'set' => ['keep' => true]],
    ])['code'] ?? null,
    'leaf_no_binding'
);

expectBatch(
    'a leaf that changes nothing is refused',
    $batch->run($joomla, $layout, [
        ['match' => ['path' => $GRID], 'set' => []],
    ])['code'] ?? null,
    'invalid_leaf'
);

expectBatch(
    'a match with neither path nor query_path is refused',
    $batch->run($joomla, $layout, [
        ['match' => [], 'set' => ['keep' => true]],
    ])['code'] ?? null,
    'invalid_leaf'
);

expectBatch('an empty batch is refused', $batch->run($joomla, $layout, [])['code'] ?? null, 'invalid_leaves');

expectBatch(
    'keep alongside a change is refused rather than silently kept',
    $batch->run($joomla, $layout, [
        ['match' => ['path' => $GRID], 'set' => ['keep' => true, 'query_arguments' => ['terms' => [9]]]],
    ])['code'] ?? null,
    'invalid_leaf'
);

// query_path as a matcher, and its ambiguity guard.
$viaQueryPath = $batch->run($joomla, $layout, [
    ['match' => ['query_path' => 'docmansource.edicio-2025-vic-pres'], 'set' => ['source_name' => 'docmansource.edicio-2026-vic-pres']],
]);
$afterQueryPath = $byPath($batch->rows($joomla, $viaQueryPath['layout']));

expectBatch('query_path resolves a unique binding', $byPath($viaQueryPath['leaves'])[$FRAGMENT]['state'], 'rebound');
expectBatch(
    'source_name repoints the query',
    $afterQueryPath[$FRAGMENT]['binding']['query_path'],
    'docmansource.edicio-2026-vic-pres'
);
expectBatch(
    'repointing keeps the field mappings',
    array_column($afterQueryPath[$FRAGMENT]['binding']['field_mappings'], 'prop'),
    ['content']
);
expectBatch(
    'an ambiguous query_path is refused',
    $batch->run($joomla, $layout, [
        ['match' => ['query_path' => 'ivCurss.customIvCurss'], 'set' => ['keep' => true]],
    ])['code'] ?? null,
    'leaf_unmatched'
);
expectBatch(
    'path and query_path that disagree are refused',
    $batch->run($joomla, $layout, [
        ['match' => ['path' => $GRID, 'query_path' => 'docmansource.edicio-2024-vic-vt'], 'set' => ['keep' => true]],
    ])['code'] ?? null,
    'leaf_unmatched'
);

// Disabled placeholders are protected unless the caller insists.
expectBatch(
    'rebinding a disabled placeholder is blocked',
    $batch->run($joomla, $layout, [
        ['match' => ['path' => $GALLERY], 'set' => ['source_name' => 'docmansource.edicio-2026-vic-vt']],
    ])['code'] ?? null,
    'rebind_disabled_blocked'
);

$forced = $batch->run($joomla, $layout, [
    ['match' => ['path' => $GALLERY], 'set' => ['source_name' => 'docmansource.edicio-2026-vic-vt']],
], true);

expectBatch('rebind_disabled lets it through', $byPath($forced['leaves'])[$GALLERY]['state'], 'rebound');
expectBatch(
    'the forced rebind still reports the disabled ancestor',
    $byPath($forced['leaves'])[$GALLERY]['disabled_by'],
    'root>section[1]>row[0]'
);
expectBatch(
    'asserting a disabled leaf needs no override',
    $batch->run($joomla, $layout, [
        ['match' => ['path' => $GALLERY], 'set' => ['keep' => true]],
    ])['leaves'] !== [],
    true
);

// Field mappings merge rather than replace wholesale.
$merged = $batch->run($joomla, $layout, [
    ['match' => ['path' => $GRID], 'set' => ['field_mappings' => ['meta' => 'iv_municipiString']]],
]);
$mergedBinding = $byPath($batch->rows($joomla, $merged['layout']))[$GRID]['binding'];

expectBatch(
    'setting one mapping keeps the others',
    array_column($mergedBinding['field_mappings'], 'prop'),
    ['title', 'meta']
);
expectBatch(
    'the named mapping is the one that moved',
    array_column($mergedBinding['field_mappings'], 'field'),
    ['title', 'iv_municipiString']
);
expectBatch('merging mappings leaves the query alone', $mergedBinding['query_arguments'], ['terms' => [7]]);

// A dotted binding keeps its arguments on the query itself.
$dotted = $batch->run($joomla, $layout, [
    ['match' => ['path' => $FRAGMENT], 'set' => ['query_arguments' => ['limit' => 5]]],
]);

expectBatch(
    'a dotted binding takes arguments on the query',
    $byPath($batch->rows($joomla, $dotted['layout']))[$FRAGMENT]['binding']['query_arguments'],
    ['limit' => 5]
);

// The schema helper has to stand on the trait alone. It once borrowed
// field_mappings from sourceInputProperties(), which only WordPress defines, and
// every Joomla call to this tool became a 500 that no unit test saw.
$joomlaSchema = $batch->schema();
$wpSchema = $wpBatch->schema();

expectBatch('the batch schema needs nothing outside the trait', array_keys($joomlaSchema), ['leaves', 'rebind_disabled']);
expectBatch('both hosts publish the same batch schema', $joomlaSchema, $wpSchema);
expectBatch(
    'a leaf declares match and set',
    array_keys($joomlaSchema['leaves']['items']['properties']),
    ['match', 'set']
);
expectBatch(
    'set declares every operation the batch applies',
    array_keys($joomlaSchema['leaves']['items']['properties']['set']['properties']),
    ['keep', 'source_name', 'query_arguments', 'field_mappings', 'source']
);

if ($failed > 0) {
    echo "\n{$failed} source batch test(s) failed.\n";
    exit(1);
}

echo "\nAll {$passed} source batch tests passed.\n";
