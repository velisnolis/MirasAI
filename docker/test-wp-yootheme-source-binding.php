<?php
/**
 * Test: WordPress YOOtheme source bindings use the same canonical carrier as
 * the Joomla host while keeping props.source as a compatibility fallback.
 *
 * Run from the repo root:
 *   php docker/test-wp-yootheme-source-binding.php
 */

declare(strict_types=1);

function wp_strip_all_tags(string $text): string
{
    return strip_tags($text);
}

require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/YoothemeElementNavigator.php';
require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/TemplateElementSourceSupportTrait.php';

use Mirasai\WordPress\Tool\TemplateElementSourceSupportTrait;
use Mirasai\WordPress\Tool\YoothemeElementNavigator;

$passed = 0;
$failed = 0;

function expectWpYoothemeSource(string $label, mixed $actual, mixed $expected): void
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

final class TestWpSourceSummarizer
{
    use TemplateElementSourceSupportTrait;

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    public function summarize(array $node): array
    {
        return $this->summarizeBinding($node);
    }
}

$layout = [
    'type' => 'layout',
    'children' => [[
        'type' => 'section',
        'children' => [[
            'type' => 'row',
            'children' => [[
                'type' => 'column',
                'children' => [[
                    'type' => 'headline',
                    'props' => [
                        'content' => 'Hello',
                        'source' => ['query' => ['name' => 'LegacyProps']],
                    ],
                    'source_extended' => ['query' => ['name' => 'LegacyExtended']],
                ]],
            ]],
        ]],
    ]],
];

$source = [
    'query' => [
        'name' => 'Post',
        'field' => ['name' => 'post'],
    ],
    'props' => [
        'content' => ['name' => 'title'],
    ],
];

$navigator = new YoothemeElementNavigator();
$path = 'root>section[0]>row[0]>column[0]>headline[0]';
$set = $navigator->setElementSource($layout, $path, $source);

expectWpYoothemeSource('setElementSource writes source query', $set['element']['source']['query']['name'] ?? null, 'Post');
expectWpYoothemeSource('setElementSource removes props.source compatibility carrier', isset($set['element']['props']['source']), false);
expectWpYoothemeSource('setElementSource removes source_extended compatibility carrier', isset($set['element']['source_extended']), false);
expectWpYoothemeSource('setElementSource detects source binding', $set['metadata']['has_source_binding'] ?? null, true);

$summary = (new TestWpSourceSummarizer())->summarize([
    'props' => ['source' => ['query' => ['name' => 'PropsSource']]],
    'source' => $source,
]);
expectWpYoothemeSource('summarizeBinding prefers source over props.source', $summary['canonical_location'] ?? null, 'source');
expectWpYoothemeSource('summarizeBinding reports source name', $summary['source_name'] ?? null, 'Post');

$deleted = $navigator->deleteElementSource($set['layout'], $path);
expectWpYoothemeSource('deleteElementSource removes source', isset($deleted['element']['source']), false);
expectWpYoothemeSource('deleteElementSource reports source location first', $deleted['removed_locations'], ['source']);
expectWpYoothemeSource('deleteElementSource clears binding metadata', $deleted['metadata']['has_source_binding'] ?? null, false);

if ($failed > 0) {
    echo "\n{$failed} WordPress YOOtheme source binding test(s) failed.\n";
    exit(1);
}

echo "\nAll {$passed} WordPress YOOtheme source binding tests passed.\n";
