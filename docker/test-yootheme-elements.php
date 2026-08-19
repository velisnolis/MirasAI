<?php
/**
 * Unit tests for YooThemeElementNavigator.
 *
 * Run from the repo root:
 *   php docker/test-yootheme-elements.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/YooThemeElementNavigator.php';

use Mirasai\Library\Tool\YooThemeElementNavigator;

$passed = 0;
$failed = 0;

function expectElement(string $label, mixed $actual, mixed $expected): void
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

$layout = [
    'type' => 'layout',
    'children' => [[
        'type' => 'section',
        'props' => ['title' => 'Hero section'],
        'children' => [[
            'type' => 'row',
            'children' => [[
                'type' => 'column',
                'children' => [
                    [
                        'type' => 'headline',
                        'props' => ['content' => '<strong>Main headline</strong>'],
                    ],
                    [
                        'type' => 'text',
                        'props' => [
                            'content' => 'Dynamic copy',
                            'source' => ['props' => ['content' => 'title']],
                        ],
                    ],
                ],
            ]],
        ]],
    ]],
];

$navigator = new YooThemeElementNavigator();
$elements = $navigator->listElements($layout);

expectElement('element count', count($elements), 6);
expectElement('root path', $elements[0]['path'], 'root');
expectElement('headline path', $elements[4]['path'], 'root>section[0]>row[0]>column[0]>headline[0]');
expectElement('headline label strips html', $elements[4]['label'], 'Main headline');
expectElement('text source binding detected', $elements[5]['has_source_binding'], true);

$found = $navigator->findElement($layout, 'root>section[0]>row[0]>column[0]>headline[0]');
expectElement('findElement returns metadata path', $found['metadata']['path'] ?? null, 'root>section[0]>row[0]>column[0]>headline[0]');
expectElement('findElement returns element type', $found['element']['type'] ?? null, 'headline');
expectElement('missing path returns null', $navigator->findElement($layout, 'root>section[9]'), null);

$updated = $navigator->updateElementProps(
    $layout,
    'root>section[0]>row[0]>column[0]>headline[0]',
    ['content' => 'Updated headline', 'title_element' => 'h2'],
);
expectElement('updateElementProps returns path', $updated['metadata']['path'] ?? null, 'root>section[0]>row[0]>column[0]>headline[0]');
expectElement('updateElementProps merges content', $updated['element']['props']['content'] ?? null, 'Updated headline');
expectElement('updateElementProps merges new prop', $updated['element']['props']['title_element'] ?? null, 'h2');
expectElement('updateElementProps does not mutate original layout', $layout['children'][0]['children'][0]['children'][0]['children'][0]['props']['content'], '<strong>Main headline</strong>');

$replaced = $navigator->updateElementProps(
    $layout,
    'root>section[0]>row[0]>column[0]>headline[0]',
    ['content' => 'Replacement only'],
    false,
);
expectElement('updateElementProps replace removes old keys', array_keys($replaced['element']['props'] ?? []), ['content']);
expectElement('updateElementProps missing path returns null', $navigator->updateElementProps($layout, 'root>section[9]', []), null);

$newSource = [
    'query' => [
        'name' => 'Article',
        'field' => [
            'name' => 'article',
            'arguments' => ['id' => '1'],
        ],
    ],
    'props' => [
        'content' => ['name' => 'introtext'],
    ],
];
$sourceSet = $navigator->setElementSource($layout, 'root>section[0]>row[0]>column[0]>headline[0]', $newSource);
expectElement('setElementSource returns path', $sourceSet['metadata']['path'] ?? null, 'root>section[0]>row[0]>column[0]>headline[0]');
expectElement('setElementSource writes source query', $sourceSet['element']['source']['query']['name'] ?? null, 'Article');
expectElement('setElementSource detects binding', $sourceSet['metadata']['has_source_binding'] ?? null, true);
expectElement('setElementSource does not mutate original layout', isset($layout['children'][0]['children'][0]['children'][0]['children'][0]['props']['source']), false);

$sourceDeleted = $navigator->deleteElementSource($sourceSet['layout'], 'root>section[0]>row[0]>column[0]>headline[0]');
expectElement('deleteElementSource removes source', isset($sourceDeleted['element']['source']), false);
expectElement('deleteElementSource reports location', $sourceDeleted['removed_locations'], ['source']);
expectElement('deleteElementSource no binding remains', $sourceDeleted['metadata']['has_source_binding'], false);

$added = $navigator->addElement(
    $layout,
    'root>section[0]>row[0]>column[0]',
    ['type' => 'image', 'props' => ['src' => '/images/test.jpg']],
);
expectElement('addElement returns new path', $added['metadata']['path'] ?? null, 'root>section[0]>row[0]>column[0]>image[2]');
expectElement('addElement normalizes props', $added['element']['props']['src'] ?? null, '/images/test.jpg');
expectElement('addElement does not mutate original layout', count($layout['children'][0]['children'][0]['children'][0]['children']), 2);

$prepended = $navigator->addElement(
    $layout,
    'root>section[0]>row[0]>column[0]',
    ['type' => 'image'],
    'prepend',
);
expectElement('addElement prepend returns shifted path', $prepended['metadata']['path'] ?? null, 'root>section[0]>row[0]>column[0]>image[0]');

$cloned = $navigator->cloneElement($layout, 'root>section[0]>row[0]>column[0]>headline[0]');
expectElement('cloneElement returns sibling path', $cloned['new_path'] ?? null, 'root>section[0]>row[0]>column[0]>headline[1]');
expectElement('cloneElement keeps element props', $cloned['element']['props']['content'] ?? null, '<strong>Main headline</strong>');

$moved = $navigator->moveElement(
    $layout,
    'root>section[0]>row[0]>column[0]>headline[0]',
    'root>section[0]',
);
expectElement('moveElement returns new path', $moved['new_path'] ?? null, 'root>section[0]>headline[1]');
expectElement('moveElement returns old path', $moved['old_path'] ?? null, 'root>section[0]>row[0]>column[0]>headline[0]');

$deleted = $navigator->deleteElement($layout, 'root>section[0]>row[0]>column[0]>text[1]');
expectElement('deleteElement returns deleted path', $deleted['deleted_path'] ?? null, 'root>section[0]>row[0]>column[0]>text[1]');
expectElement('deleteElement removes element', count($navigator->listElements($deleted['layout'] ?? [])), 5);
expectElement('deleteElement refuses root', $navigator->deleteElement($layout, 'root')['code'] ?? null, 'invalid_path');

$types = $navigator->summarizeTypes([$layout]);
$typesByName = [];

foreach ($types as $type) {
    $typesByName[$type['type']] = $type;
}

expectElement('summarizeTypes type count', count($typesByName), 6);
expectElement('headline observed prop key', $typesByName['headline']['prop_keys'], ['content']);
expectElement('text binding count', $typesByName['text']['has_source_binding_count'], 1);
expectElement('section sample path', $typesByName['section']['sample_paths'][0], 'root>section[0]');

// Cloning must not duplicate props.id: YOOtheme renders it as the HTML id and
// an in-page #anchor would resolve to the source, never to the copy (BIT Vic).
$anchorLayout = [
    'type' => 'layout',
    'children' => [[
        'type' => 'section',
        'children' => [
            [
                'type' => 'row',
                'props' => ['id' => 'visual'],
                'children' => [[
                    'type' => 'column',
                    'children' => [[
                        'type' => 'button',
                        'children' => [[
                            'type' => 'button_item',
                            'props' => ['id' => 'visual-cta', 'link' => '#visual'],
                        ]],
                    ]],
                ]],
            ],
            [
                'type' => 'row',
                'children' => [[
                    'type' => 'column',
                    'children' => [[
                        'type' => 'button',
                        'children' => [[
                            'type' => 'button_item',
                            'props' => ['link' => '#visual'],
                        ]],
                    ]],
                ]],
            ],
        ],
    ]],
];

$anchorClone = $navigator->cloneElement($anchorLayout, 'root>section[0]>row[0]');
$anchorTree = $anchorClone['layout'];
$copiedRow = $anchorTree['children'][0]['children'][1];
$sourceRow = $anchorTree['children'][0]['children'][0];
$outsideRow = $anchorTree['children'][0]['children'][2];

expectElement(
    'cloneElement renames a colliding props.id',
    $copiedRow['props']['id'] ?? null,
    'visual-2'
);
expectElement(
    'cloneElement renames nested colliding ids too',
    $copiedRow['children'][0]['children'][0]['children'][0]['props']['id'] ?? null,
    'visual-cta-2'
);
expectElement(
    'cloneElement repoints an anchor inside the copy',
    $copiedRow['children'][0]['children'][0]['children'][0]['props']['link'] ?? null,
    '#visual-2'
);
expectElement(
    'cloneElement leaves the source id alone',
    $sourceRow['props']['id'] ?? null,
    'visual'
);
expectElement(
    'cloneElement leaves an anchor outside the copy alone',
    $outsideRow['children'][0]['children'][0]['children'][0]['props']['link'] ?? null,
    '#visual'
);
expectElement(
    'cloneElement reports the renames',
    $anchorClone['renamed_ids'] ?? null,
    ['visual' => 'visual-2', 'visual-cta' => 'visual-cta-2']
);

$secondClone = $navigator->cloneElement($anchorClone['layout'], 'root>section[0]>row[0]');
expectElement(
    'a second clone skips the id already taken',
    $secondClone['layout']['children'][0]['children'][1]['props']['id'] ?? null,
    'visual-3'
);

expectElement(
    'cloneElement reports no renames when there is no id',
    $navigator->cloneElement($layout, 'root>section[0]>row[0]>column[0]>headline[0]')['renamed_ids'] ?? null,
    []
);

$besideClone = $navigator->cloneElementBeside(
    $anchorLayout,
    'root>section[0]>row[0]',
    'root>section[0]>row[1]',
    'after'
);
expectElement(
    'cloneElementBeside renames a colliding props.id',
    $besideClone['layout']['children'][0]['children'][2]['props']['id'] ?? null,
    'visual-2'
);

// WordPress ships its own copy of the navigator; the two must agree.
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text, bool $remove_breaks = false): string
    {
        $stripped = preg_replace('/\s+/', ' ', strip_tags($text)) ?? '';

        return trim($stripped);
    }
}

require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/YoothemeElementNavigator.php';

$wpClone = (new Mirasai\WordPress\Tool\YoothemeElementNavigator())
    ->cloneElement($anchorLayout, 'root>section[0]>row[0]');

expectElement(
    'WordPress navigator renames the same way',
    $wpClone['layout']['children'][0]['children'][1]['props']['id'] ?? null,
    'visual-2'
);
expectElement(
    'WordPress navigator reports the same renames',
    $wpClone['renamed_ids'] ?? null,
    ['visual' => 'visual-2', 'visual-cta' => 'visual-cta-2']
);

if ($failed > 0) {
    echo "\n{$failed} element navigator test(s) failed.\n";
    exit(1);
}

echo "\nAll {$passed} element navigator tests passed.\n";
