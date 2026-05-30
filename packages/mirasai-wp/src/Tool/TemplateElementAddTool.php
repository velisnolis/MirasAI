<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class TemplateElementAddTool extends AbstractTemplateElementWriteTool
{
    public function getName(): string
    {
        return 'template/element-add';
    }

    public function getDescription(): string
    {
        return 'Adds a child element to a YOOtheme Builder layout target. Supports templates, post/page layouts, and Builder widgets. Requires if_match and uses dry_run/confirm_guarded_write before writing.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['parent_path', 'if_match', 'element'],
            'properties' => array_merge($this->targetSelectorSchema(), [
                'parent_path' => ['type' => 'string', 'description' => 'Parent element path returned by template/element-list. Use root to add a top-level child.'],
                'if_match' => ['type' => 'string', 'description' => 'Required current layout etag. Stale values are rejected before any write.'],
                'element' => [
                    'type' => 'object',
                    'description' => 'YOOtheme element object to add. Must include type. props and children are optional.',
                    'required' => ['type'],
                    'properties' => [
                        'type' => ['type' => 'string'],
                        'props' => ['type' => 'object'],
                        'children' => ['type' => 'array'],
                    ],
                    'additionalProperties' => true,
                ],
                'position' => ['type' => 'string', 'enum' => ['append', 'prepend'], 'description' => 'Where to insert under the parent. Defaults to append.'],
                'include_element' => ['type' => 'boolean', 'description' => 'If true, return the added element object without children. Defaults to false.'],
                'dry_run' => ['type' => 'boolean', 'description' => 'If true, validate and preview without writing.'],
                'confirm_guarded_write' => ['type' => 'boolean', 'description' => 'Required for the real write after review. Not required when dry_run=true.'],
            ]),
        ];
    }

    public function handle(array $arguments): array
    {
        return $this->mutateTemplateElement($arguments, function (array $layout, array $arguments): array {
            $parentPath = trim((string) ($arguments['parent_path'] ?? ''));
            $element = $arguments['element'] ?? null;
            $position = trim((string) ($arguments['position'] ?? 'append'));
            $includeElement = !empty($arguments['include_element']);

            if (!is_array($element)) {
                return ['error' => 'element must be an object.', 'code' => 'invalid_element'];
            }

            $result = (new YoothemeElementNavigator())->addElement($layout, $parentPath, $element, $position);

            if (isset($result['error'])) {
                return $result;
            }

            $response = [
                'parent_path' => $parentPath,
                'path' => $result['metadata']['path'] ?? null,
                'position' => $position === 'prepend' ? 'prepend' : 'append',
                'metadata' => $result['metadata'],
                'layout' => $result['layout'],
            ];

            if ($includeElement) {
                $addedElement = $result['element'];
                unset($addedElement['children']);
                $response['element'] = $addedElement;
            }

            return $response;
        });
    }
}
