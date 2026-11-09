<?php
/**
 * Test: template/render-check path marking and summary, without YOOtheme. The
 * rendering itself is checked on a real site (docs/wootsup-comparison-proposal-2026-11-09.md).
 *
 * Run from the repo root:
 *   php docker/test-render-check.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text): string
    {
        return trim(strip_tags($text));
    }
}

require_once $root . '/packages/mirasai-wp/src/Tool/ToolInterface.php';
require_once $root . '/packages/mirasai-wp/src/Tool/AbstractTool.php';
require_once $root . '/packages/mirasai-wp/src/Tool/PublicPageInspector.php';
require_once $root . '/packages/mirasai-wp/src/Tool/YoothemeLayoutProcessor.php';
require_once $root . '/packages/mirasai-wp/src/Tool/YoothemeElementNavigator.php';
require_once $root . '/packages/mirasai-wp/src/Tool/TemplateRenderCheckTool.php';

use Mirasai\WordPress\Tool\TemplateRenderCheckTool as R;
use Mirasai\WordPress\Tool\YoothemeElementNavigator;

$fails = 0;
$count = 0;
function check(bool $ok, string $name): void
{
    global $fails, $count;
    $count++;
    if (!$ok) {
        $fails++;
        echo "FAIL: $name\n";
    }
}

$layout = ['type' => 'layout', 'children' => [['type' => 'section', 'children' => [['type' => 'row', 'children' => [['type' => 'column', 'children' => [
    ['type' => 'headline', 'props' => ['content' => 'Titular']],
    ['type' => 'text', 'props' => ['content' => '<p>Visible</p>']],
    ['type' => 'grid', 'props' => ['status' => 'disabled'], 'children' => [['type' => 'grid_item', 'props' => ['title' => 'Dins la graella']]]],
    ['type' => 'image', 'props' => ['image' => 'a.jpg', 'image_alt' => 'Equip']],
]]]]]]]];

// Marking uses the same paths as template/element-list.
$marked = $layout;
$paths = [];
R::mark($marked, 'root', $paths);
$listed = array_column((new YoothemeElementNavigator())->listElements($layout), 'path');
sort($listed);
$sorted = $paths;
sort($sorted);
check(array_values(array_diff($listed, ['root'])) === $sorted, 'marked paths are the element-list paths');
check($marked['children'][0]['children'][0]['children'][0]['children'][0]['props']['mirasai_path'] === 'root>section[0]>row[0]>column[0]>headline[0]', 'mark sits in props');
check(!isset($layout['children'][0]['props']), 'the original layout is not touched');

// What YOOtheme's render transforms leave: the disabled grid is gone, the text resolved.
$tree = json_decode((string) json_encode($marked));
array_splice($tree->children[0]->children[0]->children[0]->children, 2, 1);
$tree->children[0]->children[0]->children[0]->children[1]->props->content = '<p>Vis<b>ible</b>  ara</p>';
$tree = json_decode((string) json_encode($tree), false);
// YOOtheme hands props over as arrays.
$toArrays = static function (object $node) use (&$toArrays): void {
    $node->props = (array) ($node->props ?? []);
    foreach ($node->children ?? [] as $child) {
        $toArrays($child);
    }
};
$toArrays($tree);

$s = R::summarize($tree, $paths, ['visible ara', 'Dins la graella', 'equip']);
$byPath = array_column($s['elements'], 'text', 'path');
check($byPath === [
    'root>section[0]>row[0]>column[0]>headline[0]' => 'Titular',
    'root>section[0]>row[0]>column[0]>text[1]' => 'Visible ara',
    'root>section[0]>row[0]>column[0]>image[3]' => 'Equip',
], 'elements with text, tags stripped, alt included');
check($s['not_rendered'] === ['root>section[0]>row[0]>column[0]>grid[2]'], 'only the top of a missing branch is reported');
check($s['expect'][0] === ['text' => 'visible ara', 'found' => true, 'paths' => ['root>section[0]>row[0]>column[0]>text[1]']], 'expect finds the path');
check($s['expect'][1]['found'] === false, 'text inside a disabled element is not found');
check($s['expect'][2]['paths'] === ['root>section[0]>row[0]>column[0]>image[3]'], 'expect ignores case');
check(!isset($s['elements'][0]['full_text']), 'internal full text not returned');

// A repeated element (multiple items) keeps its path on every copy.
$repeat = json_decode('{"type":"layout","props":{"mirasai_path":"root"},"children":[{"type":"text","props":{"mirasai_path":"root>text[0]","content":"A"}},{"type":"text","props":{"mirasai_path":"root>text[0]","content":"B"}}]}');
$toArrays($repeat);
$s = R::summarize($repeat, ['root>text[0]'], ['B']);
check(count($s['elements']) === 2 && $s['not_rendered'] === [] && $s['expect'][0]['paths'] === ['root>text[0]'], 'each copy listed, path reported once');

// Everything dropped.
$empty = (object) ['type' => 'layout', 'props' => ['mirasai_path' => 'root'], 'children' => []];
$s = R::summarize($empty, $paths, []);
check($s['elements'] === [] && $s['not_rendered'] === ['root>section[0]'], 'an empty render reports the top section');

$long = (object) ['type' => 'layout', 'props' => ['mirasai_path' => 'root'], 'children' => [(object) ['type' => 'text', 'props' => ['mirasai_path' => 'root>text[0]', 'content' => str_repeat('a', 400)]]]];
$s = R::summarize($long, ['root>text[0]'], []);
check(mb_strlen($s['elements'][0]['text']) === 301, 'long text cut at 300 characters');

echo $fails === 0 ? "OK: $count checks\n" : "$fails of $count checks failed\n";
exit($fails === 0 ? 0 : 1);
