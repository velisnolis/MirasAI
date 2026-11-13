<?php
/**
 * Tests for the writes that take a whole job in one call, on both hosts:
 * template/element-batch, template/element-replace-children, the diagnosis a
 * path that does not resolve comes back with, and the plain HTML a WordPress
 * post keeps in front of its layout.
 *
 * Nothing here needs an installed CMS or YOOtheme: the WordPress functions the
 * write path calls are stubbed over an in-memory post, and Joomla's container
 * hands out an empty database.
 *
 * Run from the repo root:
 *   php docker/test-yootheme-element-batch.php
 */

declare(strict_types=1);

namespace Joomla\Database {
    interface DatabaseInterface {}
}

namespace Joomla\CMS {
    final class Factory
    {
        public static function getContainer(): object
        {
            return new class {
                public function get(string $id): object
                {
                    return new class implements \Joomla\Database\DatabaseInterface {};
                }
            };
        }
    }
}

namespace YOOtheme {
    // Stands in for the Builder's `content` context: one line per text element.
    final class Builder
    {
        public static bool $fails = false;

        public function withParams(array $params): self
        {
            return $this;
        }

        public function render(string $json): ?string
        {
            if (self::$fails) {
                throw new \RuntimeException('element template missing');
            }

            $html = '';
            $walk = static function (array $node) use (&$walk, &$html): void {
                if (($node['type'] ?? '') === 'text') {
                    $html .= '<div>' . ($node['props']['content'] ?? '') . "</div>\n";
                }

                foreach ($node['children'] ?? [] as $child) {
                    $walk($child);
                }
            };
            $walk(json_decode($json, true));

            return $html;
        }
    }

    function app(string $class): object
    {
        return new $class();
    }
}

namespace {
    $GLOBALS['test_wp_posts'] = [];

    function wp_strip_all_tags(string $text): string
    {
        return strip_tags($text);
    }

    function get_post_meta(int $postId, string $key, bool $single = false): mixed
    {
        return $single ? '' : [];
    }

    function get_post(int $postId): ?object
    {
        return $GLOBALS['test_wp_posts'][$postId] ?? null;
    }

    function get_the_title(int $postId): string
    {
        return (string) ($GLOBALS['test_wp_posts'][$postId]->post_title ?? '');
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

        // WordPress expects slashed input and removes one escaping layer.
        $GLOBALS['test_wp_posts'][$postId]->post_content = stripslashes((string) $postarr['post_content']);

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

    $root = dirname(__DIR__);
    $wp = $root . '/packages/mirasai-wp/src/Tool';
    $lib = $root . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool';
    $plugin = $root . '/packages/mirasai-joomla/packages/plg_mirasai_yootheme/src/Tool';
    $writeTools = ['Add', 'UpdateProps', 'Move', 'Clone', 'Delete', 'SourceSet', 'SourceDelete', 'ReplaceChildren', 'Batch'];

    foreach (['ToolInterface', 'AbstractTool', 'ToolArgumentValidator', 'YoothemeWpHelper', 'YoothemeElementNavigator', 'YoothemePropsValidator', 'YoothemeRowLayout', 'SourceListPaths', 'TemplateElementSourceSupportTrait', 'TemplateReadTool', 'AbstractTemplateElementWriteTool'] as $file) {
        require_once "{$wp}/{$file}.php";
    }

    foreach (['ToolInterface', 'AbstractTool', 'ToolArgumentValidator', 'YooThemeHelper', 'YooThemeElementNavigator'] as $file) {
        require_once "{$lib}/{$file}.php";
    }

    foreach (['YoothemeElementDefinitionLoader', 'YoothemePropsValidator', 'YoothemeRowLayout', 'SourceListPaths', 'TemplateElementSourceSupportTrait', 'AbstractTemplateElementWriteTool'] as $file) {
        require_once "{$plugin}/{$file}.php";
    }

    foreach ($writeTools as $tool) {
        require_once "{$wp}/TemplateElement{$tool}Tool.php";
        require_once "{$plugin}/TemplateElement{$tool}Tool.php";
    }

    $passed = 0;
    $failed = 0;

    function check(string $label, mixed $actual, mixed $expected): void
    {
        global $passed, $failed;

        if ($actual === $expected) {
            echo "[PASS] {$label}\n";
            $passed++;

            return;
        }

        echo "[FAIL] {$label}\n";
        echo '       Expected: ' . var_export($expected, true) . "\n";
        echo '       Actual:   ' . var_export($actual, true) . "\n";
        $failed++;
    }

    /**
     * A page with two sections. The column of the first one holds a headline
     * between texts, which is where "the third text" and text[2] part ways.
     *
     * @return array<string, mixed>
     */
    function page(): array
    {
        $text = static fn (string $content): array => ['type' => 'text', 'props' => ['content' => $content]];

        return [
            'type' => 'layout',
            'children' => [
                ['type' => 'section', 'props' => ['title' => 'Intro'], 'children' => [
                    ['type' => 'row', 'props' => [], 'children' => [
                        ['type' => 'column', 'props' => [], 'children' => [
                            $text('<p>One</p>'),
                            ['type' => 'headline', 'props' => ['content' => 'Title']],
                            $text('<p>Two</p>'),
                            $text('<p>Three</p>'),
                        ]],
                    ]],
                ]],
                ['type' => 'section', 'props' => ['title' => 'Old'], 'children' => []],
            ],
            'version' => '5.0.54',
        ];
    }

    const COLUMN = 'root>section[0]>row[0]>column[0]';

    $hosts = [
        'wordpress' => ['Mirasai\\WordPress\\Tool\\', new \Mirasai\WordPress\Tool\YoothemeElementNavigator()],
        'joomla' => ['Mirasai\\Plugin\\Mirasai\\Yootheme\\Tool\\', new \Mirasai\Library\Tool\YooThemeElementNavigator()],
    ];

    foreach ($hosts as $host => [$namespace, $navigator]) {
        echo "\n=== {$host}: a path that does not resolve ===\n";

        // "The third text" written as text[2]: index 2 is there, but it is the second text.
        $error = $navigator->deleteElement(page(), COLUMN . '>headline[2]');
        check("{$host} wrong type at an existing index keeps the code", $error['code'] ?? null, 'element_not_found');
        check("{$host} reports how far the path resolved", $error['resolved_path'] ?? null, COLUMN);
        check("{$host} reports the segment that failed", $error['failed_segment'] ?? null, 'headline[2]');
        check("{$host} lists the children that exist there", $error['available_children'] ?? null, [
            COLUMN . '>text[0]', COLUMN . '>headline[1]', COLUMN . '>text[2]', COLUMN . '>text[3]',
        ]);
        check("{$host} suggests the only child of that type", $error['did_you_mean'] ?? null, COLUMN . '>headline[1]');

        $error = $navigator->updateElementProps(page(), COLUMN . '>text[1]', ['content' => 'x']);
        check("{$host} updateElementProps still answers null for a missing path", $error, null);

        $error = $navigator->missingPathError(page(), COLUMN . '>text[1]');
        check("{$host} reads text[1] as the second text", $error['did_you_mean'] ?? null, COLUMN . '>text[2]');

        $error = $navigator->missingPathError(page(), 'root>section[0]>row[0]>column[0]>text[9]');
        check("{$host} gives no suggestion when there is no such child", array_key_exists('did_you_mean', $error), false);
        check("{$host} still lists the children", count($error['available_children'] ?? []), 4);

        // The wrong index is in the middle: the rest of the path is carried over.
        $error = $navigator->missingPathError(page(), 'root>section[0]>row[1]>column[0]>text[0]');
        check("{$host} carries the rest of the path over the corrected segment", $error['did_you_mean'] ?? null, COLUMN . '>text[0]');
        check("{$host} stops resolving at the first wrong segment", $error['resolved_path'] ?? null, 'root>section[0]');

        $error = $navigator->addElement(page(), 'root>section[5]', ['type' => 'row']);
        check("{$host} a missing parent keeps its code", $error['code'] ?? null, 'parent_not_found');
        check("{$host} a missing parent lists the sections", $error['available_children'] ?? null, ['root>section[0]', 'root>section[1]']);

        $error = $navigator->missingPathError(page(), 'section[0]');
        check("{$host} a path that does not start at root says so", str_contains((string) ($error['hint'] ?? ''), 'start at root'), true);

        // A move reads both paths in the layout the caller listed, not in the one left by taking
        // the element out: the target under a later sibling keeps the index the caller saw.
        $three = ['type' => 'layout', 'children' => [
            ['type' => 'section', 'props' => ['title' => 'A'], 'children' => []],
            ['type' => 'section', 'props' => ['title' => 'B'], 'children' => []],
            ['type' => 'section', 'props' => ['title' => 'C'], 'children' => []],
        ]];
        $moved = $navigator->moveElement($three, 'root>section[0]', 'root>section[2]');
        check("{$host} move under a later sibling lands in the parent the caller named", [
            array_column(array_column($moved['layout']['children'] ?? [], 'props'), 'title'),
            $moved['layout']['children'][1]['children'][0]['props']['title'] ?? null,
            $moved['new_path'] ?? null,
        ], [['B', 'C'], 'A', 'root>section[1]>section[0]']);

        $error = $navigator->moveElement(page(), 'root>section[0]', 'root>section[1]>row[9]');
        check("{$host} a missing move target is diagnosed in the layout the caller read", [
            $error['code'] ?? null, $error['resolved_path'] ?? null, $error['available_children'] ?? null, array_key_exists('did_you_mean', $error),
        ], ['target_parent_not_found', 'root>section[1]', [], false]);

        echo "\n=== {$host}: template/element-replace-children ===\n";

        $replace = new ($namespace . 'TemplateElementReplaceChildrenTool')();
        $newSections = [
            ['type' => 'section', 'props' => ['title' => 'New A'], 'children' => []],
            ['type' => 'section', 'props' => ['title' => 'New B'], 'children' => []],
            ['type' => 'section', 'props' => ['title' => 'New C'], 'children' => []],
        ];
        $result = $replace->applyToLayout(page(), ['children' => $newSections]);
        check("{$host} path defaults to root", $result['path'] ?? null, 'root');
        check("{$host} reports what is removed", array_column($result['removed'] ?? [], 'path'), ['root>section[0]', 'root>section[1]']);
        check("{$host} counts the elements under each removed child", array_column($result['removed'] ?? [], 'element_count'), [7, 1]);
        check("{$host} reports what is added", array_column($result['added'] ?? [], 'path'), ['root>section[0]', 'root>section[1]', 'root>section[2]']);
        check("{$host} the layout holds only the new children", array_column(array_column($result['layout']['children'] ?? [], 'props'), 'title'), ['New A', 'New B', 'New C']);
        check("{$host} the rest of the layout is untouched", $result['layout']['version'] ?? null, '5.0.54');

        $result = $replace->applyToLayout(page(), ['path' => COLUMN, 'children' => [['type' => 'text', 'props' => ['content' => 'Only']]]]);
        check("{$host} replaces the children of a nested element", count($result['layout']['children'][0]['children'][0]['children'][0]['children'] ?? []), 1);
        check("{$host} leaves the siblings of that element alone", count($result['layout']['children'] ?? []), 2);

        // Three thirds replaced by two halves: the row still says thirds, and the Builder goes by that.
        $thirds = ['type' => 'layout', 'children' => [['type' => 'section', 'children' => [
            ['type' => 'row', 'props' => ['layout' => '1-3,1-3,1-3'], 'children' => array_fill(0, 3, ['type' => 'column', 'props' => ['width_medium' => '1-3']])],
        ]]]];
        $result = $replace->applyToLayout($thirds, ['path' => 'root>section[0]>row[0]', 'children' => array_fill(0, 2, ['type' => 'column', 'props' => ['width_medium' => '1-2']])]);
        check("{$host} new columns under a row that keeps its layout are warned about", [
            $result['warnings'][0]['code'] ?? null, $result['warnings'][0]['path'] ?? null, $result['warnings'][0]['suggested_layout'] ?? null,
        ], ['row_layout_mismatch', 'root>section[0]>row[0]', '1-2,1-2']);

        $result = $replace->applyToLayout(page(), ['children' => [['props' => []]]]);
        check("{$host} rejects a child without type", $result['code'] ?? null, 'invalid_element');
        $result = $replace->applyToLayout(page(), ['children' => ['type' => 'section']]);
        check("{$host} rejects children that are not a list", $result['code'] ?? null, 'invalid_children');
        $result = $replace->applyToLayout(page(), ['path' => 'root>section[7]', 'children' => []]);
        check("{$host} a missing path comes back with the diagnosis", $result['available_children'] ?? null, ['root>section[0]', 'root>section[1]']);

        echo "\n=== {$host}: template/element-batch ===\n";

        $batch = new ($namespace . 'TemplateElementBatchTool')();
        $result = $batch->applyToLayout(page(), ['operations' => [
            ['op' => 'delete', 'path' => 'root>section[1]'],
            ['op' => 'update_props', 'path' => 'root>section[0]', 'props' => ['title' => 'Renamed']],
            ['op' => 'add', 'parent_path' => 'root', 'element' => ['type' => 'section', 'props' => ['title' => 'Added']]],
            // Sees the layout the three operations above left: the added section is root>section[1].
            ['op' => 'move', 'path' => 'root>section[1]', 'before_path' => 'root>section[0]'],
        ]]);
        check("{$host} applies every operation", $result['operation_count'] ?? null, 4);
        check("{$host} each operation sees the layout the previous ones left", array_column(array_column($result['layout']['children'] ?? [], 'props'), 'title'), ['Added', 'Renamed']);
        check("{$host} reports each operation with its own result", array_column($result['operations'] ?? [], 'op'), ['delete', 'update_props', 'add', 'move']);
        check("{$host} an operation result carries no layout", array_key_exists('layout', $result['operations'][0] ?? []), false);
        check("{$host} the add reports the path it landed on", $result['operations'][2]['path'] ?? null, 'root>section[1]');

        $result = $batch->applyToLayout(page(), ['operations' => [
            ['op' => 'delete', 'path' => 'root>section[1]'],
            ['op' => 'delete', 'path' => 'root>section[1]'],
        ]]);
        check("{$host} a failing operation fails the batch", $result['code'] ?? null, 'element_not_found');
        check("{$host} names the operation that failed", [$result['failed_operation'] ?? null, $result['op'] ?? null], [1, 'delete']);
        check("{$host} returns no layout to write", array_key_exists('layout', $result), false);
        check("{$host} shows what the operations before it did", array_column($result['operations_before_failure'] ?? [], 'deleted_path'), ['root>section[1]']);

        $result = $batch->applyToLayout(page(), ['operations' => [['op' => 'delete', 'path' => 'root>section[1]', 'if_match' => 'abc']]]);
        check("{$host} if_match belongs to the batch, not to an operation", $result['code'] ?? null, 'unknown_argument');
        $result = $batch->applyToLayout(page(), ['operations' => [['op' => 'delete', 'path' => 'root>section[1]', 'dry_run' => true]]]);
        check("{$host} dry_run belongs to the batch too", $result['code'] ?? null, 'unknown_argument');
        $result = $batch->applyToLayout(page(), ['operations' => [['op' => 'delete']]]);
        check("{$host} an operation still needs its tool's required arguments", $result['code'] ?? null, 'missing_required_argument');
        $result = $batch->applyToLayout(page(), ['operations' => [['op' => 'explode', 'path' => 'root']]]);
        check("{$host} rejects an unknown op", $result['code'] ?? null, 'invalid_operation');
        $result = $batch->applyToLayout(page(), ['operations' => []]);
        check("{$host} rejects an empty batch", $result['code'] ?? null, 'invalid_operations');
        $result = $batch->applyToLayout(page(), ['operations' => array_fill(0, 101, ['op' => 'delete', 'path' => 'root>section[0]'])]);
        check("{$host} caps the number of operations", $result['code'] ?? null, 'too_many_operations');

        // Rebuilding a page is one operation among others.
        $result = $batch->applyToLayout(page(), ['operations' => [
            ['op' => 'replace_children', 'children' => [['type' => 'section', 'props' => ['title' => 'Fresh']]]],
            ['op' => 'update_props', 'path' => 'root>section[0]', 'props' => ['style' => 'muted']],
        ]]);
        check("{$host} replace_children works inside a batch", $result['layout']['children'][0]['props'] ?? null, ['title' => 'Fresh', 'style' => 'muted']);
    }

    echo "\n=== wordpress: one read, one preview, one write ===\n";

    $comment = static fn (array $layout): string => '<!-- ' . json_encode($layout, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ' -->';
    $GLOBALS['test_wp_posts'][7] = (object) [
        'ID' => 7,
        'post_title' => 'Page',
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_content' => "<div>Stale text with an old link</div>\n<!--more-->\n" . $comment(page()),
    ];

    $helper = new \Mirasai\WordPress\Tool\YoothemeWpHelper();
    $read = $helper->resolveTarget(['post_id' => 7]);
    $batch = new \Mirasai\WordPress\Tool\TemplateElementBatchTool();
    // A price and a backslash: a replacement string would have read them as references.
    $operations = [
        ['op' => 'replace_children', 'children' => [['type' => 'section', 'children' => [['type' => 'row', 'children' => [['type' => 'column', 'children' => [
            ['type' => 'text', 'props' => ['content' => '<p>Price: $10 at C:\\dir</p>']],
        ]]]]]]]],
    ];

    $preview = $batch->handle(['post_id' => 7, 'if_match' => $read['etag'], 'operations' => $operations, 'dry_run' => true]);
    check('the preview writes nothing', str_contains($GLOBALS['test_wp_posts'][7]->post_content, 'Stale text'), true);
    check('the preview says it would change the layout', [$preview['action'] ?? null, $preview['would_change'] ?? null], ['preview', true]);

    $write = $batch->handle(['post_id' => 7, 'if_match' => $read['etag'], 'operations' => $operations, 'confirm_guarded_write' => true]);
    check('the write goes through', $write['action'] ?? null, 'updated');
    check('the write announces the etag the preview did', $write['new_etag'] ?? null, $preview['new_etag'] ?? null);

    $after = $helper->resolveTarget(['post_id' => 7]);
    check('new_etag is the etag of the next read, so no re-read is needed', $after['etag'] ?? null, $write['new_etag'] ?? null);
    check('a dollar amount and a backslash survive the write', $after['layout']['children'][0]['children'][0]['children'][0]['children'][0]['props']['content'] ?? null, '<p>Price: $10 at C:\\dir</p>');
    check('the plain HTML in front of the layout is regenerated', $write['fallback_content']['status'] ?? null, 'regenerated');
    check('the stale text is gone from post_content', str_contains($GLOBALS['test_wp_posts'][7]->post_content, 'Stale text'), false);
    check('post_content is what the Builder writes on save', str_starts_with($GLOBALS['test_wp_posts'][7]->post_content, "<div><p>Price: \$10 at C:\\dir</p></div>\n\n<!--more-->\n<!-- {"), true);

    $stale = $batch->handle(['post_id' => 7, 'if_match' => $read['etag'], 'operations' => $operations, 'confirm_guarded_write' => true]);
    check('the etag from before the write is now stale', $stale['code'] ?? null, 'stale_etag');

    // YOOtheme cannot render: the layout is still written, and the answer says the HTML is the old one.
    \YOOtheme\Builder::$fails = true;
    $update = new \Mirasai\WordPress\Tool\TemplateElementUpdatePropsTool();
    $write = $update->handle([
        'post_id' => 7,
        'if_match' => $after['etag'],
        'path' => 'root>section[0]>row[0]>column[0]>text[0]',
        'props' => ['content' => '<p>Now $20, was \\1</p>'],
        'confirm_guarded_write' => true,
    ]);
    $after = $helper->resolveTarget(['post_id' => 7]);
    check('a failed render does not stop the write', $after['layout']['children'][0]['children'][0]['children'][0]['children'][0]['props']['content'] ?? null, '<p>Now $20, was \\1</p>');
    check('a failed render is reported as stale', $write['fallback_content']['status'] ?? null, 'stale');
    check('with the reason', str_contains((string) ($write['fallback_content']['reason'] ?? ''), 'element template missing'), true);
    check('the old plain HTML stays in place', str_starts_with($GLOBALS['test_wp_posts'][7]->post_content, '<div><p>Price: $10'), true);
    \YOOtheme\Builder::$fails = false;

    echo "\n{$passed} passed, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}
