<?php
/**
 * Test: YOOtheme layouts embedded in post_content survive WordPress slashing.
 *
 * Run from the repo root:
 *   php docker/test-wp-yootheme-post-write.php
 */

declare(strict_types=1);

$initialLayout = [
    'type' => 'layout',
    'children' => [[
        'type' => 'section',
        'children' => [[
            'type' => 'row',
            'children' => [[
                'type' => 'column',
                'children' => [[
                    'type' => 'text',
                    'props' => [
                        'content' => '<p class="lead">Mireia Ollé</p>',
                    ],
                ]],
            ]],
        ]],
    ]],
    'version' => '5.0.37',
];

$GLOBALS['test_wp_posts'] = [
    2788 => (object) [
        'ID' => 2788,
        'post_title' => 'Tecnologías avanzadas',
        'post_content' => '<p>Fallback content</p>' . "\n\n<!-- "
            . json_encode($initialLayout, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . ' -->',
    ],
];

function get_post_meta(int $postId, string $key, bool $single = false): mixed
{
    return $single ? '' : [];
}

function get_post(int $postId): ?object
{
    return $GLOBALS['test_wp_posts'][$postId] ?? null;
}

function wp_json_encode(mixed $value, int $flags = 0): string|false
{
    return json_encode($value, $flags);
}

function wp_slash(mixed $value): mixed
{
    if (is_array($value)) {
        return array_map('wp_slash', $value);
    }

    return is_string($value) ? addslashes($value) : $value;
}

function wp_update_post(array $postarr, bool $wpError = false): int
{
    $postId = (int) ($postarr['ID'] ?? 0);
    if (!isset($GLOBALS['test_wp_posts'][$postId])) {
        return 0;
    }

    if (array_key_exists('post_content', $postarr)) {
        // WordPress expects slashed input and removes one escaping layer.
        $GLOBALS['test_wp_posts'][$postId]->post_content = stripslashes((string) $postarr['post_content']);
    }

    return $postId;
}

function is_wp_error(mixed $value): bool
{
    return false;
}

function wp_cache_delete(string $key, string $group = ''): bool
{
    return true;
}

function wp_cache_flush(): bool
{
    return true;
}

require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/YoothemeWpHelper.php';

use Mirasai\WordPress\Tool\YoothemeWpHelper;

$helper = new YoothemeWpHelper();
$loaded = $helper->loadPostLayout(2788);

if (!is_array($loaded)) {
    fwrite(STDERR, "[FAIL] initial YOOtheme layout was not detected.\n");
    exit(1);
}

$updatedLayout = $loaded['layout'];
$updatedLayout['children'][0]['children'][0]['children'][0]['children'][0]['props']['content']
    = '<p class="lead">Tecnología RÖSS y Swiss DolorClast®</p>';

$write = $helper->writePostLayout(2788, $loaded, $updatedLayout);
if (($write['failures'] ?? []) !== []) {
    fwrite(STDERR, "[FAIL] writePostLayout reported failures.\n");
    exit(1);
}

$reloaded = $helper->loadPostLayout(2788);
if (!is_array($reloaded)) {
    fwrite(
        STDERR,
        "[FAIL] post_content YOOtheme JSON became invalid after wp_update_post unslashed it.\n"
    );
    exit(1);
}

$actual = $reloaded['layout']['children'][0]['children'][0]['children'][0]['children'][0]['props']['content'] ?? null;
$expected = '<p class="lead">Tecnología RÖSS y Swiss DolorClast®</p>';

if ($actual !== $expected) {
    fwrite(STDERR, "[FAIL] updated YOOtheme content did not round-trip exactly.\n");
    fwrite(STDERR, '       Expected: ' . var_export($expected, true) . "\n");
    fwrite(STDERR, '       Actual:   ' . var_export($actual, true) . "\n");
    exit(1);
}

echo "[PASS] post_content YOOtheme JSON survives wp_update_post slashing.\n";
