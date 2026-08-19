<?php
/**
 * Form tests for template/read and template/element-list read modes.
 *
 * Run from the repo root:
 *   php docker/test-yootheme-read-modes.php
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

function expectMode(string $label, mixed $actual, mixed $expected): void
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
    'name' => 'page-root',
    'children' => [[
        'type' => 'section',
        'props' => ['title' => 'Hero section', 'style' => 'default'],
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

// BIT Vic's shape: the row is disabled and last edition's source stays on the
// gallery inside it as a placeholder.
$layout['children'][] = [
    'type' => 'section',
    'props' => ['title' => 'Arxiu'],
    'children' => [[
        'type' => 'row',
        'props' => ['id' => 'visual', 'status' => 'disabled'],
        'children' => [[
            'type' => 'column',
            'children' => [[
                'type' => 'gallery',
                'props' => [
                    'title' => 'Galeria edicio anterior',
                    'source' => ['props' => ['images' => 'files']],
                ],
            ]],
        ]],
    ]],
];

$joomla = new YooThemeElementNavigator();
$wp = new WpYoothemeElementNavigator();

expectMode('default mode is full', YooThemeElementNavigator::normalizeReadMode(null), ['mode' => 'full']);
expectMode('empty string mode is full', YooThemeElementNavigator::normalizeReadMode(''), ['mode' => 'full']);
expectMode('outline is accepted', YooThemeElementNavigator::normalizeReadMode('outline'), ['mode' => 'outline']);
expectMode('bindings_only is accepted', YooThemeElementNavigator::normalizeReadMode('bindings_only'), ['mode' => 'bindings_only']);
expectMode('unknown mode is rejected', YooThemeElementNavigator::normalizeReadMode('compact')['code'] ?? null, 'invalid_mode');
expectMode('non-string mode is rejected', YooThemeElementNavigator::normalizeReadMode(1)['code'] ?? null, 'invalid_mode');
expectMode('wp and joomla normalize the same', WpYoothemeElementNavigator::normalizeReadMode('OUTLINE'), YooThemeElementNavigator::normalizeReadMode('outline'));

$joomlaTree = $joomla->outlineTree($layout);
$wpTree = $wp->outlineTree($layout);

expectMode('hosts share outline shape', $joomlaTree, $wpTree);
expectMode('outline root type', $joomlaTree['type'], 'layout');
expectMode('outline root path', $joomlaTree['path'], 'root');
expectMode('outline keeps node name', $joomlaTree['name'] ?? null, 'page-root');
expectMode('outline section title from props.title', $joomlaTree['children'][0]['title'] ?? null, 'Hero section');
expectMode('outline headline title strips html', $joomlaTree['children'][0]['children'][0]['children'][0]['children'][0]['title'] ?? null, 'Main headline');
expectMode('outline omits props on section', array_key_exists('props', $joomlaTree['children'][0]), false);
expectMode('outline omits source on text', array_key_exists('source', $joomlaTree['children'][0]['children'][0]['children'][0]['children'][1]), false);

$flatPaths = array_column($joomla->listElements($layout), 'path');
$outlinePaths = [];
$walk = static function (array $node) use (&$walk, &$outlinePaths): void {
    $outlinePaths[] = $node['path'];
    foreach ($node['children'] as $child) {
        $walk($child);
    }
};
$walk($joomlaTree);
expectMode('outline paths match the flat index', $outlinePaths, $flatPaths);

$schema = YooThemeElementNavigator::readModeSchemaProperty();
expectMode('schema enum matches both hosts', $schema['enum'], WpYoothemeElementNavigator::readModeSchemaProperty()['enum']);
expectMode('schema enum values', $schema['enum'], ['full', 'outline', 'bindings_only']);


// status and has_source_binding: what an agent needs before building a rebind
// map, without paying for props.
$liveSection = $joomlaTree['children'][0];
$archiveSection = $joomlaTree['children'][1];
$disabledRow = $archiveSection['children'][0];
$boundText = $liveSection['children'][0]['children'][0]['children'][1];
$plainHeadline = $liveSection['children'][0]['children'][0]['children'][0];

expectMode('outline marks a disabled row', $disabledRow['status'] ?? null, 'disabled');
expectMode('outline omits status when the element renders', array_key_exists('status', $liveSection), false);
expectMode('outline flags a bound node', $boundText['has_source_binding'] ?? null, true);
expectMode('outline omits the flag when there is no binding', array_key_exists('has_source_binding', $plainHeadline), false);
expectMode('hosts still share outline shape with flags', $joomlaTree, $wp->outlineTree($layout));

$metaByPath = [];

foreach ($joomla->listElements($layout) as $meta) {
    $metaByPath[$meta['path']] = $meta;
}

expectMode(
    'element-list carries status too',
    $metaByPath['root>section[1]>row[0]']['status'] ?? null,
    'disabled'
);
expectMode(
    'element-list omits status when the element renders',
    array_key_exists('status', $metaByPath['root>section[0]']),
    false
);

$bindingRows = new class {
    use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateElementSourceSupportTrait;

    /**
     * @param array<string, mixed> $layout
     * @return list<array<string, mixed>>
     */
    public function rows(object $navigator, array $layout): array
    {
        return $this->bindingsOnlyFromLayout($navigator, $layout);
    }
};

$wpBindingRows = new class {
    use Mirasai\WordPress\Tool\TemplateElementSourceSupportTrait;

    /**
     * @param array<string, mixed> $layout
     * @return list<array<string, mixed>>
     */
    public function rows(object $navigator, array $layout): array
    {
        return $this->bindingsOnlyFromLayout($navigator, $layout);
    }
};

$rows = $bindingRows->rows($joomla, $layout);
$rowsByPath = [];

foreach ($rows as $row) {
    $rowsByPath[$row['path']] = $row;
}

expectMode('bindings_only finds both bindings', count($rows), 2);
expectMode(
    'a binding inside a disabled row names the ancestor',
    $rowsByPath['root>section[1]>row[0]>column[0]>gallery[0]']['disabled_by'] ?? null,
    'root>section[1]>row[0]'
);
expectMode(
    'the disabled ancestor is not the node itself',
    array_key_exists('status', $rowsByPath['root>section[1]>row[0]>column[0]>gallery[0]']),
    false
);
expectMode(
    'a live binding carries no disabled_by',
    array_key_exists('disabled_by', $rowsByPath['root>section[0]>row[0]>column[0]>text[1]']),
    false
);
expectMode(
    'hosts agree on bindings_only rows',
    $rows,
    $wpBindingRows->rows($wp, $layout)
);

if ($failed > 0) {
    echo "\n{$failed} read-mode test(s) failed.\n";
    exit(1);
}

echo "\nAll {$passed} read-mode tests passed.\n";
