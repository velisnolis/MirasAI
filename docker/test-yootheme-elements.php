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
expectElement('setElementSource writes props.source query', $sourceSet['element']['props']['source']['query']['name'] ?? null, 'Article');
expectElement('setElementSource detects binding', $sourceSet['metadata']['has_source_binding'] ?? null, true);
expectElement('setElementSource does not mutate original layout', isset($layout['children'][0]['children'][0]['children'][0]['children'][0]['props']['source']), false);

$sourceDeleted = $navigator->deleteElementSource($sourceSet['layout'], 'root>section[0]>row[0]>column[0]>headline[0]');
expectElement('deleteElementSource removes props.source', isset($sourceDeleted['element']['props']['source']), false);
expectElement('deleteElementSource reports location', $sourceDeleted['removed_locations'], ['props.source']);
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

if ($failed > 0) {
    echo "\n{$failed} element navigator test(s) failed.\n";
    exit(1);
}

echo "\nAll {$passed} element navigator tests passed.\n";
