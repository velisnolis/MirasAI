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
        return 'Moves one YOOtheme Builder element under another parent. Supports templates, post/page layouts, and Builder widgets. Requires if_match and uses dry_run/confirm_guarded_write before writing.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['path', 'target_parent_path', 'if_match'],
            'properties' => array_merge($this->targetSelectorSchema(), [
                'path' => ['type' => 'string', 'description' => 'Element path to move as returned by template/element-list. root cannot be moved.'],
                'target_parent_path' => ['type' => 'string', 'description' => 'Target parent element path. Use root to move to the top level.'],
                'if_match' => ['type' => 'string', 'description' => 'Required current layout etag. Stale values are rejected before any write.'],
                'position' => ['type' => 'string', 'enum' => ['append', 'prepend'], 'description' => 'Where to insert under the target parent. Defaults to append.'],
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
            $position = trim((string) ($arguments['position'] ?? 'append'));
            $includeElement = !empty($arguments['include_element']);

            $result = (new YoothemeElementNavigator())->moveElement($layout, $path, $targetParentPath, $position);

            if (isset($result['error'])) {
                return $result;
            }

            $response = [
                'old_path' => $result['old_path'],
                'new_path' => $result['new_path'],
                'target_parent_path' => $targetParentPath,
                'position' => $position === 'prepend' ? 'prepend' : 'append',
                'metadata' => $result['metadata'],
                'layout' => $result['layout'],
            ];

            if ($includeElement) {
                $movedElement = $result['element'];
                unset($movedElement['children']);
                $response['element'] = $movedElement;
            }

            return $response;
        });
    }
}
