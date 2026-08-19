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
        return 'Sets canonical YOOtheme Dynamic Source bindings at source. Pass path for one template element, or leaves for several under a single if_match. The batch form is fail-closed: if any entry does not resolve to exactly one bound node, nothing is written. Its preview reports every bound node in the layout with a state of rebound, kept, untouched, or skipped_disabled, so a leaf nobody named is visible before the write. Requires if_match and uses dry_run/confirm_guarded_write before writing.';
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
                'field_mappings' => [
                'type' => 'object',
                'description' => 'Map element prop names to source field names or mapping objects. The native visibility condition is a mapping like any other, under the reserved prop _condition: {"_condition": {"name": "#index", "filters": {"condition": "!!", "show_empty": true}}} hides the element when the query returns nothing.',
                'additionalProperties' => [
                    'type' => ['string', 'object'],
                    'description' => 'A field name, or a mapping object. Date and number formatting goes in filters, not in arguments or directives.',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Source field name.'],
                        'arguments' => ['type' => 'object', 'description' => 'Field arguments passed to the source query.'],
                        'directives' => ['type' => 'object', 'description' => 'GraphQL directives applied to the field.'],
                        'filters' => ['type' => 'object', 'description' => 'Output filters, for example {"date": "d/m/Y"} to format a date.'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
                'include_element' => ['type' => 'boolean', 'description' => 'If true, return the updated element object without children. Defaults to false.'],
                'include_raw' => ['type' => 'boolean', 'description' => 'Include raw source payloads in before/after. Defaults to false.'],
                'dry_run' => ['type' => 'boolean', 'description' => 'If true, validate and preview without writing YOOtheme custom_data.'],
                'confirm_guarded_write' => ['type' => 'boolean', 'description' => 'Required for the real write after review. Not required when dry_run=true.'],
            ] + $this->leafBatchInputProperties(),
            'required' => ['if_match'],
        ];
    }

    public function handle(array $arguments): array
    {
        $path = trim((string) ($arguments['path'] ?? ''));

        if (isset($arguments['leaves'])) {
            if ($path !== '') {
                return [
                    'error' => 'Use path for one binding or leaves for a batch, not both.',
                    'code' => 'invalid_input',
                ];
            }

            if (!is_array($arguments['leaves'])) {
                return ['error' => 'leaves must be an array.', 'code' => 'invalid_leaves'];
            }

            return $this->handleLeafBatch($arguments, array_values($arguments['leaves']));
        }

        $includeElement = !empty($arguments['include_element']);
        $includeRaw = !empty($arguments['include_raw']);
        $source = $this->normalizeSourceArgument($arguments);

        if ($path === '') {
            return ['error' => 'path is required, or leaves for the batch form.', 'code' => 'invalid_path'];
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

    /**
     * @param array<string, mixed> $arguments
     * @param list<mixed> $leaves
     * @return array<string, mixed>
     */
    private function handleLeafBatch(array $arguments, array $leaves): array
    {
        $rebindDisabled = !empty($arguments['rebind_disabled']);

        return $this->mutateTemplateElement(
            $arguments,
            function (array $layout) use ($leaves, $rebindDisabled): array {
                $result = $this->applyLeafBatch(new YooThemeElementNavigator(), $layout, $leaves, $rebindDisabled);

                if (isset($result['error'])) {
                    return $result;
                }

                return [
                    'layout' => $result['layout'],
                    'leaves' => $result['leaves'],
                    'leaf_states' => array_count_values(array_column($result['leaves'], 'state')),
                ];
            },
        );
    }
}
