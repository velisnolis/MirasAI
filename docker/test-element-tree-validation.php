<?php
/**
 * Test: template/element-add checks the prop values of every node it adds, not only the top one,
 * on both hosts. Element definitions come from memory instead of an installed YOOtheme.
 *
 * Run from the repo root:
 *   php docker/test-element-tree-validation.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

require_once $root . '/packages/mirasai-wp/src/Tool/ToolInterface.php';
require_once $root . '/packages/mirasai-wp/src/Tool/AbstractTool.php';
require_once $root . '/packages/mirasai-wp/src/Tool/TemplateElementSourceSupportTrait.php';
require_once $root . '/packages/mirasai-wp/src/Tool/TemplateReadTool.php';
require_once $root . '/packages/mirasai-wp/src/Tool/YoothemePropsValidator.php';
require_once $root . '/packages/mirasai-wp/src/Tool/AbstractTemplateElementWriteTool.php';
require_once $root . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/ToolInterface.php';
require_once $root . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/AbstractTool.php';
require_once $root . '/packages/mirasai-joomla/packages/plg_mirasai_yootheme/src/Tool/YoothemePropsValidator.php';
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

const FIELDS = [
    'section' => ['style' => ['type' => 'select', 'options' => ['Default' => 'default', 'Muted' => 'muted']]],
    'headline' => ['title_element' => ['type' => 'select', 'options' => ['H1' => 'h1', 'H2' => 'h2']]],
];

$wp = new class extends \Mirasai\WordPress\Tool\AbstractTemplateElementWriteTool {
    public function __construct() {}
    public function getName(): string { return 'test/tree'; }
    public function getDescription(): string { return 'test'; }
    public function handle(array $arguments): array { return []; }
    protected function rejectInvalidProps(string $elementType, array $props): ?array
    {
        return isset(FIELDS[$elementType]) ? \Mirasai\WordPress\Tool\YoothemePropsValidator::validate($elementType, FIELDS[$elementType], $props) : null;
    }
    public function tree(array $element): ?array { return $this->rejectInvalidTree($element); }
};
$joomla = new class extends \Mirasai\Plugin\Mirasai\Yootheme\Tool\AbstractTemplateElementWriteTool {
    public function __construct() {}
    public function getName(): string { return 'test/tree'; }
    public function getDescription(): string { return 'test'; }
    public function getInputSchema(): array { return []; }
    public function handle(array $arguments): array { return []; }
    protected function rejectInvalidProps(string $elementType, array $props): ?array
    {
        return isset(FIELDS[$elementType]) ? \Mirasai\Plugin\Mirasai\Yootheme\Tool\YoothemePropsValidator::validate($elementType, FIELDS[$elementType], $props) : null;
    }
    public function tree(array $element): ?array { return $this->rejectInvalidTree($element); }
};

$section = static fn (string $style, string $level): array => ['type' => 'section', 'props' => ['style' => $style], 'children' => [
    ['type' => 'row', 'children' => [['type' => 'column', 'children' => [
        ['type' => 'headline', 'props' => ['title_element' => 'h1', 'content' => 'A']],
        ['type' => 'headline', 'props' => ['title_element' => $level, 'content' => 'B']],
    ]]]],
]];

foreach (['wordpress' => $wp, 'joomla' => $joomla] as $host => $tool) {
    check($tool->tree($section('muted', 'h2')) === null, "$host: a valid section with its children passes");

    $deep = $tool->tree($section('muted', 'h7'));
    check(($deep['code'] ?? '') === 'invalid_prop_value' && ($deep['at'] ?? '') === 'element>row[0]>column[0]>headline[1]', "$host: a bad value deep in the tree is refused with its place");
    check(($deep['issues'][0]['prop'] ?? '') === 'title_element' && ($deep['issues'][0]['accepted_values'] ?? []) === ['h1', 'h2'], "$host: the issue names the prop and the accepted values");

    $top = $tool->tree($section('loud', 'h7'));
    check(($top['at'] ?? '') === 'element' && ($top['element_type'] ?? '') === 'section', "$host: the top element is checked first");

    check($tool->tree(['type' => 'unknown_vendor_element', 'props' => ['x' => 'y']]) === null, "$host: an element without a known definition is left alone");
    check($tool->tree(['type' => 'headline', 'props' => ['title_element' => ['dynamic' => true]]]) === null, "$host: non-scalar values (bindings) are left alone");
}

echo $fails === 0 ? "OK: $count checks\n" : "$fails of $count checks failed\n";
exit($fails === 0 ? 0 : 1);
