<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\YooThemeElementNavigator;

class TemplateElementMoveTool extends AbstractTemplateElementWriteTool
{
    public function getName(): string
    {
        return 'template/element-move';
    }

    public function getDescription(): string
    {
        return 'Moves one YOOtheme Builder template element under another parent. Requires if_match with the current template etag and uses dry_run/confirm_guarded_write before writing.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'key' => ['type' => 'string', 'description' => 'Template storage key as returned by template/list.'],
                'path' => ['type' => 'string', 'description' => 'Element path to move as returned by template/element-list. root cannot be moved.'],
                'target_parent_path' => ['type' => 'string', 'description' => 'Target parent element path. Use root to move to the top level.'],
                'if_match' => ['type' => 'string', 'description' => 'Required current template etag. Stale values are rejected before any write.'],
                'position' => ['type' => 'string', 'enum' => ['append', 'prepend'], 'description' => 'Where to insert under the target parent. Defaults to append.'],
                'include_element' => ['type' => 'boolean', 'description' => 'If true, return the moved element without children. Defaults to false.'],
                'dry_run' => ['type' => 'boolean', 'description' => 'If true, validate and preview without writing YOOtheme custom_data.'],
                'confirm_guarded_write' => ['type' => 'boolean', 'description' => 'Required for the real write after review. Not required when dry_run=true.'],
            ],
            'required' => ['key', 'path', 'target_parent_path', 'if_match'],
        ];
    }

    public function handle(array $arguments): array
    {
        return $this->mutateTemplateElement($arguments, function (array $layout, array $arguments): array {
            $path = trim((string) ($arguments['path'] ?? ''));
            $targetParentPath = trim((string) ($arguments['target_parent_path'] ?? ''));
            $position = trim((string) ($arguments['position'] ?? 'append'));
            $includeElement = !empty($arguments['include_element']);

            $result = (new YooThemeElementNavigator())->moveElement($layout, $path, $targetParentPath, $position);

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
