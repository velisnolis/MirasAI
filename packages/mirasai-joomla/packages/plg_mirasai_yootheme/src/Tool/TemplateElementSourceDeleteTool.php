<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\YooThemeElementNavigator;

class TemplateElementSourceDeleteTool extends AbstractTemplateElementWriteTool
{
    use TemplateElementSourceSupportTrait;

    public function getName(): string
    {
        return 'template/element-source-delete';
    }

    public function getDescription(): string
    {
        return 'Deletes YOOtheme Dynamic Source bindings from one template element. Defaults to removing props.source plus compatibility carriers source and source_extended. Requires if_match and uses dry_run/confirm_guarded_write before writing.';
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
                'locations' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => ['props.source', 'source', 'source_extended']],
                    'description' => 'Binding locations to remove. Defaults to all known locations.',
                ],
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
        $locations = $this->normalizeLocations($arguments['locations'] ?? null);

        if ($path === '') {
            return ['error' => 'path is required.', 'code' => 'invalid_path'];
        }

        if ($locations === []) {
            return ['error' => 'locations must be an array containing props.source, source, or source_extended.', 'code' => 'invalid_locations'];
        }

        return $this->mutateTemplateElement(
            $arguments,
            function (array $layout) use ($path, $locations, $includeElement, $includeRaw): array {
                $current = (new YooThemeElementNavigator())->findElement($layout, $path);

                if ($current === null) {
                    return ['error' => "Element path {$path} not found.", 'code' => 'element_not_found'];
                }

                $result = (new YooThemeElementNavigator())->deleteElementSource($layout, $path, $locations);

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
                    'removed_locations' => $result['removed_locations'],
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

    /**
     * @return list<string>
     */
    private function normalizeLocations(mixed $value): array
    {
        $default = ['props.source', 'source', 'source_extended'];

        if ($value === null) {
            return $default;
        }

        if (!is_array($value)) {
            return [];
        }

        $allowed = array_flip($default);
        $locations = [];

        foreach ($value as $item) {
            if (!is_string($item) || !isset($allowed[$item])) {
                return [];
            }

            $locations[] = $item;
        }

        return array_values(array_unique($locations));
    }
}
