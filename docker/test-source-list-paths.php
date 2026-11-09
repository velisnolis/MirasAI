<?php
/**
 * Test: bound props whose field path goes through a list field (F-042), on both hosts.
 *
 * Run from the repo root:
 *   php docker/test-source-list-paths.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

require_once $root . '/packages/mirasai-wp/src/Tool/SourceListPaths.php';
require_once $root . '/packages/mirasai-joomla/packages/plg_mirasai_yootheme/src/Tool/SourceListPaths.php';

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

// The shape template/source-types returns with kind=object and include_fields=true (seen on a test site).
$sourceTypes = ['types' => [
    ['name' => 'Post', 'fields' => [['name' => 'title', 'type' => 'String'], ['name' => 'categories', 'type' => '[Category]'], ['name' => 'featuredImage', 'type' => 'Attachment'], ['name' => 'field', 'type' => 'ProjecteFields!']]],
    ['name' => 'Category', 'fields' => [['name' => 'name', 'type' => 'String'], ['name' => 'children', 'type' => '[Category]']]],
    ['name' => 'Attachment', 'fields' => [['name' => 'url', 'type' => 'String'], ['name' => 'alt', 'type' => 'String']]],
    ['name' => 'ProjecteFields', 'fields' => [['name' => 'galeria', 'type' => '[Attachment]!'], ['name' => 'client', 'type' => 'String']]],
    'not a type',
]];

foreach (['wordpress' => \Mirasai\WordPress\Tool\SourceListPaths::class, 'joomla' => \Mirasai\Plugin\Mirasai\Yootheme\Tool\SourceListPaths::class] as $host => $S) {
    $types = $S::typeMap($sourceTypes);
    check($types['Post']['categories'] === '[Category]' && $types['ProjecteFields']['galeria'] === '[Attachment]!' && count($types) === 4, "$host: type map built, junk ignored");

    $source = ['query' => ['name' => 'posts', 'field' => ['name' => 'singlePost']], 'props' => [
        '_condition' => ['name' => 'categories', 'filters' => ['condition' => '!!']],
        'content' => ['name' => 'categories.name'],
        'title' => ['name' => 'title'],
        'image' => ['name' => 'featuredImage.url'],
        'meta' => ['name' => 'field.galeria'],
        'link' => ['name' => 'field.client'],
        'subtitle' => ['name' => 'unknown.thing'],
    ]];
    check($S::queryPath($source) === 'posts.singlePost' && $S::queryPath(['query' => ['name' => 'site']]) === 'site' && $S::queryPath([]) === '', "$host: query path");

    $w = $S::check($source, 'Post', $types);
    check(array_column($w, 'prop') === ['_condition', 'content', 'meta'], "$host: list field, list subfield and nested list (ACF gallery) flagged; scalar and object paths not");
    check($w[0]['list_field'] === 'categories' && $w[1]['list_field'] === 'categories' && $w[2]['list_field'] === 'galeria' && $w[2]['list_type'] === '[Attachment]', "$host: names the list field and its type");
    check(str_contains($w[0]['message'], 'hides the element on every page') && !str_contains($w[1]['message'], 'hides'), "$host: a condition is told it hides the element");
    check($S::check(['props' => ['title' => ['name' => 'title']]], 'Post', $types) === [] && $S::check(['props' => []], 'Post', $types) === [], "$host: nothing to warn about");
    check($S::check(['props' => ['title' => 'title']], 'Post', $types) === [], "$host: a malformed prop is left alone");
}

$strip = static fn (string $s): string => preg_replace(['/^namespace .*$/m', '/^ \* Mirrored in .*$/m'], '', $s);
check($strip(file_get_contents($root . '/packages/mirasai-wp/src/Tool/SourceListPaths.php')) === $strip(file_get_contents($root . '/packages/mirasai-joomla/packages/plg_mirasai_yootheme/src/Tool/SourceListPaths.php')), 'copies are identical apart from namespace and mirror note');

echo $fails === 0 ? "OK: $count checks\n" : "$fails of $count checks failed\n";
exit($fails === 0 ? 0 : 1);
