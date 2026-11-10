<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\AbstractTool;
use Mirasai\Library\Tool\YooThemeElementNavigator;
use Mirasai\Library\Tool\YooThemeHelper;

abstract class AbstractTemplateElementWriteTool extends AbstractTool
{
    protected YooThemeHelper $yooHelper;

    public function __construct()
    {
        parent::__construct();
        $this->yooHelper = new YooThemeHelper($this->db);
    }

    public function getPermissions(): array
    {
        return [
            'risk_level' => self::RISK_GUARDED_WRITE,
            'idempotent' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function targetSelectorSchema(): array
    {
        return [
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
        ];
    }

    /**
     * Refuse prop values the installed element does not offer.
     *
     * Silence is not an option here, but neither is guessing: when the element
     * definition cannot be loaded — a third-party element, a type YOOtheme no
     * longer ships — there is nothing to check against, and the write proceeds
     * exactly as it did before.
     *
     * @param array<string, mixed> $props
     * @return array<string, mixed>|null
     */
    protected function rejectInvalidProps(string $elementType, array $props): ?array
    {
        if ($elementType === '' || $props === []) {
            return null;
        }

        $definition = YoothemeElementDefinitionLoader::load($elementType);

        if (isset($definition['error']) || !is_array($definition['fields'] ?? null)) {
            return null;
        }

        return YoothemePropsValidator::validate($elementType, $definition['fields'], $props);
    }


    /**
     * rejectInvalidProps() on every node of an element tree, so a section added with its rows,
     * columns and elements is checked as a whole. at is the node's place inside the added element.
     *
     * @param array<string, mixed> $element
     * @return array<string, mixed>|null
     */
    protected function rejectInvalidTree(array $element, string $at = 'element'): ?array
    {
        $rejection = $this->rejectInvalidProps(
            (string) ($element['type'] ?? ''),
            is_array($element['props'] ?? null) ? $element['props'] : []
        );

        if ($rejection !== null) {
            return $rejection + ['at' => $at];
        }

        foreach (is_array($element['children'] ?? null) ? $element['children'] : [] as $index => $child) {
            if (is_array($child)) {
                $type = is_string($child['type'] ?? null) && $child['type'] !== '' ? $child['type'] : 'unknown';
                $rejection = $this->rejectInvalidTree($child, $at . '>' . $type . '[' . $index . ']');

                if ($rejection !== null) {
                    return $rejection;
                }
            }
        }

        return null;
    }

    /**
     * The layouts the installed Builder offers for a row, null when its definition cannot be read.
     *
     * @return list<string>|null
     */
    protected function rowLayoutOptions(): ?array
    {
        $definition = YoothemeElementDefinitionLoader::load('row');

        return isset($definition['error']) ? null : YoothemeRowLayout::options($definition);
    }

    /**
     * Set layout on every row of an added element that has columns and no layout, when the widths
     * match one Builder layout (F-044). Rows that cannot be filled come back as warnings.
     *
     * @param array<string, mixed> $element
     * @param list<string>|null $options
     * @param list<array<string, mixed>> $filled
     * @param list<array<string, mixed>> $warnings
     * @return array<string, mixed>
     */
    protected function fillRowLayouts(array $element, ?array $options, array &$filled, array &$warnings, string $at = 'element'): array
    {
        if (($element['type'] ?? null) === 'row') {
            $row = YoothemeRowLayout::filled($element, $options);

            if ($row !== null) {
                $element = $row;
                $filled[] = ['at' => $at, 'layout' => $row['props']['layout']];
            } else {
                $issue = YoothemeRowLayout::check($element, $options);

                if ($issue !== null) {
                    $warnings[] = self::rowLayoutWarning($issue, 'at', $at);
                }
            }
        }

        if (is_array($element['children'] ?? null)) {
            foreach ($element['children'] as $index => $child) {
                if (is_array($child)) {
                    $type = is_string($child['type'] ?? null) && $child['type'] !== '' ? $child['type'] : 'unknown';
                    $element['children'][$index] = $this->fillRowLayouts($child, $options, $filled, $warnings, $at . '>' . $type . '[' . $index . ']');
                }
            }
        }

        return $element;
    }

    /**
     * @param array<string, mixed> $issue from YoothemeRowLayout::check()
     * @return array<string, mixed>
     */
    protected static function rowLayoutWarning(array $issue, string $key, string $place): array
    {
        return [
            'code' => 'row_layout_mismatch',
            $key => $place,
            'message' => YoothemeRowLayout::message($issue),
            'fix' => YoothemeRowLayout::fix($issue),
            'suggested_layout' => $issue['suggested_layout'],
        ];
    }

    /**
     * After a write to a row's layout or to a column's width_*, the row whose layout no longer
     * describes its columns, as a warning (F-044). null when the write touched neither.
     *
     * @param array<string, mixed> $layout the layout after the write
     * @param array<string, mixed> $props the props written
     * @return array<string, mixed>|null
     */
    protected function rowLayoutWarningAfterUpdate(array $layout, string $path, string $type, array $props, bool $merge): ?array
    {
        $keys = array_map('strval', array_keys($props));

        if ($type === 'row' && (!$merge || in_array('layout', $keys, true))) {
            $rowPath = $path;
        } elseif ($type === 'column' && (!$merge || preg_grep('/^width_/', $keys) !== [])) {
            $separator = strrpos($path, '>');
            $rowPath = $separator === false ? '' : substr($path, 0, $separator);
        } else {
            return null;
        }

        $row = (new YooThemeElementNavigator())->findElement($layout, $rowPath);

        if (($row['element']['type'] ?? null) !== 'row') {
            return null;
        }

        $issue = YoothemeRowLayout::check($row['element'], $this->rowLayoutOptions());

        return $issue === null ? null : self::rowLayoutWarning($issue, 'path', $rowPath);
    }

    /**
     * @param callable(array<string, mixed>, array<string, mixed>): array<string, mixed> $mutator
     * @return array<string, mixed>
     */
    protected function mutateTemplateElement(array $arguments, callable $mutator): array
    {
        $key = trim((string) ($arguments['key'] ?? ''));
        $articleId = (int) ($arguments['article_id'] ?? 0);
        $moduleId = (int) ($arguments['module_id'] ?? 0);
        $ifMatch = trim((string) ($arguments['if_match'] ?? ''));
        $dryRun = ($arguments['dry_run'] ?? null) === true;
        $confirmed = ($arguments['confirm_guarded_write'] ?? null) === true;
        $selectorCount = ($key !== '' ? 1 : 0) + ($articleId > 0 ? 1 : 0) + ($moduleId > 0 ? 1 : 0);

        if ($selectorCount === 0 || $ifMatch === '') {
            return [
                'error' => 'key, article_id, or module_id, and if_match are required.',
                'code' => 'missing_if_match',
            ];
        }

        if ($selectorCount > 1) {
            return [
                'error' => 'Provide only one of key, article_id, or module_id.',
                'code' => 'ambiguous_storage',
            ];
        }

        if (!$dryRun && !$confirmed) {
            return [
                'error' => 'This is a guarded write. Retry with dry_run=true first, then confirm_guarded_write=true if the preview is correct.',
                'code' => 'guarded_write_confirmation_required',
            ];
        }

        if ($moduleId > 0) {
            return $this->mutateModuleElement($moduleId, $ifMatch, $dryRun, $arguments, $mutator);
        }

        if ($articleId > 0) {
            return $this->mutateArticleElement($articleId, $ifMatch, $dryRun, $arguments, $mutator);
        }

        return $this->mutateStoredTemplateElement($key, $ifMatch, $dryRun, $arguments, $mutator);
    }

    /**
     * @param callable(array<string, mixed>, array<string, mixed>): array<string, mixed> $mutator
     * @return array<string, mixed>
     */
    private function mutateStoredTemplateElement(string $key, string $ifMatch, bool $dryRun, array $arguments, callable $mutator): array
    {
        $templates = $this->yooHelper->loadTemplates();
        $template = $templates[$key] ?? null;

        if (!is_array($template)) {
            return ['error' => "Template {$key} not found."];
        }

        $currentEtag = $this->yooHelper->buildTemplateEtag($template);

        if (!hash_equals($currentEtag, $ifMatch)) {
            return [
                'error' => 'Template etag mismatch. Re-read the template and retry with the fresh etag.',
                'code' => 'stale_etag',
                'expected_etag' => $currentEtag,
                'provided_etag' => $ifMatch,
            ];
        }

        $layout = $this->yooHelper->getTemplateLayout($template);

        if ($layout === null) {
            return ['error' => "Template {$key} has no layout."];
        }

        $mutation = $mutator($layout, $arguments);

        if (isset($mutation['error'])) {
            return $mutation;
        }

        if (!isset($mutation['layout']) || !is_array($mutation['layout'])) {
            return [
                'error' => 'Internal error: mutation did not return a layout.',
                'code' => 'missing_mutation_layout',
            ];
        }

        $this->yooHelper->setTemplateLayout($template, $mutation['layout']);
        $newEtag = $this->yooHelper->buildTemplateEtag($template);
        $responseTemplates = $templates;
        $responseTemplates[$key] = $template;

        if ($dryRun) {
            $cache = ['cleared' => false, 'groups' => [], 'failures' => [], 'reason' => 'dry_run'];
        } else {
            $freshTemplates = $this->yooHelper->loadTemplates();
            $freshTemplate = $freshTemplates[$key] ?? null;

            if (!is_array($freshTemplate)) {
                return [
                    'error' => "Template {$key} no longer exists. Re-read templates and retry.",
                    'code' => 'template_missing_before_write',
                ];
            }

            $freshEtag = $this->yooHelper->buildTemplateEtag($freshTemplate);

            if (!hash_equals($freshEtag, $ifMatch)) {
                return [
                    'error' => 'Template changed before write. Re-read the template and retry with the fresh etag.',
                    'code' => 'stale_etag',
                    'expected_etag' => $freshEtag,
                    'provided_etag' => $ifMatch,
                ];
            }

            $freshTemplates[$key] = $template;
            $responseTemplates = $freshTemplates;
            $cache = $this->yooHelper->writeTemplates($freshTemplates);
        }

        unset($mutation['layout']);

        $response = array_merge([
            'key' => $key,
            'dry_run' => $dryRun,
            'would_change' => !hash_equals($currentEtag, $newEtag),
            'old_etag' => $currentEtag,
            'new_etag' => $newEtag,
            'collection_etag' => $this->yooHelper->buildTemplatesEtag($responseTemplates),
            'cache' => $cache,
        ], $mutation);

        if ($dryRun) {
            $response['action'] = 'preview';
            $response['note'] = 'No changes were written. Retry with confirm_guarded_write=true and the same if_match if the preview is still current.';
        } else {
            $response['action'] = 'updated';
        }

        return $response;
    }

    /**
     * @param callable(array<string, mixed>, array<string, mixed>): array<string, mixed> $mutator
     * @return array<string, mixed>
     */
    private function mutateArticleElement(int $articleId, string $ifMatch, bool $dryRun, array $arguments, callable $mutator): array
    {
        $article = $this->yooHelper->loadArticle($articleId);

        if ($article === null) {
            return ['error' => "Article {$articleId} not found.", 'code' => 'article_not_found'];
        }

        $currentEtag = $this->yooHelper->buildArticleLayoutEtag($article);

        if (!hash_equals($currentEtag, $ifMatch)) {
            return [
                'error' => 'Article layout etag mismatch. Re-read the article layout and retry with the fresh etag.',
                'code' => 'stale_etag',
                'expected_etag' => $currentEtag,
                'provided_etag' => $ifMatch,
            ];
        }

        $layout = $this->yooHelper->getArticleLayout($article);

        if ($layout === null) {
            return ['error' => "Article {$articleId} has no YOOtheme layout in fulltext.", 'code' => 'article_layout_missing'];
        }

        $mutation = $mutator($layout, $arguments);

        if (isset($mutation['error'])) {
            return $mutation;
        }

        if (!isset($mutation['layout']) || !is_array($mutation['layout'])) {
            return [
                'error' => 'Internal error: mutation did not return a layout.',
                'code' => 'missing_mutation_layout',
            ];
        }

        $this->yooHelper->setArticleLayout($article, $mutation['layout']);
        $newEtag = $this->yooHelper->buildArticleLayoutEtag($article);

        $cache = $dryRun
            ? ['cleared' => false, 'groups' => [], 'failures' => [], 'reason' => 'dry_run']
            : $this->yooHelper->writeArticleLayout($article);

        unset($mutation['layout']);

        $response = array_merge([
            'storage' => 'article',
            'article_id' => $articleId,
            'article_title' => (string) ($article['title'] ?? ''),
            'article_state' => (int) ($article['state'] ?? 0),
            'dry_run' => $dryRun,
            'would_change' => !hash_equals($currentEtag, $newEtag),
            'old_etag' => $currentEtag,
            'new_etag' => $newEtag,
            'cache' => $cache,
        ], $mutation);

        if ($dryRun) {
            $response['action'] = 'preview';
            $response['note'] = 'No changes were written. Retry with confirm_guarded_write=true and the same if_match if the preview is still current.';
        } else {
            $response['action'] = 'updated';
        }

        return $response;
    }

    /**
     * @param callable(array<string, mixed>, array<string, mixed>): array<string, mixed> $mutator
     * @return array<string, mixed>
     */
    private function mutateModuleElement(int $moduleId, string $ifMatch, bool $dryRun, array $arguments, callable $mutator): array
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

        if (!hash_equals($currentEtag, $ifMatch)) {
            return [
                'error' => 'Module layout etag mismatch. Re-read the module layout and retry with the fresh etag.',
                'code' => 'stale_etag',
                'expected_etag' => $currentEtag,
                'provided_etag' => $ifMatch,
            ];
        }

        $layout = $this->yooHelper->getModuleLayout($module);

        if ($layout === null) {
            return ['error' => "Module {$moduleId} has no YOOtheme layout in content.", 'code' => 'module_layout_missing'];
        }

        $mutation = $mutator($layout, $arguments);

        if (isset($mutation['error'])) {
            return $mutation;
        }

        if (!isset($mutation['layout']) || !is_array($mutation['layout'])) {
            return [
                'error' => 'Internal error: mutation did not return a layout.',
                'code' => 'missing_mutation_layout',
            ];
        }

        $this->yooHelper->setModuleLayout($module, $mutation['layout']);
        $newEtag = $this->yooHelper->buildModuleLayoutEtag($module);

        $cache = $dryRun
            ? ['cleared' => false, 'groups' => [], 'failures' => [], 'reason' => 'dry_run']
            : $this->yooHelper->writeModuleLayout($module);

        unset($mutation['layout']);

        $response = array_merge([
            'storage' => 'module',
            'module_id' => $moduleId,
            'module_title' => (string) ($module['title'] ?? ''),
            'module_type' => (string) ($module['module'] ?? ''),
            'module_published' => (int) ($module['published'] ?? 0),
            'dry_run' => $dryRun,
            'would_change' => !hash_equals($currentEtag, $newEtag),
            'old_etag' => $currentEtag,
            'new_etag' => $newEtag,
            'cache' => $cache,
        ], $mutation);

        if ($dryRun) {
            $response['action'] = 'preview';
            $response['note'] = 'No changes were written. Retry with confirm_guarded_write=true and the same if_match if the preview is still current.';
        } else {
            $response['action'] = 'updated';
        }

        return $response;
    }
}
