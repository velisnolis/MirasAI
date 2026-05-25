<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\AbstractTool;
use Mirasai\Library\Tool\YooThemeElementNavigator;
use Mirasai\Library\Tool\YooThemeHelper;

class TemplateElementSourceReadTool extends AbstractTool
{
    use TemplateElementSourceSupportTrait;

    private YooThemeHelper $yooHelper;

    public function __construct()
    {
        parent::__construct();
        $this->yooHelper = new YooThemeHelper($this->db);
    }

    public function getName(): string
    {
        return 'template/element-source-read';
    }

    public function getDescription(): string
    {
        return 'Reads the Dynamic Source binding for one YOOtheme Builder element. Uses props.source as the canonical binding, with source and source_extended as compatibility fallbacks.';
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
                'path' => [
                    'type' => 'string',
                    'description' => 'Element path as returned by template/element-list.',
                ],
                'include_raw' => [
                    'type' => 'boolean',
                    'description' => 'Include the raw source binding payload. Defaults to false.',
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function getPermissions(): array
    {
        return [
            'risk_level' => self::RISK_READ,
            'idempotent' => true,
        ];
    }

    public function handle(array $arguments): array
    {
        $key = trim((string) ($arguments['key'] ?? ''));
        $articleId = (int) ($arguments['article_id'] ?? 0);
        $moduleId = (int) ($arguments['module_id'] ?? 0);
        $path = trim((string) ($arguments['path'] ?? ''));
        $includeRaw = !empty($arguments['include_raw']);
        $selectorCount = ($key !== '' ? 1 : 0) + ($articleId > 0 ? 1 : 0) + ($moduleId > 0 ? 1 : 0);

        if ($selectorCount === 0 || $path === '') {
            return ['error' => 'key, article_id, or module_id, and path are required.'];
        }

        if ($selectorCount > 1) {
            return ['error' => 'Provide only one of key, article_id, or module_id.', 'code' => 'ambiguous_storage'];
        }

        if ($articleId > 0) {
            return $this->handleArticle($articleId, $path, $includeRaw);
        }

        if ($moduleId > 0) {
            return $this->handleModule($moduleId, $path, $includeRaw);
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

        $result = (new YooThemeElementNavigator())->findElement($layout, $path);

        if ($result === null) {
            return ['error' => "Element path {$path} not found in template {$key}."];
        }

        $binding = $this->summarizeBinding($result['element']);

        if (!$includeRaw) {
            unset($binding['raw_source']);
        }

        return [
            'storage' => 'template',
            'key' => $key,
            'name' => $this->yooHelper->getTemplateName($template),
            'etag' => $this->yooHelper->buildTemplateEtag($template),
            'metadata' => $result['metadata'],
            'binding' => $binding,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handleArticle(int $articleId, string $path, bool $includeRaw): array
    {
        $article = $this->yooHelper->loadArticle($articleId);

        if ($article === null) {
            return ['error' => "Article {$articleId} not found.", 'code' => 'article_not_found'];
        }

        $layout = $this->yooHelper->getArticleLayout($article);

        if ($layout === null) {
            return ['error' => "Article {$articleId} has no YOOtheme layout in fulltext.", 'code' => 'article_layout_missing'];
        }

        $result = (new YooThemeElementNavigator())->findElement($layout, $path);

        if ($result === null) {
            return ['error' => "Element path {$path} not found in article {$articleId}.", 'code' => 'element_not_found'];
        }

        $binding = $this->summarizeBinding($result['element']);

        if (!$includeRaw) {
            unset($binding['raw_source']);
        }

        return [
            'storage' => 'article',
            'article_id' => $articleId,
            'article_title' => (string) ($article['title'] ?? ''),
            'article_state' => (int) ($article['state'] ?? 0),
            'etag' => $this->yooHelper->buildArticleLayoutEtag($article),
            'metadata' => $result['metadata'],
            'binding' => $binding,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handleModule(int $moduleId, string $path, bool $includeRaw): array
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

        $result = (new YooThemeElementNavigator())->findElement($layout, $path);

        if ($result === null) {
            return ['error' => "Element path {$path} not found in module {$moduleId}.", 'code' => 'element_not_found'];
        }

        $binding = $this->summarizeBinding($result['element']);

        if (!$includeRaw) {
            unset($binding['raw_source']);
        }

        return [
            'storage' => 'module',
            'module_id' => $moduleId,
            'module_title' => (string) ($module['title'] ?? ''),
            'module_type' => (string) ($module['module'] ?? ''),
            'module_published' => (int) ($module['published'] ?? 0),
            'etag' => $this->yooHelper->buildModuleLayoutEtag($module),
            'metadata' => $result['metadata'],
            'binding' => $binding,
        ];
    }

}
