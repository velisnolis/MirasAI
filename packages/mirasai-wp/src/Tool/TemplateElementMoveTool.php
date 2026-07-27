<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class TemplateElementMoveTool extends AbstractTemplateElementWriteTool
{
    public function getName(): string
    {
        return 'template/element-move';
    }

    public function getDescription(): string
    {
        return 'Moves one YOOtheme Builder element. Place it under a parent with target_parent_path, or between existing siblings with before_path/after_path. Supports templates, post/page layouts, and Builder widgets. Requires if_match and uses dry_run/confirm_guarded_write before writing.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['path', 'if_match'],
            'properties' => array_merge($this->targetSelectorSchema(), [
                'path' => ['type' => 'string', 'description' => 'Element path to move as returned by template/element-list. root cannot be moved.'],
                'target_parent_path' => ['type' => 'string', 'description' => 'Target parent element path. Use root to move to the top level. Mutually exclusive with before_path and after_path.'],
                'before_path' => ['type' => 'string', 'description' => 'Place the element immediately before this sibling, which also determines the new parent. Use this to insert between two existing elements.'],
                'after_path' => ['type' => 'string', 'description' => 'Place the element immediately after this sibling, which also determines the new parent.'],
                'if_match' => ['type' => 'string', 'description' => 'Required current layout etag. Stale values are rejected before any write.'],
                'position' => ['type' => 'string', 'enum' => ['append', 'prepend'], 'description' => 'Where to insert under target_parent_path. Defaults to append. Not accepted together with before_path or after_path.'],
                'include_element' => ['type' => 'boolean', 'description' => 'If true, return the moved element object without children. Defaults to false.'],
                'dry_run' => ['type' => 'boolean', 'description' => 'If true, validate and preview without writing.'],
                'confirm_guarded_write' => ['type' => 'boolean', 'description' => 'Required for the real write after review. Not required when dry_run=true.'],
            ]),
        ];
    }

    public function handle(array $arguments): array
    {
        return $this->mutateTemplateElement($arguments, function (array $layout, array $arguments): array {
            $path = trim((string) ($arguments['path'] ?? ''));
            $targetParentPath = trim((string) ($arguments['target_parent_path'] ?? ''));
            $beforePath = trim((string) ($arguments['before_path'] ?? ''));
            $afterPath = trim((string) ($arguments['after_path'] ?? ''));
            $includeElement = !empty($arguments['include_element']);
            $navigator = new YoothemeElementNavigator();

            $placements = array_keys(array_filter([
                'target_parent_path' => $targetParentPath !== '',
                'before_path' => $beforePath !== '',
                'after_path' => $afterPath !== '',
            ]));

            if (count($placements) !== 1) {
                return [
                    'error' => $placements === []
                        ? 'Give exactly one placement: target_parent_path, before_path, or after_path.'
                        : 'Placement arguments are mutually exclusive; got ' . implode(' and ', $placements) . '.',
                    'code' => 'invalid_placement',
                ];
            }

            $placement = $placements[0];

            if ($placement !== 'target_parent_path' && isset($arguments['position'])) {
                return [
                    'error' => 'position only applies to target_parent_path; ' . $placement . ' already fixes the insertion point.',
                    'code' => 'conflicting_arguments',
                ];
            }

            if ($placement === 'target_parent_path') {
                $position = trim((string) ($arguments['position'] ?? 'append'));
                $result = $navigator->moveElement($layout, $path, $targetParentPath, $position);
            } else {
                $mode = $placement === 'before_path' ? 'before' : 'after';
                $result = $navigator->moveElementBeside(
                    $layout,
                    $path,
                    $placement === 'before_path' ? $beforePath : $afterPath,
                    $mode
                );
            }

            if (isset($result['error'])) {
                return $result;
            }

            $response = [
                'old_path' => $result['old_path'],
                'new_path' => $result['new_path'],
                'placement' => $placement,
                'metadata' => $result['metadata'],
                'layout' => $result['layout'],
            ];

            if ($placement === 'target_parent_path') {
                $response['target_parent_path'] = $targetParentPath;
                $response['position'] = $position === 'prepend' ? 'prepend' : 'append';
            } else {
                $response['reference_path'] = $placement === 'before_path' ? $beforePath : $afterPath;
                $response['target_parent_path'] = $result['reference_parent_path'];
            }

            if ($includeElement) {
                $movedElement = $result['element'];
                unset($movedElement['children']);
                $response['element'] = $movedElement;
            }

            return $response;
        });
    }
}
