<?php
/**
 * Unit tests for YooThemeLayoutProcessor.
 *
 * Run inside the Docker lab after lib_mirasai is loaded:
 *   php /var/www/html/test-yootheme-processor.php
 *
 * Exit code 0 = all tests pass.
 */

declare(strict_types=1);

// ── Bootstrap ────────────────────────────────────────────────────────────────
// In Docker the autoloader handles this; locally require manually.
if (file_exists(__DIR__ . '/bootstrap.php')) {
    require_once __DIR__ . '/bootstrap.php';
} else {
    require_once dirname(__DIR__) . '/pkg_mirasai/packages/lib_mirasai/src/Tool/ContentLayoutProcessorInterface.php';
    require_once dirname(__DIR__) . '/pkg_mirasai/packages/lib_mirasai/src/Tool/YooThemeLayoutProcessor.php';
}

// ── Helpers ───────────────────────────────────────────────────────────────────
$passed = 0;
$failed = 0;

function expect(string $label, mixed $actual, mixed $expected): void
{
    global $passed, $failed;

    if ($actual === $expected) {
        echo "[PASS] {$label}\n";
        $passed++;
    } else {
        echo "[FAIL] {$label}\n";
        echo "       Expected: " . var_export($expected, true) . "\n";
        echo "       Actual:   " . var_export($actual, true) . "\n";
        $failed++;
    }
}

function expectTrue(string $label, bool $value): void
{
    expect($label, $value, true);
}

function expectFalse(string $label, bool $value): void
{
    expect($label, $value, false);
}

// ── Test fixtures ─────────────────────────────────────────────────────────────
$plainArticle = '<p>Hello World</p>';
$yooLayout = [
    'type' => 'layout',
    'nodes' => [],
    'props' => ['id' => 'main'],
    'children' => [
        [
            'type' => 'section',
            'props' => ['title' => 'Original Section Title', 'class' => 'uk-section'],
            'children' => [
                [
                    'type' => 'column',
                    'props' => ['width' => '1-2'],
                    'children' => [
                        [
                            'type' => 'text',
                            'props' => [
                                'content' => 'Hello World, this is translatable text.',
                                'image_position' => 'right',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];

$yooJson = json_encode($yooLayout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$yooContent = '<!-- ' . $yooJson . ' -->';

$processor = new \Mirasai\Library\Tool\YooThemeLayoutProcessor();

// ── detectLayout() ────────────────────────────────────────────────────────────
echo "\n=== detectLayout() ===\n";

expectTrue('detects YOOtheme content', $processor->detectLayout($yooContent));
expectTrue('detects with leading whitespace', $processor->detectLayout('  ' . $yooContent));
expectFalse('rejects plain HTML', $processor->detectLayout($plainArticle));
expectFalse('rejects plain JSON (no comment wrapper)', $processor->detectLayout($yooJson));
expectFalse('rejects empty string', $processor->detectLayout(''));

// ── extractJson() ─────────────────────────────────────────────────────────────
echo "\n=== extractJson() ===\n";

$json = $processor->extractJson($yooContent);
expect('extracts correct JSON string', $json, $yooJson);
expect('returns null for plain HTML', $processor->extractJson($plainArticle), null);

$decodedBack = json_decode($json, true);
expect('extracted JSON decodes correctly', $decodedBack['type'], 'layout');

// ── extractTranslatableText() ─────────────────────────────────────────────────
echo "\n=== extractTranslatableText() ===\n";

$nodes = $processor->extractTranslatableText($yooContent);
expect('returns array for valid YOO content', is_array($nodes), true);
expect('returns empty for plain HTML', $processor->extractTranslatableText($plainArticle), []);

// Verify the text node is found
$textNodes = array_filter($nodes, fn($n) => $n['field'] === 'content');
expectTrue('finds text content node', count($textNodes) > 0);

// Verify config props are excluded
$configNodes = array_filter($nodes, fn($n) => $n['field'] === 'image_position');
expectFalse('excludes config props (image_position)', count($configNodes) > 0);

// ── findTranslatableNodes() on array ─────────────────────────────────────────
echo "\n=== findTranslatableNodes() ===\n";

$nodesFromArray = $processor->findTranslatableNodes($yooLayout);
expect('finds same count from array as from string', count($nodesFromArray), count($nodes));

// Check format detection
$textNode = array_values(array_filter($nodesFromArray, fn($n) => $n['field'] === 'content'))[0] ?? null;
expect('format is plain for plain text', $textNode['format'] ?? null, 'plain');

$htmlLayout = $yooLayout;
$htmlLayout['children'][0]['children'][0]['children'][0]['props']['content'] = '<p>Hello <strong>World</strong></p>';
$htmlNodes = $processor->findTranslatableNodes($htmlLayout);
$htmlTextNode = array_values(array_filter($htmlNodes, fn($n) => $n['field'] === 'content'))[0] ?? null;
expect('format is html for HTML content', $htmlTextNode['format'] ?? null, 'html');

// ── replaceText() ─────────────────────────────────────────────────────────────
echo "\n=== replaceText() ===\n";

// Build path for the content field
// Layout path: root>section[0]>column[0]>text[0].content
$firstNode = $nodesFromArray[0] ?? null;
if ($firstNode) {
    $replacements = [
        $firstNode['path'] . '.' . $firstNode['field'] => 'Translated text',
    ];
    $replaced = $processor->replaceText($yooContent, $replacements);
    expectTrue('replaceText returns string with comment wrapper', str_starts_with($replaced, '<!-- {'));

    $replacedDecoded = json_decode(substr($replaced, 5, strrpos($replaced, ' -->') - 5), true);
    // Drill down to find the replaced value
    $replacedContent = $replacedDecoded['children'][0]['children'][0]['children'][0]['props']['content'] ?? null;
    expect('replaceText applies replacement correctly', $replacedContent, 'Translated text');
}

// replaceText should return unchanged content for plain HTML
$unchanged = $processor->replaceText($plainArticle, ['root.title' => 'Whatever']);
expect('replaceText returns plain article unchanged', $unchanged, $plainArticle);

// ── patchLayoutArray() ────────────────────────────────────────────────────────
echo "\n=== patchLayoutArray() ===\n";

if ($firstNode) {
    $replacements = [
        $firstNode['path'] . '.' . $firstNode['field'] => 'Array patched text',
    ];
    $patched = $processor->patchLayoutArray($yooLayout, $replacements);
    $patchedContent = $patched['children'][0]['children'][0]['children'][0]['props']['content'] ?? null;
    expect('patchLayoutArray applies replacement to array', $patchedContent, 'Array patched text');
    expect('patchLayoutArray returns array', is_array($patched), true);
}

// ── walkTranslatableNodes() ───────────────────────────────────────────────────
echo "\n=== walkTranslatableNodes() ===\n";

$walked = [];
$result = $processor->walkTranslatableNodes($yooContent, function (string $path, string $field, string $text) use (&$walked): string {
    $walked[] = ['path' => $path, 'field' => $field, 'text' => $text];
    return 'VISITED:' . $text;
});

expectTrue('walkTranslatableNodes visited at least one node', count($walked) > 0);
expectTrue('walkTranslatableNodes returns modified content', str_contains($result, 'VISITED:'));

// ── getTextProperties() / getConfigProperties() ───────────────────────────────
echo "\n=== getTextProperties() / getConfigProperties() ===\n";

$textProps = $processor->getTextProperties();
expectTrue('getTextProperties returns array', is_array($textProps));
expectTrue('getTextProperties contains content', in_array('content', $textProps, true));
expectTrue('getTextProperties contains title', in_array('title', $textProps, true));

$configProps = $processor->getConfigProperties();
expectTrue('getConfigProperties returns array', is_array($configProps));
expectTrue('getConfigProperties contains class', in_array('class', $configProps, true));
expectFalse('getConfigProperties does not contain content', in_array('content', $configProps, true));

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('─', 50) . "\n";
echo "Results: {$passed} passed, {$failed} failed\n";

exit($failed > 0 ? 1 : 0);
