<?php
/**
 * Test: template/create builds the entry YOOtheme's editor saves, switched off.
 * The write itself is checked on a real site (docs/wootsup-comparison-proposal-2026-11-09.md).
 *
 * Run from the repo root:
 *   php docker/test-template-create.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

require_once $root . '/packages/mirasai-wp/src/Tool/ToolInterface.php';
require_once $root . '/packages/mirasai-wp/src/Tool/AbstractTool.php';
require_once $root . '/packages/mirasai-wp/src/Tool/TemplateCreateTool.php';
require_once $root . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/ToolInterface.php';
require_once $root . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/AbstractTool.php';
require_once $root . '/packages/mirasai-joomla/packages/plg_mirasai_yootheme/src/Tool/TemplateCreateTool.php';

use Mirasai\WordPress\Tool\TemplateCreateTool as C;

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

$t = C::buildTemplate('Fitxa de projecte', 'single-projecte', null);
check($t === ['name' => 'Fitxa de projecte', 'type' => 'single-projecte', 'status' => 'disabled', 'query' => [], 'layout' => ['type' => 'layout', 'children' => []]], 'wordpress: empty template, switched off');

$section = ['type' => 'section', 'children' => []];
$t = C::buildTemplate('X', 'single-post', ['children' => [$section]]);
check($t['layout'] === ['children' => [$section], 'type' => 'layout'], 'wordpress: starting layout kept, type added');

check((C::buildTemplate('', 'single-post', null)['code'] ?? '') === 'missing_name', 'wordpress: name required');
foreach (['', 'Single-Post', 'single post', 'single--post', '-single', 'single/post'] as $bad) {
    check((C::buildTemplate('X', $bad, null)['code'] ?? '') === 'invalid_template_type', "wordpress: type «{$bad}» refused");
}
check(!isset(C::buildTemplate('X', 'error-404', null)['error']), 'wordpress: error-404 accepted');
check(!isset(C::buildTemplate('X', 'single_my_type', null)['error']), 'wordpress: underscore accepted');
foreach ([[$section], ['type' => 'section'], ['children' => 'x'], ['children' => ['a' => $section]], 'x'] as $i => $bad) {
    check((C::buildTemplate('X', 'single-post', $bad)['code'] ?? '') === 'invalid_layout', "wordpress: bad layout {$i} refused");
}

check(C::keysNamed(['a1' => ['name' => 'Projecte'], 'b2' => ['name' => ' projecte '], 'c3' => ['name' => 'Altres'], 'd4' => 'x'], 'PROJECTE') === ['a1', 'b2'], 'wordpress: same-name keys found ignoring case and spaces');

$tool = new C();
$mcp = $tool->toMcpTool();
check($mcp['metadata']['risk_level'] === 'guarded_write', 'wordpress: guarded write');
check(($mcp['inputSchema']['required'] ?? null) === ['name', 'type', 'if_match'], 'wordpress: name, type and if_match required');
check(($tool->handle(['name' => 'X', 'type' => 'single-post', 'if_match' => 'e'])['code'] ?? '') === 'guarded_write_confirmation_required', 'wordpress: no write without dry_run or confirmation');

// Joomla builds the same entry; its types carry a dot.
$J = \Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateCreateTool::class;
check($J::buildTemplate('Article', 'com_content.article', null) === ['name' => 'Article', 'type' => 'com_content.article', 'status' => 'disabled', 'query' => [], 'layout' => ['type' => 'layout', 'children' => []]], 'joomla: empty article template, switched off');
check(!isset(C::buildTemplate('X', 'com_content.article', null)['error']), 'wordpress: same type rule as joomla');
foreach (['Single-Post', 'com_content..article', '.article', 'com_content/article'] as $bad) {
    check(($J::buildTemplate('X', $bad, null)['code'] ?? '') === 'invalid_template_type', "joomla: type «{$bad}» refused");
}
check($J::keysNamed(['a' => ['name' => 'Article']], 'article') === ['a'], 'joomla: same-name keys');
$jt = (new \ReflectionClass($J))->newInstanceWithoutConstructor();
check(($jt->toMcpTool()['metadata']['risk_level'] ?? null) === 'guarded_write', 'joomla: guarded write');
check(($jt->getInputSchema()['required'] ?? null) === ['name', 'type', 'if_match'], 'joomla: name, type and if_match required');

echo $fails === 0 ? "OK: $count checks\n" : "$fails of $count checks failed\n";
exit($fails === 0 ? 0 : 1);
