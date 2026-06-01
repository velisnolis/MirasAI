<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\YooThemeElementNavigator;

class TemplateElementSourceSetTool extends AbstractTemplateElementWriteTool
{
    use TemplateElementSourceSupportTrait;

    public function getName(): string
    {
        return 'template/element-source-set';
    }

    public function getDescription(): string
    {
        return 'Sets the canonical YOOtheme Dynamic Source binding for one template element at source. Requires if_match and uses dry_run/confirm_guarded_write before writing.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'key' => ['type' => 'string', 'description' => 'Template storage key as returned by template/list. Use one of key, article_id, or module_id.'],
                'article_id' => ['type' => 'integer', 'description' => 'Optional Joomla article ID with a YOOtheme Builder layout in fulltext. Use one of key, article_id, or module_id.'],
                'module_id' => ['type' => 'integer', 'description' => 'Optional YOOtheme Builder module ID with a layout in #__modules.content. Use one of key, article_id, or module_id.'],
                'path' => ['type' => 'string', 'description' => 'Element path as returned by template/element-list.'],
                'if_match' => ['type' => 'string', 'description' => 'Required current template etag. Stale values are rejected before any write.'],
                'source' => ['type' => 'object', 'description' => 'Raw YOOtheme source payload to write to the element source binding.'],
                'source_name' => ['type' => 'string', 'description' => 'Source type/name used when source is omitted, for example Article or article.'],
                'query_field' => ['type' => 'string', 'description' => 'Optional query field name, for example article.'],
                'query_arguments' => ['type' => 'object', 'description' => 'Optional query field arguments.'],
                'query_directives' => ['type' => 'object', 'description' => 'Optional query field directives.'],
                'field_mappings' => ['type' => 'object', 'description' => 'Map element prop names to source field names or mapping objects.'],
                'include_element' => ['type' => 'boolean', 'description' => 'If true, return the updated element object without children. Defaults to false.'],
                'include_raw' => ['type' => 'boolean', 'description' => 'Include raw source payloads in before/after. Defaults to false.'],
                'dry_run' => ['type' => 'boolean', 'description' => 'If true, validate and preview without writing YOOtheme custom_data.'],
                'confirm_guarded_write' => ['type' => 'boolean', 'description' => 'Required for the real write after review. Not required when dry_run=true.'],
            ],
            'required' => ['path', 'if_match'],
        ];
    }

    public function handle(array $arguments): array
    {
        $path = trim((string) ($arguments['path'] ?? ''));
        $includeElement = !empty($arguments['include_element']);
        $includeRaw = !empty($arguments['include_raw']);
        $source = $this->normalizeSourceArgument($arguments);

        if ($path === '') {
            return ['error' => 'path is required.', 'code' => 'invalid_path'];
        }

        if (isset($source['error'])) {
            return $source;
        }

        return $this->mutateTemplateElement(
            $arguments,
            function (array $layout) use ($path, $source, $includeElement, $includeRaw): array {
                $current = (new YooThemeElementNavigator())->findElement($layout, $path);

                if ($current === null) {
                    return ['error' => "Element path {$path} not found.", 'code' => 'element_not_found'];
                }

                $result = (new YooThemeElementNavigator())->setElementSource($layout, $path, $source);

                if (isset($result['error'])) {
                    return $result;
                }

                $before = $this->summarizeBinding($current['element']);
                $after = $this->summarizeBinding($result['element']);

                if (!$includeRaw) {
                    unset($before['raw_source'], $after['raw_source']);
                }

                $response = [
                    'layout' => $result['layout'],
                    'path' => $path,
                    'metadata' => $result['metadata'],
                    'before' => $before,
                    'after' => $after,
                ];

                if ($includeElement) {
                    $element = $result['element'];
                    unset($element['children']);
                    $response['element'] = $element;
                }

                return $response;
            },
        );
    }
}
