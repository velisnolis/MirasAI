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

if ($failed > 0) {
    echo "\n{$failed} read-mode test(s) failed.\n";
    exit(1);
}

echo "\nAll {$passed} read-mode tests passed.\n";
