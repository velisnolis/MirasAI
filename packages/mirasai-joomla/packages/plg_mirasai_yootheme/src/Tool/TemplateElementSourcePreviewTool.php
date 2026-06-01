<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\AbstractTool;
use Mirasai\Library\Tool\YooThemeElementNavigator;
use Mirasai\Library\Tool\YooThemeHelper;

class TemplateElementSourcePreviewTool extends AbstractTool
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
        return 'template/element-source-preview';
    }

    public function getDescription(): string
    {
        return 'Previews setting a YOOtheme Dynamic Source binding on one element without writing. Accepts either a raw source object or shorthand source_name/query_field/field_mappings.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->sourceInputProperties() + [
                'key' => ['type' => 'string', 'description' => 'Template storage key as returned by template/list. Use one of key, article_id, or module_id.'],
                'article_id' => ['type' => 'integer', 'description' => 'Optional Joomla article ID with a YOOtheme Builder layout in fulltext. Use one of key, article_id, or module_id.'],
                'module_id' => ['type' => 'integer', 'description' => 'Optional YOOtheme Builder module ID with a layout in #__modules.content. Use one of key, article_id, or module_id.'],
                'path' => ['type' => 'string', 'description' => 'Element path as returned by template/element-list.'],
                'if_match' => ['type' => 'string', 'description' => 'Optional current template etag. When provided, stale values are reported in the preview.'],
                'include_raw' => ['type' => 'boolean', 'description' => 'Include raw source payloads in the response. Defaults to false.'],
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
        $ifMatch = trim((string) ($arguments['if_match'] ?? ''));
        $includeRaw = !empty($arguments['include_raw']);
        $selectorCount = ($key !== '' ? 1 : 0) + ($articleId > 0 ? 1 : 0) + ($moduleId > 0 ? 1 : 0);

        if ($selectorCount === 0 || $path === '') {
            return ['error' => 'key, article_id, or module_id, and path are required.'];
        }

        if ($selectorCount > 1) {
            return ['error' => 'Provide only one of key, article_id, or module_id.', 'code' => 'ambiguous_storage'];
        }

        $source = $this->normalizeSourceArgument($arguments);

        if (isset($source['error'])) {
            return $source;
        }

        if ($articleId > 0) {
            return $this->handleArticle($articleId, $path, $source, $ifMatch, $includeRaw);
        }

        if ($moduleId > 0) {
            return $this->handleModule($moduleId, $path, $source, $ifMatch, $includeRaw);
        }

        $templates = $this->yooHelper->loadTemplates();
        $template = $templates[$key] ?? null;

        if (!is_array($template)) {
            return ['error' => "Template {$key} not found."];
        }

        $currentEtag = $this->yooHelper->buildTemplateEtag($template);
        $layout = $this->yooHelper->getTemplateLayout($template);

        if ($layout === null) {
            return ['error' => "Template {$key} has no layout."];
        }

        $current = (new YooThemeElementNavigator())->findElement($layout, $path);

        if ($current === null) {
            return ['error' => "Element path {$path} not found in template {$key}."];
        }

        $updated = (new YooThemeElementNavigator())->setElementSource($layout, $path, $source);

        if (isset($updated['error'])) {
            return $updated;
        }

        $previewTemplate = $template;
        $this->yooHelper->setTemplateLayout($previewTemplate, $updated['layout']);
        $newEtag = $this->yooHelper->buildTemplateEtag($previewTemplate);
        $before = $this->summarizeBinding($current['element']);
        $after = $this->summarizeBinding($updated['element']);

        if (!$includeRaw) {
            unset($before['raw_source'], $after['raw_source']);
        }

        return [
            'storage' => 'template',
            'key' => $key,
            'path' => $path,
            'dry_run' => true,
            'action' => 'preview',
            'would_change' => !hash_equals($currentEtag, $newEtag),
            'etag_matches' => $ifMatch === '' ? null : hash_equals($currentEtag, $ifMatch),
            'old_etag' => $currentEtag,
            'new_etag' => $newEtag,
            'metadata' => $updated['metadata'],
            'before' => $before,
            'after' => $after,
            'note' => 'No changes were written. Retry template/element-source-set with confirm_guarded_write=true and the current if_match to apply.',
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function handleArticle(int $articleId, string $path, array $source, string $ifMatch, bool $includeRaw): array
    {
        $article = $this->yooHelper->loadArticle($articleId);

        if ($article === null) {
            return ['error' => "Article {$articleId} not found.", 'code' => 'article_not_found'];
        }

        $currentEtag = $this->yooHelper->buildArticleLayoutEtag($article);
        $layout = $this->yooHelper->getArticleLayout($article);

        if ($layout === null) {
            return ['error' => "Article {$articleId} has no YOOtheme layout in fulltext.", 'code' => 'article_layout_missing'];
        }

        $current = (new YooThemeElementNavigator())->findElement($layout, $path);

        if ($current === null) {
            return ['error' => "Element path {$path} not found in article {$articleId}.", 'code' => 'element_not_found'];
        }

        $updated = (new YooThemeElementNavigator())->setElementSource($layout, $path, $source);

        if (isset($updated['error'])) {
            return $updated;
        }

        $previewArticle = $article;
        $this->yooHelper->setArticleLayout($previewArticle, $updated['layout']);
        $newEtag = $this->yooHelper->buildArticleLayoutEtag($previewArticle);
        $before = $this->summarizeBinding($current['element']);
        $after = $this->summarizeBinding($updated['element']);

        if (!$includeRaw) {
            unset($before['raw_source'], $after['raw_source']);
        }

        return [
            'storage' => 'article',
            'article_id' => $articleId,
            'article_title' => (string) ($article['title'] ?? ''),
            'article_state' => (int) ($article['state'] ?? 0),
            'path' => $path,
            'dry_run' => true,
            'action' => 'preview',
            'would_change' => !hash_equals($currentEtag, $newEtag),
            'etag_matches' => $ifMatch === '' ? null : hash_equals($currentEtag, $ifMatch),
            'old_etag' => $currentEtag,
            'new_etag' => $newEtag,
            'metadata' => $updated['metadata'],
            'before' => $before,
            'after' => $after,
            'note' => 'No changes were written. Retry template/element-source-set with confirm_guarded_write=true and the current if_match to apply.',
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function handleModule(int $moduleId, string $path, array $source, string $ifMatch, bool $includeRaw): array
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

        $currentEtag = $this->yooHelper->buildModuleLayoutEtag($module);
        $layout = $this->yooHelper->getModuleLayout($module);

        if ($layout === null) {
            return ['error' => "Module {$moduleId} has no YOOtheme layout in content.", 'code' => 'module_layout_missing'];
        }

        $current = (new YooThemeElementNavigator())->findElement($layout, $path);

        if ($current === null) {
            return ['error' => "Element path {$path} not found in module {$moduleId}.", 'code' => 'element_not_found'];
        }

        $updated = (new YooThemeElementNavigator())->setElementSource($layout, $path, $source);

        if (isset($updated['error'])) {
            return $updated;
        }

        $previewModule = $module;
        $this->yooHelper->setModuleLayout($previewModule, $updated['layout']);
        $newEtag = $this->yooHelper->buildModuleLayoutEtag($previewModule);
        $before = $this->summarizeBinding($current['element']);
        $after = $this->summarizeBinding($updated['element']);

        if (!$includeRaw) {
            unset($before['raw_source'], $after['raw_source']);
        }

        return [
            'storage' => 'module',
            'module_id' => $moduleId,
            'module_title' => (string) ($module['title'] ?? ''),
            'module_type' => (string) ($module['module'] ?? ''),
            'module_published' => (int) ($module['published'] ?? 0),
            'path' => $path,
            'dry_run' => true,
            'action' => 'preview',
            'would_change' => !hash_equals($currentEtag, $newEtag),
            'etag_matches' => $ifMatch === '' ? null : hash_equals($currentEtag, $ifMatch),
            'old_etag' => $currentEtag,
            'new_etag' => $newEtag,
            'metadata' => $updated['metadata'],
            'before' => $before,
            'after' => $after,
            'note' => 'No changes were written. Retry template/element-source-set with confirm_guarded_write=true and the current if_match to apply.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceInputProperties(): array
    {
        return [
            'source' => ['type' => 'object', 'description' => 'Raw YOOtheme source payload to write to the element source binding.'],
            'source_name' => ['type' => 'string', 'description' => 'Source type/name used when source is omitted, for example Article or article.'],
            'query_field' => ['type' => 'string', 'description' => 'Optional query field name, for example article.'],
            'query_arguments' => ['type' => 'object', 'description' => 'Optional query field arguments.'],
            'query_directives' => ['type' => 'object', 'description' => 'Optional query field directives.'],
            'field_mappings' => ['type' => 'object', 'description' => 'Map element prop names to source field names or mapping objects.'],
        ];
    }
}
