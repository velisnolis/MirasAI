<?php
/**
 * Test: a row's layout against its column widths (F-044) on both hosts. The
 * options are keys of row/element.php in YOOtheme 5.0.53; the widths follow the
 * Builder's columnWidths(): width_medium, then width_small, then width_default.
 *
 * Run from the repo root:
 *   php docker/test-row-layout.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

require_once $root . '/packages/mirasai-wp/src/Tool/YoothemeRowLayout.php';
require_once $root . '/packages/mirasai-wp/src/Tool/YoothemeElementNavigator.php';
require_once $root . '/packages/mirasai-wp/src/Tool/ToolInterface.php';
require_once $root . '/packages/mirasai-wp/src/Tool/AbstractTool.php';
require_once $root . '/packages/mirasai-wp/src/Tool/TemplateElementSourceSupportTrait.php';
require_once $root . '/packages/mirasai-wp/src/Tool/TemplateReadTool.php';
require_once $root . '/packages/mirasai-wp/src/Tool/AbstractTemplateElementWriteTool.php';
require_once $root . '/packages/mirasai-joomla/packages/plg_mirasai_yootheme/src/Tool/YoothemeRowLayout.php';
require_once $root . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/YooThemeElementNavigator.php';
require_once $root . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/ToolInterface.php';
require_once $root . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/AbstractTool.php';
require_once $root . '/packages/mirasai-joomla/packages/plg_mirasai_yootheme/src/Tool/AbstractTemplateElementWriteTool.php';

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

const ROW_DEFINITION = ['fields' => ['layout' => ['type' => 'select-img', 'options' => [
    '' => ['label' => 'Whole'],
    '1-2,1-2' => ['label' => 'Halves'],
    '1-3,1-3,1-3' => ['label' => 'Thirds'],
    '1-4,1-4,1-4,1-4|1-2,1-2,1-2,1-2' => ['label' => 'Quarters'],
    '3-4,1-4' => ['label' => 'Quarters 3-1'],
    '1-3,2-3' => ['label' => 'Thirds 1-2'],
    'large,expand' => ['label' => 'Fixed-Left'],
]]]];

$column = static fn (array $props = []): array => ['type' => 'column', 'props' => $props, 'children' => []];
$row = static fn (array $columns, ?string $layout = null): array => ['type' => 'row', 'props' => $layout === null ? [] : ['layout' => $layout], 'children' => $columns];
$m = static fn (string ...$widths): array => array_map(static fn (string $w): array => ['type' => 'column', 'props' => ['width_medium' => $w], 'children' => []], $widths);

foreach (['wordpress' => \Mirasai\WordPress\Tool\YoothemeRowLayout::class, 'joomla' => \Mirasai\Plugin\Mirasai\Yootheme\Tool\YoothemeRowLayout::class] as $host => $R) {
    $options = $R::options(ROW_DEFINITION);
    check($options !== null && !in_array('', $options, true) && in_array('3-4,1-4', $options, true), "$host: options are the layout keys without Whole");
    check($R::options(['fields' => []]) === null && $R::options([]) === null, "$host: no options when the definition has none");

    check($R::fromColumns($row($m('3-4', '1-4'))) === '3-4,1-4', "$host: columns layout from width_medium");
    check($R::fromColumns($row([$column(['width_medium' => '1-4', 'width_small' => '1-2']), $column(['width_medium' => '1-4', 'width_small' => '1-2'])])) === '1-4,1-4|1-2,1-2', "$host: width_small after |");

    check($R::check($row([$column()]), $options) === null, "$host: a single column needs no layout");
    check($R::check($row($m('1-2', '1-2'), '1-2,1-2'), $options) === null, "$host: matching layout is fine");

    $missing = $R::check($row($m('3-4', '1-4')), $options);
    check(($missing['code'] ?? '') === 'row_layout_mismatch' && $missing['reason'] === 'missing' && $missing['suggested_layout'] === '3-4,1-4' && $missing['columns'] === 2, "$host: missing layout, the widths suggest it");
    check(str_contains($R::message($missing), 'Whole') && str_contains($R::fix($missing), '«3-4,1-4»'), "$host: message names Whole, fix names the layout");

    $quarters = $R::check($row($m('1-4', '1-4', '1-4', '1-4')), $options);
    check($quarters['suggested_layout'] === '1-4,1-4,1-4,1-4|1-2,1-2,1-2,1-2', "$host: medium widths only still find the Builder layout that starts with them");
    check($R::check($row($m('1-4', '1-4', '1-4', '1-4'), '1-4,1-4,1-4,1-4|1-2,1-2,1-2,1-2'), $options) === null, "$host: that layout fits columns without width_small");

    $differs = $R::check($row($m('1-2', '1-2'), '3-4,1-4'), $options);
    check($differs['reason'] === 'differs' && $differs['columns_layout'] === '1-2,1-2' && $differs['suggested_layout'] === '1-2,1-2', "$host: layout that differs from the widths");
    check($R::check($row($m('1-2', '1-2', '1-2'), '1-2,1-2'), $options)['reason'] === 'differs', "$host: column count differs from the layout");
    check($R::check($row([$column(['width_medium' => '1-2', 'width_small' => '1-1']), $column(['width_medium' => '1-2', 'width_small' => '1-1'])], '1-2,1-2'), $options)['reason'] === 'differs', "$host: a width_small the layout does not carry");

    $foreign = $R::check($row($m('2-5', '3-5'), '2-5,3-5'), $options);
    check($foreign['reason'] === 'not_an_option' && $foreign['suggested_layout'] === null, "$host: a layout the Builder does not offer, no suggestion");
    check(str_contains($R::fix($foreign), 'match no Builder layout'), "$host: the fix says the widths match no layout");
    check($R::check($row($m('2-5', '3-5'), '2-5,3-5'), null) === null, "$host: without options only the widths are compared");

    check($R::check($row([$column(), $column()]), $options) === null, "$host: columns without any width stack in both, nothing to flag");
    $bare = $R::check($row([$column(), $column()], '1-2,1-2'), $options);
    check($bare['reason'] === 'differs' && $bare['suggested_layout'] === null, "$host: a layout over columns without widths differs, no suggestion");
    $half = $R::check($row([$column(['width_medium' => '1-2']), $column()]), $options);
    check($half['reason'] === 'missing' && $half['suggested_layout'] === null, "$host: one column with a width, the other without: flagged, nothing to suggest");
    check($R::check($row([$column(['width_medium' => '1-2']), $column()]), null)['suggested_layout'] === null, "$host: nor without options");

    check(($R::filled($row($m('1-3', '2-3')), $options)['props']['layout'] ?? null) === '1-3,2-3', "$host: filled sets the layout");
    check($R::filled($row($m('1-2', '1-2'), '3-4,1-4'), $options) === null, "$host: filled leaves a set layout alone");
    check($R::filled($row([$column(), $column()]), $options) === null, "$host: filled does not guess");
}

$wp = new class extends \Mirasai\WordPress\Tool\AbstractTemplateElementWriteTool {
    public function __construct() {}
    public function getName(): string { return 'test/row'; }
    public function getDescription(): string { return 'test'; }
    public function handle(array $arguments): array { return []; }
    protected function rowLayoutOptions(): ?array { return \Mirasai\WordPress\Tool\YoothemeRowLayout::options(ROW_DEFINITION); }
    public function fill(array $element, array &$filled, array &$warnings): array { return $this->fillRowLayouts($element, $this->rowLayoutOptions(), $filled, $warnings); }
    public function afterUpdate(array $layout, string $path, string $type, array $props, bool $merge = true): ?array { return $this->rowLayoutWarningAfterUpdate($layout, $path, $type, $props, $merge); }
};
$joomla = new class extends \Mirasai\Plugin\Mirasai\Yootheme\Tool\AbstractTemplateElementWriteTool {
    public function __construct() {}
    public function getName(): string { return 'test/row'; }
    public function getDescription(): string { return 'test'; }
    public function getInputSchema(): array { return []; }
    public function handle(array $arguments): array { return []; }
    protected function rowLayoutOptions(): ?array { return \Mirasai\Plugin\Mirasai\Yootheme\Tool\YoothemeRowLayout::options(ROW_DEFINITION); }
    public function fill(array $element, array &$filled, array &$warnings): array { return $this->fillRowLayouts($element, $this->rowLayoutOptions(), $filled, $warnings); }
    public function afterUpdate(array $layout, string $path, string $type, array $props, bool $merge = true): ?array { return $this->rowLayoutWarningAfterUpdate($layout, $path, $type, $props, $merge); }
};

$results = [];

foreach (['wordpress' => $wp, 'joomla' => $joomla] as $host => $tool) {
    $section = ['type' => 'section', 'children' => [
        $row($m('3-4', '1-4')),
        $row([$column(['width_medium' => '2-5']), $column(['width_medium' => '3-5'])]),
        $row([$column()]),
        $row([$column(), $column()]),
    ]];
    $filled = [];
    $warnings = [];
    $out = $tool->fill($section, $filled, $warnings);
    check(($out['children'][0]['props']['layout'] ?? null) === '3-4,1-4', "$host: element-add fills the row layout inside a section");
    check($filled === [['at' => 'element>row[0]', 'layout' => '3-4,1-4']], "$host: and reports where");
    check(count($warnings) === 1 && $warnings[0]['at'] === 'element>row[1]' && $warnings[0]['code'] === 'row_layout_mismatch' && $warnings[0]['suggested_layout'] === null, "$host: a row it cannot fill is a warning");
    check(!isset($out['children'][1]['props']['layout']) && !isset($out['children'][2]['props']['layout']) && !isset($out['children'][3]['props']['layout']), "$host: nothing guessed; single column and width-less columns untouched");

    $layout = ['type' => 'layout', 'children' => [['type' => 'section', 'children' => [$row($m('1-2', '1-2'), '3-4,1-4')]]]];
    $columnWarning = $tool->afterUpdate($layout, 'root>section[0]>row[0]>column[1]', 'column', ['width_medium' => '1-2']);
    check(($columnWarning['path'] ?? '') === 'root>section[0]>row[0]' && $columnWarning['suggested_layout'] === '1-2,1-2', "$host: a column width change warns on its row");
    check($tool->afterUpdate($layout, 'root>section[0]>row[0]>column[1]', 'column', ['style' => 'card-default']) === null, "$host: other column props do not warn");
    check(($tool->afterUpdate($layout, 'root>section[0]>row[0]', 'row', ['layout' => '3-4,1-4'])['path'] ?? '') === 'root>section[0]>row[0]', "$host: a row layout change warns on the row");
    check($tool->afterUpdate($layout, 'root>section[0]>row[0]', 'row', ['column_gap' => 'small']) === null, "$host: other row props do not warn");
    $fixed = ['type' => 'layout', 'children' => [['type' => 'section', 'children' => [$row($m('1-2', '1-2'), '1-2,1-2')]]]];
    check($tool->afterUpdate($fixed, 'root>section[0]>row[0]', 'row', ['layout' => '1-2,1-2']) === null, "$host: no warning once the layout matches");

    $results[$host] = [$out, $filled, $warnings, $columnWarning];
}
check($results['wordpress'] === $results['joomla'], 'both hosts give the same result');

$strip = static fn (string $s): string => preg_replace(['/^namespace .*$/m', '/^ \* Mirrored in .*$/m'], '', $s);
check(
    $strip(file_get_contents($root . '/packages/mirasai-wp/src/Tool/YoothemeRowLayout.php'))
        === $strip(file_get_contents($root . '/packages/mirasai-joomla/packages/plg_mirasai_yootheme/src/Tool/YoothemeRowLayout.php')),
    'row layout copies are identical apart from namespace and mirror note'
);

echo $fails === 0 ? "OK: $count checks\n" : "$fails of $count checks failed\n";
exit($fails === 0 ? 0 : 1);
