<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\YooThemeElementNavigator;

class TemplateElementDeleteTool extends AbstractTemplateElementWriteTool
{
    public function getName(): string
    {
        return 'template/element-delete';
    }

    public function getDescription(): string
    {
        return 'Deletes one YOOtheme Builder template element. Requires if_match with the current template etag and uses dry_run/confirm_guarded_write before writing.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'key' => ['type' => 'string', 'description' => 'Template storage key as returned by template/list.'],
                'path' => ['type' => 'string', 'description' => 'Element path to delete as returned by template/element-list. root cannot be deleted.'],
                'if_match' => ['type' => 'string', 'description' => 'Required current template etag. Stale values are rejected before any write.'],
                'dry_run' => ['type' => 'boolean', 'description' => 'If true, validate and preview without writing YOOtheme custom_data.'],
                'confirm_guarded_write' => ['type' => 'boolean', 'description' => 'Required for the real write after review. Not required when dry_run=true.'],
            ],
            'required' => ['key', 'path', 'if_match'],
        ];
    }

    public function handle(array $arguments): array
    {
        return $this->mutateTemplateElement($arguments, function (array $layout, array $arguments): array {
            $path = trim((string) ($arguments['path'] ?? ''));
            $result = (new YooThemeElementNavigator())->deleteElement($layout, $path);

            if (isset($result['error'])) {
                return $result;
            }

            return [
                'deleted_path' => $result['deleted_path'],
                'deleted_type' => $result['deleted_type'],
                'layout' => $result['layout'],
            ];
        });
    }
}
