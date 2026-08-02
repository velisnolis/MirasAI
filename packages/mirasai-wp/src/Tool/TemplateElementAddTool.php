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
        return 'Adds a child element to a YOOtheme Builder layout target. Place it under a parent with parent_path, or between existing siblings with before_path/after_path. Supports templates, post/page layouts, and Builder widgets. Requires if_match and uses dry_run/confirm_guarded_write before writing.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['if_match', 'element'],
            'properties' => array_merge($this->targetSelectorSchema(), [
                'parent_path' => ['type' => 'string', 'description' => 'Parent element path returned by template/element-list. Use root to add a top-level child. Mutually exclusive with before_path and after_path.'],
                'before_path' => ['type' => 'string', 'description' => 'Insert immediately before this sibling, which also determines the parent. Use this to compose a page rather than appending and moving afterwards.'],
                'after_path' => ['type' => 'string', 'description' => 'Insert immediately after this sibling, which also determines the parent.'],
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
                'position' => ['type' => 'string', 'enum' => ['append', 'prepend'], 'description' => 'Where to insert under parent_path. Defaults to append. Not accepted together with before_path or after_path.'],
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
            $beforePath = trim((string) ($arguments['before_path'] ?? ''));
            $afterPath = trim((string) ($arguments['after_path'] ?? ''));
            $element = $arguments['element'] ?? null;
            $includeElement = !empty($arguments['include_element']);
            $navigator = new YoothemeElementNavigator();

            if (!is_array($element)) {
                return ['error' => 'element must be an object.', 'code' => 'invalid_element'];
            }

            $rejection = $this->rejectInvalidProps(
                (string) ($element['type'] ?? ''),
                is_array($element['props'] ?? null) ? $element['props'] : []
            );

            if ($rejection !== null) {
                return $rejection;
            }

            $placements = array_keys(array_filter([
                'parent_path' => $parentPath !== '',
                'before_path' => $beforePath !== '',
                'after_path' => $afterPath !== '',
            ]));

            if (count($placements) !== 1) {
                return [
                    'error' => $placements === []
                        ? 'Give exactly one placement: parent_path, before_path, or after_path.'
                        : 'Placement arguments are mutually exclusive; got ' . implode(' and ', $placements) . '.',
                    'code' => 'invalid_placement',
                ];
            }

            $placement = $placements[0];

            if ($placement !== 'parent_path' && isset($arguments['position'])) {
                return [
                    'error' => 'position only applies to parent_path; ' . $placement . ' already fixes the insertion point.',
                    'code' => 'conflicting_arguments',
                ];
            }

            if ($placement === 'parent_path') {
                $position = trim((string) ($arguments['position'] ?? 'append'));
                $result = $navigator->addElement($layout, $parentPath, $element, $position);
            } else {
                $result = $navigator->addElementBeside(
                    $layout,
                    $placement === 'before_path' ? $beforePath : $afterPath,
                    $element,
                    $placement === 'before_path' ? 'before' : 'after'
                );
            }

            if (isset($result['error'])) {
                return $result;
            }

            $response = [
                'path' => $result['metadata']['path'] ?? null,
                'placement' => $placement,
                'metadata' => $result['metadata'],
                'layout' => $result['layout'],
            ];

            if ($placement === 'parent_path') {
                $response['parent_path'] = $parentPath;
                $response['position'] = $position === 'prepend' ? 'prepend' : 'append';
            } else {
                $response['reference_path'] = $placement === 'before_path' ? $beforePath : $afterPath;
                $response['parent_path'] = $result['reference_parent_path'];
            }

            if ($includeElement) {
                $addedElement = $result['element'];
                unset($addedElement['children']);
                $response['element'] = $addedElement;
            }

            return $response;
        });
    }
}
