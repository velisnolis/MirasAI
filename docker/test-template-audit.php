<?php
/**
 * Test: template/audit findings on both hosts, with element definitions as
 * YOOtheme 5.0.53 ships them (title_element defaults, list containers).
 *
 * Run from the repo root:
 *   php docker/test-template-audit.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

require_once $root . '/packages/mirasai-wp/src/Tool/YoothemeRowLayout.php';
require_once $root . '/packages/mirasai-wp/src/Tool/YoothemeLayoutAuditor.php';
require_once $root . '/packages/mirasai-wp/src/Tool/ToolInterface.php';
require_once $root . '/packages/mirasai-wp/src/Tool/AbstractTool.php';
require_once $root . '/packages/mirasai-wp/src/Tool/TemplateAuditTool.php';
require_once $root . '/packages/mirasai-joomla/packages/plg_mirasai_yootheme/src/Tool/YoothemeRowLayout.php';
require_once $root . '/packages/mirasai-joomla/packages/plg_mirasai_yootheme/src/Tool/YoothemeLayoutAuditor.php';
require_once $root . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/ToolInterface.php';
require_once $root . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/AbstractTool.php';
require_once $root . '/packages/mirasai-joomla/packages/plg_mirasai_yootheme/src/Tool/TemplateAuditTool.php';

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

$definitions = [
    'headline' => ['element' => true, 'defaults' => ['title_element' => 'h1'], 'fields' => ['title_element' => []]],
    'panel' => ['element' => true, 'defaults' => ['title_element' => 'h3'], 'fields' => ['title_element' => []]],
    'grid' => ['element' => true, 'container' => true, 'defaults' => ['title_element' => 'h3'], 'fields' => ['title_element' => []]],
    'grid_item' => ['fields' => ['title' => []]],
    'slideshow' => ['element' => true, 'container' => true, 'defaults' => ['title_element' => 'h3']],
    'fragment' => ['element' => true, 'container' => true],
    'text' => ['element' => true, 'fields' => ['content' => []]],
    'image' => ['element' => true, 'fields' => ['image' => [], 'image_alt' => []]],
    'section' => ['fields' => []], 'column' => [],
    'row' => ['fields' => ['layout' => ['options' => ['' => [], '1-2,1-2' => [], '3-4,1-4' => []]]]],
];
$def = static fn (string $type): array => $definitions[$type] ?? [];
$files = static fn (string $image): ?bool => str_starts_with($image, 'http') ? null : $image !== 'images/missing.jpg';

$col = static fn (array $children): array => ['type' => 'layout', 'children' => [['type' => 'section', 'children' => [['type' => 'row', 'children' => [['type' => 'column', 'children' => $children]]]]]]];
$p = 'root>section[0]>row[0]>column[0]>';

foreach (['wordpress' => \Mirasai\WordPress\Tool\YoothemeLayoutAuditor::class, 'joomla' => \Mirasai\Plugin\Mirasai\Yootheme\Tool\YoothemeLayoutAuditor::class] as $host => $A) {
    $audit = static fn (array $layout): array => (new $A($def, $files))->audit($layout);
    $codes = static fn (array $r): array => array_map(static fn (array $f): string => $f['code'] . '@' . $f['path'], $r['findings']);

    $clean = $audit($col([
        ['type' => 'headline', 'props' => ['content' => 'Qui som']],
        ['type' => 'headline', 'props' => ['content' => 'Equip', 'title_element' => 'h2']],
        ['type' => 'grid', 'children' => [['type' => 'grid_item', 'props' => ['title' => 'Anna']]]],
        ['type' => 'image', 'props' => ['image' => 'images/team.jpg', 'image_alt' => 'Equip al taller']],
    ]));
    check($clean['findings'] === [] && $clean['counts'] === ['error' => 0, 'warning' => 0, 'notice' => 0], "$host: a clean page has no findings");
    check(array_column($clean['headings'], 'level') === [1, 2, 3], "$host: outline h1, h2, then grid item title at the grid's h3");

    $r = $audit($col([
        ['type' => 'headline', 'props' => ['content' => 'Secció', 'title_element' => 'h2']],
        ['type' => 'headline', 'props' => ['content' => 'Detall', 'title_element' => 'h4']],
        ['type' => 'image', 'props' => ['image' => 'images/missing.jpg']],
        ['type' => 'grid', 'children' => []],
        ['type' => 'fragment', 'children' => []],
        ['type' => 'slideshow', 'children' => [], 'source' => ['query' => ['name' => 'posts']]],
        ['type' => 'headline', 'props' => ['content' => str_repeat('Llarg ', 15)]],
        ['type' => 'headline', 'props' => ['content' => 'Segon h1']],
    ]));
    check($codes($r) === [
        "image_file_missing@{$p}image[2]",
        "empty_container@{$p}grid[3]",
        "multiple_h1@{$p}headline[7]",
        "heading_level_skip@{$p}headline[1]",
        "missing_alt@{$p}image[2]",
        "long_heading@{$p}headline[6]",
    ], "$host: findings sorted by severity, with paths");
    check($r['counts'] === ['error' => 2, 'warning' => 2, 'notice' => 2], "$host: counts per severity");
    check(!in_array("empty_container@{$p}fragment[4]", $codes($r), true) && !in_array("empty_container@{$p}slideshow[5]", $codes($r), true), "$host: an empty fragment and a list bound to a source are not flagged");

    // F-043: a list item showing teaser or content without a length limit.
    $item = static fn (array $props): array => ['type' => 'grid_item', 'source' => ['query' => ['name' => 'posts'], 'props' => $props]];
    $r = $audit($col([['type' => 'headline', 'props' => ['content' => 'T']], ['type' => 'grid', 'props' => ['title_element' => 'h2'], 'children' => [
        $item(['content' => ['name' => 'teaser'], 'title' => ['name' => 'title']]),
        $item(['content' => ['name' => 'teaser', 'filters' => ['limit' => '180']]]),
        $item(['content' => ['name' => 'excerpt']]),
        $item(['meta' => ['name' => 'content', 'filters' => ['limit' => '']]]),
    ]]]));
    check($codes($r) === ["unbounded_teaser@{$p}grid[1]>grid_item[0]", "unbounded_teaser@{$p}grid[1]>grid_item[3]"], "$host: teaser or content without a limit in a list item, not with a limit or excerpt");
    $r = $audit($col([['type' => 'headline', 'props' => ['content' => 'T'], 'source' => ['props' => ['content' => ['name' => 'title']]]], ['type' => 'text', 'source' => ['props' => ['content' => ['name' => 'content']]]]]));
    check($r['findings'] === [], "$host: the full content of a single post is not flagged");

    $r = $audit($col([['type' => 'text', 'props' => ['content' => '<p>Sense títols</p>']]]));
    check($r['findings'] === [] && $r['headings'] === [], "$host: a layout without headings is not asked for an h1");

    $r = $audit($col([['type' => 'headline', 'props' => ['content' => 'Notícies', 'title_element' => 'h2']]]));
    check($codes($r) === ["no_h1@root"], "$host: headings without an h1");

    $r = $audit($col([
        ['type' => 'headline', 'props' => ['content' => ''], 'source' => ['props' => ['content' => ['name' => 'title']]]],
        ['type' => 'image', 'props' => ['image' => 'https://cdn.example/x.jpg', 'image_alt' => ''], 'source' => ['props' => ['image_alt' => ['name' => 'alt']]]],
        ['type' => 'image', 'props' => ['image' => ''], 'source' => ['props' => ['image' => ['name' => 'featured']]]],
    ]));
    check($r['findings'] === [] && $r['headings'][0]['text'] === '(dynamic)', "$host: dynamic values are not judged; a bound headline still counts as h1");

    // F-044: a row with several columns whose layout does not describe them.
    $cols = static fn (string ...$widths): array => array_map(static fn (string $w): array => ['type' => 'column', 'props' => ['width_medium' => $w], 'children' => [['type' => 'headline', 'props' => ['content' => 'T']]]], $widths);
    $rows = static fn (array ...$rows): array => ['type' => 'layout', 'children' => [['type' => 'section', 'children' => $rows]]];
    $r = $audit($rows(
        ['type' => 'row', 'children' => $cols('3-4', '1-4')],
        ['type' => 'row', 'props' => ['layout' => '3-4,1-4'], 'children' => $cols('1-2', '1-2')],
        ['type' => 'row', 'props' => ['layout' => '1-2,1-2'], 'children' => $cols('1-2', '1-2')],
        ['type' => 'row', 'children' => [['type' => 'column', 'children' => [['type' => 'headline', 'props' => ['content' => 'T']]]]]],
        ['type' => 'row', 'props' => ['status' => 'disabled'], 'children' => $cols('1-2', '1-2')],
    ));
    $rowFindings = array_values(array_filter($r['findings'], static fn (array $f): bool => $f['code'] === 'row_layout_mismatch'));
    check(array_column($rowFindings, 'path') === ['root>section[0]>row[0]', 'root>section[0]>row[1]'], "$host: rows without or with a wrong layout, not a matching, single-column or disabled one");
    check($rowFindings[0]['severity'] === 'warning' && str_contains($rowFindings[0]['fix'], '«3-4,1-4»') && str_contains($rowFindings[1]['fix'], '«1-2,1-2»'), "$host: a warning whose fix names the layout to set");

    $r = $audit($col([
        ['type' => 'headline', 'props' => ['content' => 'Títol']],
        ['type' => 'grid', 'props' => ['status' => 'disabled'], 'children' => []],
        ['type' => 'panel', 'props' => ['status' => 'disabled', 'title' => 'Amagat', 'title_element' => 'h1']],
        ['type' => 'column', 'props' => ['status' => 'disabled'], 'children' => [['type' => 'image', 'props' => ['image' => 'images/missing.jpg']]]],
    ]));
    check($r['findings'] === [] && $r['disabled_elements'] === 3, "$host: disabled elements and their contents are not audited, only counted");
}

foreach ([\Mirasai\WordPress\Tool\TemplateAuditTool::class, \Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateAuditTool::class] as $T) {
    $tmp = sys_get_temp_dir() . '/mirasai-audit-' . bin2hex(random_bytes(3));
    mkdir($tmp . '/images', 0700, true);
    touch($tmp . '/images/a b.jpg');
    check($T::localFileExists($tmp, 'images/a%20b.jpg') === true && $T::localFileExists($tmp, '/images/a b.jpg#joomlaImage://local-images/a.jpg?width=1') === true, "$T: local path, url-encoded and Joomla suffix");
    check($T::localFileExists($tmp, 'images/none.jpg') === false, "$T: missing file");
    check($T::localFileExists($tmp, 'https://x/y.jpg') === null && $T::localFileExists($tmp, '//x/y.jpg') === null && $T::localFileExists($tmp, 'data:image/png;base64,x') === null, "$T: URLs not judged");
    check($T::localFileExists($tmp, '../../etc/passwd') === null, "$T: no path traversal");
    check($T::localFileExists($tmp, '%2e%2e/%2e%2e/etc/passwd') === null && $T::localFileExists($tmp, 'images/%2E%2E/%2E%2E/etc/hosts') === null, "$T: encoded traversal refused");
    check($T::localFileExists($tmp, "images/a b.jpg%00.png") === null && $T::localFileExists($tmp, 'images\\..\\x') === null, "$T: NUL and backslash refused");
    symlink('/etc', $tmp . '/images/out');
    check($T::localFileExists($tmp, 'images/out/hosts') === null, "$T: a file behind a symlink out of the root is not judged");
    unlink($tmp . '/images/out');
    unlink($tmp . '/images/a b.jpg'); rmdir($tmp . '/images'); rmdir($tmp);
}

// scope=site: worst layouts first, clean ones only counted, findings capped.
$site = [
    ['selector' => ['key' => 'clean'], 'label' => 'Net', 'layout' => $col([['type' => 'headline', 'props' => ['content' => 'Títol']]])],
    ['selector' => ['post_id' => 7], 'label' => 'Avisos', 'layout' => $col([['type' => 'headline', 'props' => ['content' => 'A', 'title_element' => 'h2']], ['type' => 'headline', 'props' => ['content' => 'B', 'title_element' => 'h4']]])],
    ['selector' => ['key' => 'bad'], 'label' => 'Errors', 'layout' => $col([
        ['type' => 'headline', 'props' => ['content' => 'T']],
        ['type' => 'grid', 'children' => []], ['type' => 'slideshow', 'children' => []],
        ['type' => 'image', 'props' => ['image' => 'images/missing.jpg']],
    ])],
];
$reports = [];
foreach ([[\Mirasai\WordPress\Tool\TemplateAuditTool::class, \Mirasai\WordPress\Tool\YoothemeLayoutAuditor::class], [\Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateAuditTool::class, \Mirasai\Plugin\Mirasai\Yootheme\Tool\YoothemeLayoutAuditor::class]] as [$T, $A]) {
    $r = $T::siteReport(new $A($def, $files), $site, 2);
    $reports[] = $r;
    check($r['layouts_audited'] === 3 && $r['layouts_clean'] === 1, "$T: audited and clean counts");
    check(array_column($r['layouts'], 'label') === ['Errors', 'Avisos'], "$T: worst layout first, clean one left out");
    check($r['layouts'][0]['key'] === 'bad' && $r['layouts'][1]['post_id'] === 7, "$T: each row keeps its selector");
    check(count($r['layouts'][0]['findings']) === 2 && $r['layouts'][0]['more_findings'] === 2 && $r['layouts'][0]['findings'][0]['severity'] === 'error', "$T: findings capped, worst first, rest counted");
    check($r['totals'] === ['error' => 3, 'warning' => 2, 'notice' => 1], "$T: totals over every layout");
    check($T::siteReport(new $A($def, $files), [], 5)['layouts'] === [], "$T: no layouts, no rows");
}
check($reports[0] === $reports[1], 'site report is the same on both hosts');

// scope=site pages through every layout (Codex review, PR #12): nothing is left out silently.
$byKey = [];
foreach ($site as $item) {
    $byKey[json_encode($item['selector'])] = ['label' => $item['label'], 'layout' => $item['layout']];
}
$selectors = array_merge(array_column($site, 'selector'), [['post_id' => 99]]);
$resolve = static fn (array $sel): ?array => $byKey[json_encode($sel)] ?? null;
$pages = [];
foreach ([[\Mirasai\WordPress\Tool\TemplateAuditTool::class, \Mirasai\WordPress\Tool\YoothemeLayoutAuditor::class], [\Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateAuditTool::class, \Mirasai\Plugin\Mirasai\Yootheme\Tool\YoothemeLayoutAuditor::class]] as [$T, $A]) {
    $first = $T::sitePage(new $A($def, $files), $selectors, ['limit' => 2], $resolve);
    check($first['scope'] === 'site' && $first['layouts_total'] === 4 && $first['offset'] === 0 && $first['next_offset'] === 2 && $first['layouts_audited'] === 2, "$T: first page, total and next_offset");
    check(str_contains($first['note'], 'offset=2'), "$T: the note says how to continue");
    $second = $T::sitePage(new $A($def, $files), $selectors, ['limit' => 2, 'offset' => 2], $resolve);
    check($second['next_offset'] === null && $second['layouts_audited'] === 1 && $second['layouts_unreadable'] === 1, "$T: last page; an unreadable layout is counted, not hidden");
    check(array_column(array_merge($first['layouts'], $second['layouts']), 'label') === ['Avisos', 'Errors'], "$T: the two pages cover every layout with findings");
    $all = $T::sitePage(new $A($def, $files), $selectors, [], $resolve);
    check($all['next_offset'] === null && $all['layouts_audited'] === 3 && str_starts_with($all['note'], 'Every layout'), "$T: default limit covers a small site in one call");
    $pages[] = [$first, $second];
}
check($pages[0] === $pages[1], 'site pages are the same on both hosts');

$wp = file_get_contents($root . '/packages/mirasai-wp/src/Tool/YoothemeLayoutAuditor.php');
$joomla = file_get_contents($root . '/packages/mirasai-joomla/packages/plg_mirasai_yootheme/src/Tool/YoothemeLayoutAuditor.php');
$strip = static fn (string $s): string => preg_replace(['/^namespace .*$/m', '/^ \* Mirrored in .*$/m'], '', $s);
check($strip($wp) === $strip($joomla), 'auditor copies are identical apart from namespace and mirror note');

echo $fails === 0 ? "OK: $count checks\n" : "$fails of $count checks failed\n";
exit($fails === 0 ? 0 : 1);
