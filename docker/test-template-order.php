<?php
/**
 * Test: which template YOOtheme applies, as TemplateHelper::match() decides it,
 * and what switching or moving one changes. The writes are checked on real sites.
 *
 * Run from the repo root:
 *   php docker/test-template-order.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

require_once $root . '/packages/mirasai-wp/src/Tool/TemplateOrder.php';
require_once $root . '/packages/mirasai-joomla/packages/plg_mirasai_yootheme/src/Tool/TemplateOrder.php';
require_once $root . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/YooThemeHelper.php';

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

$templates = [
    'news' => ['name' => 'Notícies', 'type' => 'single-post', 'query' => ['terms' => ['5']]],
    'page' => ['name' => 'Pàgina', 'type' => 'single-page', 'query' => []],
    'all' => ['name' => 'Totes', 'type' => 'single-post', 'query' => ['terms' => [], 'lang' => '']],
    'new' => ['name' => 'Nova', 'type' => 'single-post', 'status' => 'disabled', 'query' => []],
    'old' => ['name' => 'Antiga', 'type' => 'single-post', 'status' => 'disabled', 'query' => []],
];

foreach (['wordpress' => \Mirasai\WordPress\Tool\TemplateOrder::class, 'joomla' => \Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateOrder::class] as $host => $O) {
    check($O::conditions($templates['all']) === [] && $O::conditions($templates['news']) === ['terms' => ['5']], "$host: empty query values are no condition");
    check(!$O::isEnabled($templates['new']) && $O::isEnabled($templates['all']), "$host: disabled only when status is disabled");

    $d = $O::describe($templates, 'new');
    check(array_column($d['order'], 'key') === ['news', 'all', 'new', 'old'], "$host: order lists only the same type, in storage order");
    check($d['enabled'] === false && str_starts_with($d['applies'], 'never: switched off'), "$host: a disabled template applies nowhere");

    $on = $O::withStatus($templates, 'new', true);
    check(!isset($on['new']['status']) && array_keys($on) === array_keys($templates), "$host: switching on removes status, keeps the order");
    $d = $O::describe($on, 'new');
    check($d['shadowed_by'] === ['all'] && $d['earlier_with_conditions'] === ['news'] && $d['takes_over_from'] === [], "$host: switched on behind a template without conditions, it is hidden");
    check(str_starts_with($d['applies'], 'never: an earlier template without conditions'), "$host: says why it never applies");

    $front = $O::moved($on, 'new', 0);
    check(array_keys($front) === ['new', 'news', 'page', 'all', 'old'], "$host: moved before the first of its type; other types keep their place");
    $d = $O::describe($front, 'new');
    check($d['shadowed_by'] === [] && $d['applies'] === 'to every page of this type', "$host: first and without conditions, it applies everywhere");
    check(array_column($d['takes_over_from'], 'key') === ['news', 'all'] && $d['takes_over_from'][0]['pages'] === 'all', "$host: it takes every page from the enabled ones after it");

    check(array_keys($O::moved($templates, 'news', 99)) === ['page', 'all', 'new', 'old', 'news'], "$host: a position past the end moves it last");
    check(array_keys($O::moved($templates, 'all', 1)) === ['news', 'page', 'all', 'new', 'old'], "$host: moving to its own place changes nothing");
    check(array_keys($O::moved($templates, 'old', 1)) === ['news', 'page', 'old', 'all', 'new'], "$host: moved between two of its type");

    $dup = $O::duplicated($templates, 'news', 'news2', 'Notícies (copy)');
    check(array_keys($dup) === ['news', 'news2', 'page', 'all', 'new', 'old'], "$host: the copy goes right after the original");
    check($dup['news2'] === ['name' => 'Notícies (copy)', 'type' => 'single-post', 'query' => ['terms' => ['5']], 'status' => 'disabled'] && $dup['news'] === $templates['news'], "$host: copy keeps type and conditions, is switched off; original unchanged");

    $narrow = $O::withStatus(['a' => ['type' => 'x', 'query' => ['lang' => 'ca']], 'b' => ['type' => 'x']], 'a', true);
    $d = $O::describe($narrow, 'a');
    check($d['applies'] === 'to the pages its conditions match' && $d['takes_over_from'] === [['key' => 'b', 'pages' => 'those matching both']], "$host: a template with conditions takes only the matching pages");
}

// Joomla's collection etag must change with the order (Codex review, PR #12): the order decides which template applies.
$helper = (new \ReflectionClass(\Mirasai\Library\Tool\YooThemeHelper::class))->newInstanceWithoutConstructor();
$a = ['k1' => ['name' => 'A', 'type' => 'x', 'query' => []], 'k2' => ['type' => 'x', 'name' => 'B']];
$b = ['k2' => ['type' => 'x', 'name' => 'B'], 'k1' => ['name' => 'A', 'type' => 'x', 'query' => []]];
$c = ['k1' => ['type' => 'x', 'query' => [], 'name' => 'A'], 'k2' => ['name' => 'B', 'type' => 'x']];
check($helper->buildTemplatesEtag($a) !== $helper->buildTemplatesEtag($b), 'joomla: reordering templates changes the collection etag');
check($helper->buildTemplatesEtag($a) === $helper->buildTemplatesEtag($c), 'joomla: field order inside a template does not');
check($helper->buildTemplatesEtag($a) !== $helper->buildTemplatesEtag(\Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateOrder::withStatus($a, 'k2', false)), 'joomla: switching a template off changes it');

$strip = static fn (string $s): string => preg_replace(['/^namespace .*$/m', '/^ \* Mirrored in .*$/m'], '', $s);
check($strip(file_get_contents($root . '/packages/mirasai-wp/src/Tool/TemplateOrder.php')) === $strip(file_get_contents($root . '/packages/mirasai-joomla/packages/plg_mirasai_yootheme/src/Tool/TemplateOrder.php')), 'order copies are identical apart from namespace and mirror note');

echo $fails === 0 ? "OK: $count checks\n" : "$fails of $count checks failed\n";
exit($fails === 0 ? 0 : 1);
