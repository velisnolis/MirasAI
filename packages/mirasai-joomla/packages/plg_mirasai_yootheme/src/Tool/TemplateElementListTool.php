<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\AbstractTool;
use Mirasai\Library\Tool\YooThemeElementNavigator;
use Mirasai\Library\Tool\YooThemeHelper;

class TemplateElementListTool extends AbstractTool
{
    use TemplateElementSourceSupportTrait;
    /** @var list<string> */
    private const FIELD_KEYS = [
        'path',
        'type',
        'depth',
        'index',
        'parent_path',
        'child_count',
        'prop_keys',
        'label',
        'has_source_binding',
    ];

    private YooThemeHelper $yooHelper;

    public function __construct()
    {
        parent::__construct();
        $this->yooHelper = new YooThemeHelper($this->db);
    }

    public function getName(): string
    {
        return 'template/element-list';
    }

    public function getDescription(): string
    {
        return 'Lists elements in a YOOtheme Builder template as a flat depth-first index with stable paths, type, depth, parent, child count, prop keys, label, and source-binding flag. Use this before template/element-read to locate the element to inspect. mode=outline returns a nested type/path/title tree; mode=bindings_only returns Dynamic Source bindings only. Every mode carries status on an element the Builder keeps but does not output, so a disabled row is visible without reading its props; bindings_only adds disabled_by for a binding sitting inside a disabled ancestor.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'key' => [
                    'type' => 'string',
                    'description' => 'Template storage key as returned by template/list. Use one of key, article_id, or module_id.',
                ],
                'article_id' => [
                    'type' => 'integer',
                    'description' => 'Optional Joomla article ID with a YOOtheme Builder layout in fulltext. Use one of key, article_id, or module_id.',
                ],
                'module_id' => [
                    'type' => 'integer',
                    'description' => 'Optional YOOtheme Builder module ID with a layout in #__modules.content. Use one of key, article_id, or module_id.',
                ],
                'fields' => [
                    'type' => 'array',
                    'description' => 'Optional projection to reduce response size.',
                    'items' => [
                        'type' => 'string',
                        'enum' => self::FIELD_KEYS,
                    ],
                ],
                'max_results' => [
                    'type' => 'integer',
                    'description' => 'Optional maximum number of elements to return. Default 500, max 2000.',
                    'minimum' => 1,
                    'maximum' => 2000,
                ],
                'mode' => YooThemeElementNavigator::readModeSchemaProperty(),
            ],
            'required' => [],
        ];
    }

    public function handle(array $arguments): array
    {
        $key = trim((string) ($arguments['key'] ?? ''));
        $articleId = (int) ($arguments['article_id'] ?? 0);
        $moduleId = (int) ($arguments['module_id'] ?? 0);
        $selectorCount = ($key !== '' ? 1 : 0) + ($articleId > 0 ? 1 : 0) + ($moduleId > 0 ? 1 : 0);

        if ($selectorCount === 0) {
            return ['error' => 'Template key, article_id, or module_id is required.'];
        }

        if ($selectorCount > 1) {
            return ['error' => 'Provide only one of key, article_id, or module_id.', 'code' => 'ambiguous_storage'];
        }

        $fields = $this->normalizeFields($arguments['fields'] ?? null);

        if (isset($fields['error'])) {
            return $fields;
        }

        $maxResults = $this->normalizeMaxResults($arguments['max_results'] ?? null);

        if (isset($maxResults['error'])) {
            return $maxResults;
        }

        $mode = YooThemeElementNavigator::normalizeReadMode($arguments['mode'] ?? null);

        if (isset($mode['error'])) {
            return $mode;
        }

        if ($articleId > 0) {
            return $this->handleArticle($articleId, $fields, $maxResults, $mode['mode']);
        }

        if ($moduleId > 0) {
            return $this->handleModule($moduleId, $fields, $maxResults, $mode['mode']);
        }

        $templates = $this->yooHelper->loadTemplates();
        $template = $templates[$key] ?? null;
        if (!is_array($template)) {
            return ['error' => "Template {$key} not found."];
        }

        $layout = $this->yooHelper->getTemplateLayout($template);

        if ($layout === null) {
            return ['error' => "Template {$key} has no layout."];
        }

        return $this->presentElements(
            $layout,
            $mode['mode'],
            $fields,
            $maxResults,
            [
                'storage' => 'template',
                'key' => $key,
                'name' => $this->yooHelper->getTemplateName($template),
                'etag' => $this->yooHelper->buildTemplateEtag($template),
            ],
        );
    }

    /**
     * @param array{fields?: list<string>, error?: string} $fields
     * @param array{max_results: int, error?: string} $maxResults
     * @return array<string, mixed>
     */
    private function handleArticle(int $articleId, array $fields, array $maxResults, string $mode): array
    {
        $article = $this->yooHelper->loadArticle($articleId);

        if ($article === null) {
            return ['error' => "Article {$articleId} not found.", 'code' => 'article_not_found'];
        }

        $layout = $this->yooHelper->getArticleLayout($article);

        if ($layout === null) {
            return ['error' => "Article {$articleId} has no YOOtheme layout in fulltext.", 'code' => 'article_layout_missing'];
        }

        return $this->presentElements(
            $layout,
            $mode,
            $fields,
            $maxResults,
            [
                'storage' => 'article',
                'article_id' => $articleId,
                'article_title' => (string) ($article['title'] ?? ''),
                'article_state' => (int) ($article['state'] ?? 0),
                'etag' => $this->yooHelper->buildArticleLayoutEtag($article),
            ],
        );
    }

    /**
     * @param array{fields?: list<string>, error?: string} $fields
     * @param array{max_results: int, error?: string} $maxResults
     * @return array<string, mixed>
     */
    private function handleModule(int $moduleId, array $fields, array $maxResults, string $mode): array
    {
        $module = $this->yooHelper->loadModule($moduleId);

        if ($module === null) {
            return ['error' => "Module {$moduleId} not found.", 'code' => 'module_not_found'];
        }

        if ((string) ($module['module'] ?? '') !== 'mod_yootheme_builder') {
            return [
                'error' => "Module {$moduleId} is not a YOOtheme Builder module.",
                'code' => 'module_not_yootheme_builder',
                'module_type' => (string) ($module['module'] ?? ''),
            ];
        }

        $layout = $this->yooHelper->getModuleLayout($module);

        if ($layout === null) {
            return ['error' => "Module {$moduleId} has no YOOtheme layout in content.", 'code' => 'module_layout_missing'];
        }

        return $this->presentElements(
            $layout,
            $mode,
            $fields,
            $maxResults,
            [
                'storage' => 'module',
                'module_id' => $moduleId,
                'module_title' => (string) ($module['title'] ?? ''),
                'module_type' => (string) ($module['module'] ?? ''),
                'module_published' => (int) ($module['published'] ?? 0),
                'etag' => $this->yooHelper->buildModuleLayoutEtag($module),
            ],
        );
    }

    /**
     * @param array<string, mixed> $layout
     * @param array{fields?: list<string>, error?: string} $fields
     * @param array{max_results: int, error?: string} $maxResults
     * @param array<string, mixed> $identity
     * @return array<string, mixed>
     */
    private function presentElements(array $layout, string $mode, array $fields, array $maxResults, array $identity): array
    {
        $navigator = new YooThemeElementNavigator();
        $response = $identity + ['mode' => $mode];

        if ($mode === 'outline') {
            $response['tree'] = $navigator->outlineTree($layout);

            return $response;
        }

        if ($mode === 'bindings_only') {
            $response['bindings'] = $this->bindingsOnlyFromLayout($navigator, $layout);
            $response['count'] = count($response['bindings']);

            return $response;
        }

        $elements = $navigator->listElements($layout);
        $truncated = count($elements) > $maxResults['max_results'];
        $elements = array_slice($elements, 0, $maxResults['max_results']);

        if (isset($fields['fields'])) {
            $elements = array_map(
                fn (array $item): array => $this->projectFields($item, $fields['fields']),
                $elements,
            );
        }

        $response['count'] = count($elements);
        $response['truncated'] = $truncated;
        $response['elements'] = $elements;

        return $response;
    }

    /**
     * @return array{fields?: list<string>, error?: string}
     */
    private function normalizeFields(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value)) {
            return ['error' => 'fields must be an array of strings.'];
        }

        $fields = [];

        foreach ($value as $field) {
            if (!is_string($field)) {
                return ['error' => 'fields must contain strings only.'];
            }

            $field = trim($field);

            if (!in_array($field, self::FIELD_KEYS, true)) {
                return ['error' => "Unsupported field '{$field}'. Allowed fields: " . implode(', ', self::FIELD_KEYS) . '.'];
            }

            if (!in_array($field, $fields, true)) {
                $fields[] = $field;
            }
        }

        return ['fields' => $fields];
    }

    /**
     * @return array{max_results: int, error?: string}
     */
    private function normalizeMaxResults(mixed $value): array
    {
        if ($value === null) {
            return ['max_results' => 500];
        }

        $maxResults = (int) $value;

        if ($maxResults < 1 || $maxResults > 2000) {
            return ['max_results' => 500, 'error' => 'max_results must be between 1 and 2000.'];
        }

        return ['max_results' => $maxResults];
    }

    /**
     * @param array<string, mixed> $item
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    private function projectFields(array $item, array $fields): array
    {
        if ($fields === []) {
            return $item;
        }

        $projected = [];

        foreach ($fields as $field) {
            $projected[$field] = $item[$field] ?? null;
        }

        return $projected;
    }
}
