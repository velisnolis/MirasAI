<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class YoothemeWpHelper
{
    /** @var list<string> */
    private const POST_LAYOUT_META_KEYS = [
        '_yootheme_page',
        'yootheme_page',
        '_yootheme_builder',
        'yootheme_builder',
    ];

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $theme = wp_get_theme('yootheme');
        $active = get_template() === 'yootheme' || get_stylesheet() === 'yootheme';

        return [
            'active' => $active,
            'installed' => $theme->exists(),
            'version' => $theme->exists() ? (string) $theme->get('Version') : null,
            'template' => get_template(),
            'stylesheet' => get_stylesheet(),
            'template_list_tool' => 'template/list',
            'template_read_tool' => 'template/read',
            'template_summary_tool' => 'template/summary',
            'element_list_tool' => 'template/element-list',
            'element_read_tool' => 'template/element-read',
            'supported_storages' => ['template', 'post', 'widget'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function loadState(): array
    {
        $raw = get_option('yootheme', []);

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($raw) ? $raw : [];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function writeState(array $state): array
    {
        // YOOtheme's WordPress Storage passes this option to Storage::addJson(),
        // so the database value must remain a JSON string, not a PHP array.
        $encoded = wp_json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        update_option('yootheme', is_string($encoded) ? $encoded : '{}', false);

        return $this->invalidateBuilderCache();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function loadTemplates(): array
    {
        $templates = $this->loadState()['templates'] ?? [];

        return is_array($templates) ? $templates : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function loadLibraryItems(): array
    {
        $library = $this->loadState()['library'] ?? [];

        return is_array($library) ? $library : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listLayouts(array $storages = ['template', 'post', 'widget']): array
    {
        $storages = array_values(array_intersect($storages, ['template', 'post', 'widget']));
        $items = [];

        if (in_array('template', $storages, true)) {
            foreach ($this->loadTemplates() as $key => $template) {
                if (!is_array($template)) {
                    continue;
                }

                $layout = $this->getTemplateLayout($template);
                $items[] = [
                    'storage' => 'template',
                    'key' => (string) $key,
                    'name' => $this->templateName($template, (string) $key),
                    'type' => is_string($template['type'] ?? null) ? (string) $template['type'] : 'template',
                    'language' => $this->templateLanguage($template),
                    'etag' => $this->etag($template),
                    'has_layout' => $layout !== null,
                    'element_count' => $layout !== null ? (new YoothemeElementNavigator())->countElements($layout) : 0,
                ];
            }
        }

        if (in_array('post', $storages, true)) {
            $seenPostIds = [];

            foreach ($this->listPostLayoutRows() as $row) {
                $postId = (int) $row['post_id'];
                $target = $this->loadPostLayout($postId);

                if ($target === null) {
                    continue;
                }

                $seenPostIds[$postId] = true;
                $items[] = [
                    'storage' => 'post',
                    'post_id' => $postId,
                    'post_title' => (string) ($row['post_title'] ?? ''),
                    'post_type' => (string) ($row['post_type'] ?? ''),
                    'post_status' => (string) ($row['post_status'] ?? ''),
                    'meta_key' => $target['meta_key'] ?? null,
                    'etag' => $this->etag($target['layout']),
                    'has_layout' => true,
                    'element_count' => (new YoothemeElementNavigator())->countElements($target['layout']),
                ];
            }

            foreach ($this->listYoothemePostStateRows() as $row) {
                $postId = (int) ($row['post_id'] ?? 0);
                if ($postId <= 0 || isset($seenPostIds[$postId])) {
                    continue;
                }

                $target = $this->loadPostLayout($postId);
                if ($target === null) {
                    continue;
                }

                $items[] = [
                    'storage' => 'post',
                    'post_id' => $postId,
                    'post_title' => (string) ($row['post_title'] ?? ''),
                    'post_type' => (string) ($row['post_type'] ?? ''),
                    'post_status' => (string) ($row['post_status'] ?? ''),
                    'source' => $target['source'] ?? null,
                    'meta_key' => $target['meta_key'] ?? null,
                    'etag' => $this->etag($target['layout']),
                    'has_layout' => true,
                    'element_count' => (new YoothemeElementNavigator())->countElements($target['layout']),
                ];
            }
        }

        if (in_array('widget', $storages, true)) {
            foreach ($this->loadBuilderWidgets() as $widgetId => $widget) {
                if (!is_array($widget)) {
                    continue;
                }

                $layout = $this->getWidgetLayout($widget);

                $items[] = [
                    'storage' => 'widget',
                    'widget_id' => (string) $widgetId,
                    'title' => is_string($widget['title'] ?? null) ? (string) $widget['title'] : '',
                    'language' => is_string($widget['wpml_language'] ?? null) ? (string) $widget['wpml_language'] : null,
                    'etag' => $this->etag($widget),
                    'has_layout' => $layout !== null,
                    'element_count' => $layout !== null ? (new YoothemeElementNavigator())->countElements($layout) : 0,
                ];
            }
        }

        return $items;
    }

    /**
     * List posts/pages that WordPress/YOOtheme marks with the "YOOtheme" post state.
     *
     * This is intentionally separate from listLayouts(): a post can be marked as
     * handled by YOOtheme without exposing an individual Builder layout payload
     * that MirasAI can edit as a post_id target.
     *
     * @return list<array<string, mixed>>
     */
    public function listYoothemePostStateRows(int $limit = 500): array
    {
        $postTypes = get_post_types(['public' => true], 'names');
        if (!is_array($postTypes) || $postTypes === []) {
            $postTypes = ['post', 'page'];
        }

        $query = new \WP_Query([
            'post_type' => array_values($postTypes),
            'post_status' => ['publish', 'draft', 'private', 'pending', 'future'],
            'posts_per_page' => $limit,
            'fields' => 'ids',
            'orderby' => 'modified',
            'order' => 'DESC',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        $rows = [];

        foreach ($query->posts as $postId) {
            $post = get_post((int) $postId);
            if ($post === null) {
                continue;
            }

            $states = apply_filters('display_post_states', [], $post);
            if (!is_array($states) || !array_key_exists('yootheme', $states)) {
                continue;
            }

            $target = $this->loadPostLayout((int) $postId);
            $rows[] = [
                'post_id' => (int) $postId,
                'post_title' => (string) $post->post_title,
                'post_type' => (string) $post->post_type,
                'post_status' => (string) $post->post_status,
                'has_editable_layout' => $target !== null,
            ];
        }

        return $rows;
    }

    /**
     * @return array{storage: string, id: string, label: string, layout: array<string, mixed>, etag: string, raw: array<string, mixed>, meta?: array<string, mixed>}|array{error: string, code: string}
     */
    public function resolveTarget(array $arguments): array
    {
        $key = trim((string) ($arguments['key'] ?? ''));
        $postId = (int) ($arguments['post_id'] ?? 0);
        $widgetId = trim((string) ($arguments['widget_id'] ?? ''));
        $selectorCount = ($key !== '' ? 1 : 0) + ($postId > 0 ? 1 : 0) + ($widgetId !== '' ? 1 : 0);

        if ($selectorCount === 0) {
            return ['error' => 'Provide one of key, post_id, or widget_id.', 'code' => 'missing_target'];
        }

        if ($selectorCount > 1) {
            return ['error' => 'Provide only one of key, post_id, or widget_id.', 'code' => 'ambiguous_target'];
        }

        if ($key !== '') {
            return $this->resolveTemplateTarget($key);
        }

        if ($postId > 0) {
            return $this->resolvePostTarget($postId);
        }

        return $this->resolveWidgetTarget($widgetId);
    }

    /**
     * @param array<string, mixed> $template
     * @return array<string, mixed>|null
     */
    public function getTemplateLayout(array $template): ?array
    {
        $layout = $template['layout'] ?? null;

        return is_array($layout) ? $layout : null;
    }

    /**
     * @param array<string, mixed> $template
     * @param array<string, mixed> $layout
     */
    public function setTemplateLayout(array &$template, array $layout): void
    {
        $template['layout'] = $layout;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function loadBuilderWidgets(): array
    {
        $widgets = get_option('widget_builderwidget', []);

        return is_array($widgets) ? $widgets : [];
    }

    /**
     * @param array<string, mixed> $widget
     * @return array<string, mixed>|null
     */
    public function getWidgetLayout(array $widget): ?array
    {
        $content = $widget['content'] ?? null;

        if (!is_string($content) || trim($content) === '') {
            return null;
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $widget
     * @param array<string, mixed> $layout
     */
    public function setWidgetLayout(array &$widget, array $layout): void
    {
        $encoded = wp_json_encode($layout, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $widget['content'] = is_string($encoded) ? $encoded : '{}';
    }

    /**
     * @return array{layout: array<string, mixed>, meta_key?: string, source: string}|null
     */
    public function loadPostLayout(int $postId): ?array
    {
        foreach (self::POST_LAYOUT_META_KEYS as $metaKey) {
            $value = get_post_meta($postId, $metaKey, true);
            $layout = $this->decodeLayoutValue($value);

            if ($layout !== null) {
                return [
                    'layout' => $layout,
                    'meta_key' => $metaKey,
                    'raw_value' => $value,
                    'source' => 'post_meta',
                ];
            }
        }

        $post = get_post($postId);
        if ($post === null) {
            return null;
        }

        $layout = $this->extractCommentLayout((string) $post->post_content);

        return $layout === null ? null : [
            'layout' => $layout,
            'source' => 'post_content_comment',
        ];
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    public function writePostLayout(int $postId, array $target, array $layout): array
    {
        $source = (string) ($target['source'] ?? '');

        if ($source === 'post_meta') {
            $metaKey = is_string($target['meta_key'] ?? null) ? $target['meta_key'] : '';

            if ($metaKey === '') {
                return [
                    'cleared' => false,
                    'groups' => [],
                    'failures' => [['group' => 'post_meta', 'error' => 'Missing YOOtheme post meta key.']],
                ];
            }

            update_post_meta($postId, $metaKey, $this->encodeLayoutLike($layout, $target['raw_value'] ?? null));

            return $this->invalidateBuilderCache();
        }

        if ($source === 'post_content_comment') {
            $post = get_post($postId);

            if ($post === null) {
                return [
                    'cleared' => false,
                    'groups' => [],
                    'failures' => [['group' => 'post_content', 'error' => "Post {$postId} not found."]],
                ];
            }

            $encoded = wp_json_encode($layout, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $content = preg_replace(
                '/<!--\\s*(\\{.*\\})\\s*-->/sU',
                '<!-- ' . (is_string($encoded) ? $encoded : '{}') . ' -->',
                (string) $post->post_content,
                1,
                $count,
            );

            if ($content === null || $count !== 1) {
                return [
                    'cleared' => false,
                    'groups' => [],
                    'failures' => [['group' => 'post_content', 'error' => 'YOOtheme JSON comment could not be replaced.']],
                ];
            }

            $result = wp_update_post([
                'ID' => $postId,
                'post_content' => $content,
            ], true);

            if (is_wp_error($result)) {
                return [
                    'cleared' => false,
                    'groups' => [],
                    'failures' => [['group' => 'post_content', 'error' => $result->get_error_message()]],
                ];
            }

            return $this->invalidateBuilderCache();
        }

        return [
            'cleared' => false,
            'groups' => [],
            'failures' => [['group' => 'post_layout', 'error' => "Unsupported post layout source {$source}."]],
        ];
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>|null
     */
    private function decodeLayoutValue($value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $layout
     * @param mixed $rawValue
     * @return mixed
     */
    private function encodeLayoutLike(array $layout, $rawValue)
    {
        if (is_array($rawValue)) {
            return $layout;
        }

        $encoded = wp_json_encode($layout, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '{}';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractCommentLayout(string $content): ?array
    {
        if (!preg_match('/<!--\\s*(\\{.*\\})\\s*-->/sU', $content, $matches)) {
            return null;
        }

        $decoded = json_decode($matches[1], true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listPostLayoutRows(): array
    {
        global $wpdb;

        $placeholders = implode(', ', array_fill(0, count(self::POST_LAYOUT_META_KEYS), '%s'));
        $sql = $wpdb->prepare(
            "SELECT DISTINCT p.ID AS post_id, p.post_title, p.post_type, p.post_status
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE pm.meta_key IN ({$placeholders})
               AND pm.meta_value <> ''
             ORDER BY p.post_modified_gmt DESC
             LIMIT 200",
            self::POST_LAYOUT_META_KEYS
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string, mixed> $template
     */
    public function templateName(array $template, string $fallback = ''): string
    {
        foreach (['name', 'title'] as $key) {
            if (is_string($template[$key] ?? null) && trim((string) $template[$key]) !== '') {
                return trim((string) $template[$key]);
            }
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $template
     */
    public function templateLanguage(array $template): string
    {
        $query = is_array($template['query'] ?? null) ? $template['query'] : [];
        $locale = is_string($query['locale'] ?? null) ? trim((string) $query['locale']) : '';

        if ($locale === '' && is_string($query['lang'] ?? null)) {
            $locale = trim((string) $query['lang']);
        }

        return $locale === '' ? '*' : $locale;
    }

    /**
     * @param array<string, mixed> $template
     */
    public function setTemplateLanguage(array &$template, string $language): void
    {
        if (!isset($template['query']) || !is_array($template['query'])) {
            $template['query'] = [];
        }

        $template['query']['locale'] = $language;
    }

    /**
     * @param array<string, mixed> $template
     * @return list<array{path: string, node_type: string, field: string, replacement_key: string, text: string, format: string}>
     */
    public function findTemplateTranslatableNodes(array $template): array
    {
        $layout = $this->getTemplateLayout($template);

        if ($layout === null) {
            return [];
        }

        return (new YoothemeLayoutProcessor())->findTranslatableNodes($layout);
    }

    /**
     * @param array<string, mixed> $template
     */
    public function templateHasStaticText(array $template): bool
    {
        return $this->findTemplateTranslatableNodes($template) !== [];
    }

    /**
     * @param array<string, mixed> $template
     */
    public function buildTemplateAssignmentFingerprint(array $template): string
    {
        $copy = $template;
        unset($copy['name'], $copy['title'], $copy['layout'], $copy['status']);

        if (isset($copy['query']) && is_array($copy['query'])) {
            unset($copy['query']['locale'], $copy['query']['lang']);
        }

        $copy = $this->sortRecursive($copy);

        $encoded = wp_json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', is_string($encoded) ? $encoded : '');
    }

    public function generateStorageKey(int $length = 12): string
    {
        return 'mirasai_' . substr(hash('sha256', wp_generate_uuid4() . microtime(true)), 0, max(8, $length));
    }

    /**
     * @param array<string, mixed> $value
     */
    public function etag(array $value): string
    {
        $encoded = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', is_string($encoded) ? $encoded : '');
    }

    /**
     * @return array{cleared: bool, groups: list<string>, failures: list<array{group: string, error: string}>}
     */
    public function invalidateBuilderCache(): array
    {
        $groups = [];
        $failures = [];

        foreach (['yootheme', 'theme_json', 'posts', 'options'] as $group) {
            try {
                wp_cache_delete($group, 'options');
                $groups[] = $group;
            } catch (\Throwable $exception) {
                $failures[] = [
                    'group' => $group,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        try {
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
                $groups[] = 'object_cache';
            }
        } catch (\Throwable $exception) {
            $failures[] = [
                'group' => 'object_cache',
                'error' => $exception->getMessage(),
            ];
        }

        return [
            'cleared' => $groups !== [] && $failures === [],
            'groups' => array_values(array_unique($groups)),
            'failures' => $failures,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRuntimeElementTypes(): array
    {
        $root = $this->findElementsRoot();

        if ($root === null) {
            return [];
        }

        $items = [];
        $dirs = glob($root . '/*', GLOB_ONLYDIR);

        if (!is_array($dirs)) {
            return [];
        }

        foreach ($dirs as $dir) {
            $name = basename($dir);
            if (!is_file($dir . '/element.php')) {
                continue;
            }

            $definition = $this->loadElementDefinition($name);
            $items[] = [
                'type' => $name,
                'title' => is_array($definition) && is_string($definition['title'] ?? null) ? $definition['title'] : $name,
                'group' => is_array($definition) && is_string($definition['group'] ?? null) ? $definition['group'] : '',
                'is_element' => is_array($definition) ? !empty($definition['element']) : true,
                'is_container' => is_array($definition) ? !empty($definition['container']) : false,
                'field_count' => is_array($definition) && is_array($definition['fields'] ?? null) ? count($definition['fields']) : 0,
                'source_field_count' => is_array($definition) ? $this->countSourceFields($definition) : 0,
            ];
        }

        usort($items, static fn(array $a, array $b): int => strcmp((string) $a['type'], (string) $b['type']));

        return $items;
    }

    /**
     * @return array<string, mixed>|array{error: string, code: string}
     */
    public function loadElementDefinition(string $type): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $type)) {
            return ['error' => 'type may only contain letters, numbers, underscores, and hyphens.', 'code' => 'invalid_type'];
        }

        $root = $this->findElementsRoot();
        if ($root === null) {
            return ['error' => 'Installed YOOtheme Builder elements directory was not found.', 'code' => 'yootheme_elements_root_missing'];
        }

        $file = $root . '/' . $type . '/element.php';
        if (!is_file($file)) {
            return ['error' => "Element type {$type} was not found in the installed YOOtheme Builder registry.", 'code' => 'element_schema_not_found'];
        }

        try {
            $definition = include $file;
        } catch (\Throwable $exception) {
            return ['error' => 'Unable to load YOOtheme element definition: ' . $exception->getMessage(), 'code' => 'element_schema_load_failed'];
        }

        if (!is_array($definition)) {
            return ['error' => 'YOOtheme element definition did not return an array.', 'code' => 'element_schema_invalid'];
        }

        return $definition;
    }

    public function elementsRuntimeSource(): ?string
    {
        $root = $this->findElementsRoot();

        return $root === null ? null : $this->relativeToRoot($root);
    }

    private function findElementsRoot(): ?string
    {
        $root = defined('ABSPATH') ? rtrim((string) ABSPATH, '/') : '';

        if ($root === '') {
            return null;
        }

        $candidates = [
            $root . '/wp-content/themes/yootheme/packages/builder/elements',
            get_template_directory() . '/packages/builder/elements',
            get_stylesheet_directory() . '/packages/builder/elements',
        ];

        foreach (array_unique($candidates) as $candidate) {
            if (is_dir($candidate)) {
                return rtrim($candidate, '/');
            }
        }

        return null;
    }

    private function relativeToRoot(string $path): string
    {
        $root = defined('ABSPATH') ? rtrim((string) ABSPATH, '/') . '/' : '';

        if ($root !== '' && str_starts_with($path, $root)) {
            return ltrim(substr($path, strlen($root)), '/');
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function countSourceFields(array $definition): int
    {
        $count = 0;
        $fields = $definition['fields'] ?? [];

        if (!is_array($fields)) {
            return 0;
        }

        foreach ($fields as $field) {
            if (is_array($field) && !empty($field['source'])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function sortRecursive($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $child) {
            $value[$key] = $this->sortRecursive($child);
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @return array{storage: string, id: string, label: string, layout: array<string, mixed>, etag: string, raw: array<string, mixed>, meta?: array<string, mixed>}|array{error: string, code: string}
     */
    private function resolveTemplateTarget(string $key): array
    {
        $templates = $this->loadTemplates();
        $template = $templates[$key] ?? null;

        if (!is_array($template)) {
            return ['error' => "Template {$key} not found.", 'code' => 'template_not_found'];
        }

        $layout = $this->getTemplateLayout($template);

        if ($layout === null) {
            return ['error' => "Template {$key} has no YOOtheme layout.", 'code' => 'template_layout_missing'];
        }

        return [
            'storage' => 'template',
            'id' => $key,
            'label' => $this->templateName($template, $key),
            'layout' => $layout,
            'etag' => $this->etag($template),
            'raw' => $template,
            'meta' => [
                'key' => $key,
                'type' => is_string($template['type'] ?? null) ? (string) $template['type'] : 'template',
                'language' => $this->templateLanguage($template),
            ],
        ];
    }

    /**
     * @return array{storage: string, id: string, label: string, layout: array<string, mixed>, etag: string, raw: array<string, mixed>, meta?: array<string, mixed>}|array{error: string, code: string}
     */
    private function resolvePostTarget(int $postId): array
    {
        $post = get_post($postId);

        if ($post === null) {
            return ['error' => "Post {$postId} not found.", 'code' => 'post_not_found'];
        }

        $target = $this->loadPostLayout($postId);

        if ($target === null) {
            return ['error' => "Post {$postId} has no detected YOOtheme layout.", 'code' => 'post_layout_missing'];
        }

        return [
            'storage' => 'post',
            'id' => (string) $postId,
            'label' => get_the_title($postId),
            'layout' => $target['layout'],
            'etag' => $this->etag($target['layout']),
            'raw' => $target['layout'],
            'meta' => [
                'post_id' => $postId,
                'post_type' => (string) $post->post_type,
                'post_status' => (string) $post->post_status,
                'source' => $target['source'],
                'meta_key' => $target['meta_key'] ?? null,
            ],
        ];
    }

    /**
     * @return array{storage: string, id: string, label: string, layout: array<string, mixed>, etag: string, raw: array<string, mixed>, meta?: array<string, mixed>}|array{error: string, code: string}
     */
    private function resolveWidgetTarget(string $widgetId): array
    {
        $widgets = $this->loadBuilderWidgets();
        $widget = $widgets[$widgetId] ?? null;

        if (!is_array($widget)) {
            return ['error' => "YOOtheme Builder widget {$widgetId} not found.", 'code' => 'widget_not_found'];
        }

        $layout = $this->getWidgetLayout($widget);

        if ($layout === null) {
            return ['error' => "YOOtheme Builder widget {$widgetId} has no layout content.", 'code' => 'widget_layout_missing'];
        }

        return [
            'storage' => 'widget',
            'id' => $widgetId,
            'label' => is_string($widget['title'] ?? null) ? (string) $widget['title'] : $widgetId,
            'layout' => $layout,
            'etag' => $this->etag($widget),
            'raw' => $widget,
            'meta' => [
                'widget_id' => $widgetId,
                'language' => is_string($widget['wpml_language'] ?? null) ? (string) $widget['wpml_language'] : null,
            ],
        ];
    }
}
