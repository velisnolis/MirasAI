<?php
/**
 * Unit tests for YooThemeLayoutSummarizer.
 *
 * Run from the repo root:
 *   php docker/test-yootheme-summary.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/pkg_mirasai/packages/lib_mirasai/src/Tool/YooThemeLayoutSummarizer.php';

use Mirasai\Library\Tool\YooThemeLayoutSummarizer;

$passed = 0;
$failed = 0;

function expectSummary(string $label, mixed $actual, mixed $expected): void
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
    'children' => [
        [
            'type' => 'section',
            'props' => ['title' => 'Hero section'],
            'children' => [
                [
                    'type' => 'headline',
                    'props' => ['content' => '<strong>Main headline</strong>'],
                ],
                [
                    'type' => 'grid',
                    'source' => ['props' => ['title' => 'name']],
                    'children' => [
                        [
                            'type' => 'panel',
                            'props' => ['title' => 'API item'],
                        ],
                    ],
                ],
            ],
        ],
    ],
];

$summary = (new YooThemeLayoutSummarizer())->summarize($layout);

expectSummary('root type', $summary['root_type'], 'layout');
expectSummary('total elements', $summary['total_elements'], 5);
expectSummary('max depth', $summary['max_depth'], 3);
expectSummary('grid count', $summary['element_counts_by_type']['grid'], 1);
expectSummary('source binding count', $summary['source_binding_count'], 1);
expectSummary('first landmark label strips html', $summary['named_landmarks'][0]['label'], 'Hero section');
expectSummary('headline landmark strips html', $summary['named_landmarks'][1]['label'], 'Main headline');

if ($failed > 0) {
    echo "\n{$failed} summary test(s) failed.\n";
    exit(1);
}

echo "\nAll {$passed} summary tests passed.\n";
