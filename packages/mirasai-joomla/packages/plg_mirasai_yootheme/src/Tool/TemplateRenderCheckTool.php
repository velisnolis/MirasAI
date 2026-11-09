<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\ParameterType;
use Mirasai\Library\Tool\AbstractTool;
use Mirasai\Library\Tool\PublicPageInspector;
use Mirasai\Library\Tool\YooThemeHelper;
use Mirasai\Library\Tool\YooThemeLayoutProcessor;

/**
 * Mirrors mirasai-wp's TemplateRenderCheckTool. What differs on Joomla:
 *
 * - YOOtheme does not boot in the API application (template_bootstrap.php only
 *   loads for the site client), so this tool loads it the same way first.
 * - Article sources read the article from the render params, not from globals.
 */
class TemplateRenderCheckTool extends AbstractTool
{
    private const PATH_PROP = 'mirasai_path';
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;
    private const MAX_TEXT = 300;
    private const ARTICLE_VIEW = 'com_content.article';

    private YooThemeHelper $yooHelper;

    public function __construct()
    {
        parent::__construct();
        $this->yooHelper = new YooThemeHelper($this->db);
    }

    public function getName(): string
    {
        return 'template/render-check';
    }

    public function getDescription(): string
    {
        return 'Renders a stored YOOtheme layout on the server as a guest and lists every element that shows text, with its path, '
            . 'its type and the text; not_rendered lists the stored paths that do not render (disabled, a display condition, an empty '
            . 'source). For a com_content.article template, pass context_id: the article it should render with, so Dynamic Sources '
            . 'resolve. With expect, says in which paths each text appears. It ignores the publication state, so unpublished articles '
            . 'render too; use site/public-fetch for what a visitor really gets at a URL. Read-only.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'key' => ['type' => 'string', 'description' => 'YOOtheme template key from template/list. Use one of key or article_id.'],
                'article_id' => ['type' => 'integer', 'description' => 'Article whose own YOOtheme layout to render. Use one of key or article_id.'],
                'context_id' => [
                    'type' => 'integer',
                    'description' => 'com_content.article templates only: the article to render the template with.',
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

    public function handle(array $arguments): array
    {
        $expect = PublicPageInspector::normalizeExpect($arguments['expect'] ?? null);

        if (isset($expect['error'])) {
            return $expect;
        }

        $key = trim((string) ($arguments['key'] ?? ''));
        $articleId = (int) ($arguments['article_id'] ?? 0);
        $contextId = (int) ($arguments['context_id'] ?? 0);

        if (($key !== '') === ($articleId > 0)) {
            return ['error' => 'Provide one of key or article_id.', 'code' => $key === '' ? 'missing_target' : 'ambiguous_target'];
        }

        if ($key !== '') {
            $template = $this->yooHelper->loadTemplates()[$key] ?? null;

            if (!is_array($template)) {
                return ['error' => "Template {$key} not found.", 'code' => 'template_not_found'];
            }

            $layout = $this->yooHelper->getTemplateLayout($template);

            if ($layout === null) {
                return ['error' => "Template {$key} has no layout.", 'code' => 'template_layout_missing'];
            }

            $type = is_string($template['type'] ?? null) ? $template['type'] : '';

            if ($contextId > 0 && $type !== self::ARTICLE_VIEW) {
                return ['error' => "context_id renders an article; template {$key} is {$type}.", 'code' => 'context_unsupported_for_type'];
            }

            $target = ['storage' => 'template', 'key' => $key, 'label' => $this->yooHelper->getTemplateName($template), 'etag' => $this->yooHelper->buildTemplateEtag($template)];
            $context = $contextId > 0 ? $this->loadArticle($contextId) : null;

            if ($contextId > 0 && $context === null) {
                return ['error' => "Article {$contextId} not found.", 'code' => 'context_not_found'];
            }

            $params = ['prefix' => 'template', 'template' => $type];
        } else {
            if ($contextId > 0) {
                return ['error' => 'context_id is for templates; an article renders with itself.', 'code' => 'context_only_for_templates'];
            }

            $row = $this->yooHelper->loadArticle($articleId);

            if ($row === null) {
                return ['error' => "Article {$articleId} not found.", 'code' => 'article_not_found'];
            }

            $layout = $this->yooHelper->getArticleLayout($row);

            if ($layout === null) {
                return ['error' => "Article {$articleId} has no YOOtheme layout in fulltext.", 'code' => 'article_layout_missing'];
            }

            $target = ['storage' => 'article', 'article_id' => $articleId, 'label' => (string) ($row['title'] ?? ''), 'etag' => $this->yooHelper->buildArticleLayoutEtag($row)];
            $context = $this->loadArticle($articleId);
            $params = ['prefix' => 'page', 'template' => self::ARTICLE_VIEW];
        }

        if ($context !== null) {
            $params['article'] = $context;
        }

        $stored = [];
        self::mark($layout, 'root', $stored);
        $rendered = $this->render($layout, $params);

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

        $response = $target + [
            'rendered_as' => 'guest',
            'context' => $context !== null
                ? ['id' => (int) $context->id, 'title' => (string) $context->title, 'state' => (int) $context->state]
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

        if ($key !== '' && $context === null) {
            $response['note'] = 'Rendered without context_id: Dynamic Sources that need an article render empty.';
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

            foreach (YooThemeLayoutProcessor::TEXT_PROPS as $name) {
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

    /** The article as an object, the shape YOOtheme's article sources read. */
    private function loadArticle(int $id): ?object
    {
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__content'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);
        $article = $this->db->setQuery($query)->loadObject();

        if (!is_object($article)) {
            return null;
        }

        $article->text = (string) $article->introtext . (string) $article->fulltext;

        return $article;
    }

    /**
     * Boot YOOtheme if needed and render as a guest.
     *
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $params
     * @return array{tree: object, html: string}|array{error: string, code: string}
     */
    private function render(array $layout, array $params): array
    {
        $boot = $this->bootYootheme();

        if ($boot !== null) {
            return $boot;
        }

        $app = Factory::getApplication();
        $identity = $app->getIdentity();
        // A new User is a guest. loadIdentity(null) would need a UserFactory the API application does not set.
        $app->loadIdentity(new User());
        ob_start();

        try {
            $builder = \YOOtheme\app(\YOOtheme\Builder::class);
            $json = (string) json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $tree = $builder->load($json, ['context' => 'render'] + $params);

            if (!is_object($tree)) {
                // The render transforms can drop the whole layout (a source that resolves to nothing):
                // that renders nothing. Only a layout that does not load without them is broken.
                if (!is_object($builder->load($json, $params))) {
                    return ['error' => 'YOOtheme could not load this layout.', 'code' => 'layout_load_failed'];
                }

                return ['tree' => (object) ['type' => 'layout', 'props' => [self::PATH_PROP => 'root'], 'children' => []], 'html' => ''];
            }

            return ['tree' => $tree, 'html' => (string) $builder->render($tree, $params)];
        } catch (\Throwable $exception) {
            return ['error' => 'YOOtheme failed to render this layout: ' . $exception->getMessage(), 'code' => 'render_failed'];
        } finally {
            ob_end_clean();
            $app->loadIdentity($identity);
        }
    }

    /**
     * What templates/yootheme/template_bootstrap.php does for the site client.
     *
     * @return array{error: string, code: string}|null
     */
    private function bootYootheme(): ?array
    {
        if (function_exists('YOOtheme\\app')) {
            return null;
        }

        $root = JPATH_ROOT . '/templates/yootheme';

        if (!is_file($root . '/bootstrap.php')) {
            return ['error' => 'YOOtheme Pro is not installed, so nothing can be rendered.', 'code' => 'yootheme_unavailable'];
        }

        try {
            $app = require $root . '/bootstrap.php';
            $app->load($root . '/{packages/{platform-joomla,theme{,-consent,-highlight,-settings},builder{,-source*,-templates,-newsletter},styler,theme-joomla*,builder-joomla*}/bootstrap.php,config.php}');
        } catch (\Throwable $exception) {
            return ['error' => 'YOOtheme could not be loaded: ' . $exception->getMessage(), 'code' => 'yootheme_boot_failed'];
        }

        return null;
    }
}
