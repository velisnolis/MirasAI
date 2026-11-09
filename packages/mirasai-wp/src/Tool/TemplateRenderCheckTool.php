<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

/**
 * Renders a stored layout the way a visitor would get it and says, per element
 * path, what shows.
 *
 * Builder::load() with context «render» runs YOOtheme's own transforms: disabled
 * elements drop out and Dynamic Sources resolve into props. Each stored node is
 * marked with its path in props first; the mark survives the transforms, so a
 * path missing from the result is an element that does not render.
 */
class TemplateRenderCheckTool extends AbstractTool
{
    private const PATH_PROP = 'mirasai_path';
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;
    private const MAX_TEXT = 300;

    public function getName(): string
    {
        return 'template/render-check';
    }

    public function getDescription(): string
    {
        return 'Renders a stored YOOtheme layout on the server as an anonymous visitor and lists every element that shows text, '
            . 'with its path, its type and the text; not_rendered lists the stored paths that do not render (disabled, a display '
            . 'condition, an empty source). For a template, pass context_id: the post it should render with, so Dynamic Sources '
            . 'resolve. With expect, says in which paths each text appears. It ignores the publication state, so drafts render too; '
            . 'use site/public-fetch for what a visitor really gets at a URL. Read-only.';
    }

    /**
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'key' => ['type' => 'string', 'description' => 'YOOtheme template key from template/list.'],
                'post_id' => ['type' => 'integer', 'description' => 'Post or page whose own YOOtheme layout to render.'],
                'context_id' => [
                    'type' => 'integer',
                    'description' => 'Templates only: the post to render the template with, as if a visitor opened it.',
                ],
                'expect' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'maxItems' => PublicPageInspector::MAX_EXPECT,
                    'description' => 'Texts that should show. Matching ignores case, tags and repeated whitespace.',
                ],
                'offset' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Skip this many elements. Defaults to 0.'],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'description' => 'Elements per answer. Defaults to ' . self::DEFAULT_LIMIT . '.',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        $expect = PublicPageInspector::normalizeExpect($arguments['expect'] ?? null);

        if (isset($expect['error'])) {
            return $expect;
        }

        if (!class_exists(\YOOtheme\Builder::class) || !function_exists('YOOtheme\\app')) {
            return ['error' => 'YOOtheme Pro is not the active theme, so nothing can be rendered.', 'code' => 'yootheme_unavailable'];
        }

        $target = (new YoothemeWpHelper())->resolveTarget($arguments);

        if (isset($target['error'])) {
            return $target;
        }

        $contextId = (int) ($arguments['context_id'] ?? 0);
        $isTemplate = $target['storage'] === 'template';

        if (!$isTemplate && $contextId > 0) {
            return ['error' => 'context_id is for templates; a post renders with itself.', 'code' => 'context_only_for_templates'];
        }

        $context = $isTemplate ? ($contextId > 0 ? get_post($contextId) : null) : get_post((int) $target['id']);

        if ($contextId > 0 && !$context instanceof \WP_Post) {
            return ['error' => "Post {$contextId} not found.", 'code' => 'context_not_found'];
        }

        $layout = $target['layout'];
        $stored = [];
        self::mark($layout, 'root', $stored);

        $rendered = $this->render($layout, $isTemplate ? 'template' : 'page', $context instanceof \WP_Post ? $context : null);

        if (isset($rendered['error'])) {
            return $rendered;
        }

        $summary = self::summarize($rendered['tree'], $stored, $expect);
        $htmlText = PublicPageInspector::normalizeText(strip_tags($rendered['html']));

        foreach ($summary['expect'] as &$match) {
            // Text a template prints by itself (labels, defaults) is in the HTML but in no element's props.
            $match['in_html'] = str_contains(mb_strtolower($htmlText, 'UTF-8'), mb_strtolower($match['text'], 'UTF-8'));
        }
        unset($match);

        $offset = max(0, (int) ($arguments['offset'] ?? 0));
        $limit = min(self::MAX_LIMIT, max(1, (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT)));
        $total = count($summary['elements']);

        $response = [
            'storage' => $target['storage'],
            'id' => $target['id'],
            'label' => $target['label'],
            'etag' => $target['etag'],
            'rendered_as' => 'guest',
            'context' => $context instanceof \WP_Post
                ? ['id' => $context->ID, 'type' => $context->post_type, 'title' => get_the_title($context), 'status' => $context->post_status]
                : null,
            'renders_empty' => self::rendersNothing($rendered['html']),
            'html_bytes' => strlen($rendered['html']),
            'element_count' => $total,
            'elements' => array_slice($summary['elements'], $offset, $limit),
            'next_offset' => $offset + $limit < $total ? $offset + $limit : null,
            'not_rendered' => $summary['not_rendered'],
        ];

        if ($expect !== []) {
            $response['expect'] = $summary['expect'];
        }

        if ($isTemplate && $context === null) {
            $response['note'] = 'Rendered without context_id: Dynamic Sources that need a post render empty.';
        }

        return $response;
    }

    /**
     * Nothing a visitor would see: no text and no media. A layout with only an image, a video or a map
     * is not empty, although it has no text.
     */
    public static function rendersNothing(string $html): bool
    {
        if (PublicPageInspector::normalizeText(strip_tags($html)) !== '') {
            return false;
        }

        return preg_match('/<(img|picture|video|audio|iframe|svg|canvas|object|embed)\b/i', $html) !== 1;
    }

    /**
     * Put each node's path into its props and collect the paths, in the format template/element-list uses.
     *
     * @param array<string, mixed> $node
     * @param list<string> $paths
     */
    public static function mark(array &$node, string $path, array &$paths): void
    {
        $props = is_array($node['props'] ?? null) ? $node['props'] : [];
        $props[self::PATH_PROP] = $path;
        $node['props'] = $props;

        if ($path !== 'root') {
            $paths[] = $path;
        }

        if (!isset($node['children']) || !is_array($node['children'])) {
            return;
        }

        foreach ($node['children'] as $index => &$child) {
            if (is_array($child)) {
                $type = is_string($child['type'] ?? null) && $child['type'] !== '' ? $child['type'] : 'unknown';
                self::mark($child, $path . '>' . $type . '[' . $index . ']', $paths);
            }
        }
    }

    /**
     * Elements with visible text, stored paths that did not render, and where each expected text shows.
     *
     * @param list<string> $stored
     * @param list<string> $expect
     * @return array{elements: list<array<string, mixed>>, not_rendered: list<string>, expect: list<array<string, mixed>>}
     */
    public static function summarize(object $tree, array $stored, array $expect): array
    {
        $elements = [];
        $seen = [];
        self::collect($tree, $elements, $seen);

        // Report only the top of each missing branch: its children are missing with it.
        $missing = array_values(array_diff($stored, array_keys($seen)));
        $notRendered = array_values(array_filter($missing, static function (string $path) use ($missing): bool {
            foreach ($missing as $other) {
                if ($other !== $path && str_starts_with($path, $other . '>')) {
                    return false;
                }
            }

            return true;
        }));

        $matches = [];

        foreach ($expect as $needle) {
            $lower = mb_strtolower($needle, 'UTF-8');
            $paths = [];

            foreach ($elements as $element) {
                if (str_contains(mb_strtolower($element['full_text'], 'UTF-8'), $lower)) {
                    $paths[] = $element['path'];
                }
            }

            $paths = array_values(array_unique($paths));
            $matches[] = ['text' => $needle, 'found' => $paths !== [], 'paths' => $paths];
        }

        foreach ($elements as &$element) {
            unset($element['full_text']);
        }

        return ['elements' => $elements, 'not_rendered' => $notRendered, 'expect' => $matches];
    }

    /**
     * @param list<array<string, mixed>> $elements
     * @param array<string, true> $seen
     */
    private static function collect(object $node, array &$elements, array &$seen): void
    {
        $props = (array) ($node->props ?? []);
        $path = is_string($props[self::PATH_PROP] ?? null) ? $props[self::PATH_PROP] : null;

        if ($path !== null) {
            $seen[$path] = true;
            $texts = [];

            foreach (YoothemeLayoutProcessor::TEXT_PROPS as $name) {
                if (is_string($props[$name] ?? null)) {
                    $text = PublicPageInspector::normalizeText(strip_tags($props[$name]));

                    if ($text !== '') {
                        $texts[] = $text;
                    }
                }
            }

            if ($texts !== []) {
                $full = implode(' · ', $texts);
                $elements[] = [
                    'path' => $path,
                    'type' => (string) ($node->type ?? 'unknown'),
                    'text' => mb_strlen($full, 'UTF-8') > self::MAX_TEXT ? rtrim(mb_substr($full, 0, self::MAX_TEXT, 'UTF-8')) . '…' : $full,
                    'full_text' => $full,
                ];
            }
        }

        foreach ((array) ($node->children ?? []) as $child) {
            if (is_object($child)) {
                self::collect($child, $elements, $seen);
            }
        }
    }

    /**
     * Render as a logged-out visitor with the context post in the globals YOOtheme's sources read.
     *
     * @param array<string, mixed> $layout
     * @return array{tree: object, html: string}|array{error: string, code: string}
     */
    private function render(array $layout, string $prefix, ?\WP_Post $context): array
    {
        $user = get_current_user_id();
        $saved = [$GLOBALS['post'] ?? null, $GLOBALS['wp_query'] ?? null, $GLOBALS['wp_the_query'] ?? null];

        wp_set_current_user(0);

        if ($context !== null) {
            $GLOBALS['wp_query'] = new \WP_Query(['p' => $context->ID, 'post_type' => $context->post_type, 'post_status' => 'any']);
            $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
            $GLOBALS['post'] = $context;
            setup_postdata($context);
        }

        ob_start();

        try {
            $builder = \YOOtheme\app(\YOOtheme\Builder::class);
            $params = ['prefix' => $prefix, 'post' => $context];
            $json = (string) wp_json_encode($layout);
            $tree = $builder->load($json, ['context' => 'render'] + $params);

            if (!is_object($tree)) {
                // The render transforms can drop the whole layout (a source that resolves to nothing):
                // that renders nothing. Only a layout that does not load without them is broken.
                if (!is_object($builder->load($json, $params))) {
                    return ['error' => 'YOOtheme could not load this layout.', 'code' => 'layout_load_failed'];
                }

                return ['tree' => (object) ['type' => 'layout', 'props' => [self::PATH_PROP => 'root'], 'children' => []], 'html' => ''];
            }

            $html = (string) $builder->render($tree, $params);

            return ['tree' => $tree, 'html' => $html];
        } catch (\Throwable $exception) {
            return ['error' => 'YOOtheme failed to render this layout: ' . $exception->getMessage(), 'code' => 'render_failed'];
        } finally {
            ob_end_clean();
            [$GLOBALS['post'], $GLOBALS['wp_query'], $GLOBALS['wp_the_query']] = $saved;

            if ($GLOBALS['post'] instanceof \WP_Post) {
                setup_postdata($GLOBALS['post']);
            }

            wp_set_current_user($user);
        }
    }
}
