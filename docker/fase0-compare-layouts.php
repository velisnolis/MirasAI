<?php
/**
 * Compare two YOOtheme layout JSON files for Fase 0 corruption.
 *
 * Corruption = lost nodes, type changes, or props that would break render
 * (props === null, or content dropped). Added default keys / version are
 * reported as normalization, not corruption.
 *
 *   php fase0-compare-layouts.php before.json after.json
 */

declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "Usage: php fase0-compare-layouts.php before.json after.json\n");
    exit(1);
}

/**
 * @param array<string, mixed> $node
 * @return list<array<string, mixed>>
 */
function fase0_walk(array $node, string $path): array
{
    $type = is_string($node['type'] ?? null) ? $node['type'] : 'unknown';
    $props = $node['props'] ?? null;
    $propKeys = is_array($props) ? array_keys($props) : [];
    sort($propKeys);

    $rows = [[
        'path' => $path,
        'type' => $type,
        'name' => is_string($node['name'] ?? null) ? $node['name'] : null,
        'id' => is_string($node['id'] ?? null) ? $node['id'] : null,
        'props_is_null' => array_key_exists('props', $node) && $node['props'] === null,
        'prop_keys' => $propKeys,
        'content' => is_array($props) && array_key_exists('content', $props) ? $props['content'] : null,
    ]];

    $children = is_array($node['children'] ?? null) ? $node['children'] : [];
    foreach ($children as $index => $child) {
        if (!is_array($child)) {
            continue;
        }
        $childType = is_string($child['type'] ?? null) ? $child['type'] : 'unknown';
        $childPath = $path === 'root' ? "root>{$childType}[{$index}]" : "{$path}>{$childType}[{$index}]";
        $rows = array_merge($rows, fase0_walk($child, $childPath));
    }

    return $rows;
}

/**
 * @param array<string, mixed> $layout
 * @return array<string, array<string, mixed>>
 */
function fase0_index(array $layout): array
{
    $indexed = [];
    foreach (fase0_walk($layout, 'root') as $row) {
        $indexed[$row['path']] = $row;
    }

    return $indexed;
}

$before = json_decode((string) file_get_contents($argv[1]), true);
$after = json_decode((string) file_get_contents($argv[2]), true);

if (!is_array($before) || !is_array($after)) {
    fwrite(STDERR, "Both files must be JSON objects.\n");
    exit(1);
}

$beforeIndex = fase0_index($before);
$afterIndex = fase0_index($after);

$lost = [];
$typeChanges = [];
$propsNull = [];
$contentDropped = [];
$addedPaths = [];
$addedPropKeys = [];

foreach ($beforeIndex as $path => $node) {
    if (!isset($afterIndex[$path])) {
        $lost[] = ['path' => $path, 'type' => $node['type']];
        continue;
    }

    $next = $afterIndex[$path];
    if ($next['type'] !== $node['type']) {
        $typeChanges[] = ['path' => $path, 'from' => $node['type'], 'to' => $next['type']];
    }

    if ($next['props_is_null'] && !$node['props_is_null']) {
        $propsNull[] = $path;
    }

    $beforeContent = is_string($node['content']) ? $node['content'] : '';
    $afterContent = is_string($next['content']) ? $next['content'] : '';
    if ($beforeContent !== '' && $afterContent === '') {
        $contentDropped[] = ['path' => $path, 'from' => $beforeContent];
    }

    $added = array_values(array_diff($next['prop_keys'], $node['prop_keys']));
    if ($added !== []) {
        $addedPropKeys[] = ['path' => $path, 'keys' => $added];
    }
}

foreach ($afterIndex as $path => $node) {
    if (!isset($beforeIndex[$path])) {
        $addedPaths[] = ['path' => $path, 'type' => $node['type']];
    }
}

$corrupt = $lost !== [] || $typeChanges !== [] || $propsNull !== [] || $contentDropped !== [];

$report = [
    'corrupt' => $corrupt,
    'before_node_count' => count($beforeIndex),
    'after_node_count' => count($afterIndex),
    'lost_nodes' => $lost,
    'type_changes' => $typeChanges,
    'props_null' => $propsNull,
    'content_dropped' => $contentDropped,
    'added_nodes' => $addedPaths,
    'added_prop_keys' => $addedPropKeys,
    'layout_version_before' => $before['version'] ?? null,
    'layout_version_after' => $after['version'] ?? null,
];

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
exit($corrupt ? 2 : 0);
