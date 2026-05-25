<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\YooThemeElementNavigator;

class TemplateElementCloneTool extends AbstractTemplateElementWriteTool
{
    public function getName(): string
    {
        return 'template/element-clone';
    }

    public function getDescription(): string
    {
        return 'Clones one YOOtheme Builder template element as the next sibling. Requires if_match with the current template etag and uses dry_run/confirm_guarded_write before writing.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'key' => ['type' => 'string', 'description' => 'Template storage key as returned by template/list.'],
                'path' => ['type' => 'string', 'description' => 'Element path to clone as returned by template/element-list. root cannot be cloned.'],
                'if_match' => ['type' => 'string', 'description' => 'Required current template etag. Stale values are rejected before any write.'],
                'include_element' => ['type' => 'boolean', 'description' => 'If true, return the cloned element without children. Defaults to false.'],
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
            $includeElement = !empty($arguments['include_element']);

            $result = (new YooThemeElementNavigator())->cloneElement($layout, $path);

            if (isset($result['error'])) {
                return $result;
            }

            $response = [
                'source_path' => $result['source_path'],
                'new_path' => $result['new_path'],
                'metadata' => $result['metadata'],
                'layout' => $result['layout'],
            ];

            if ($includeElement) {
                $clonedElement = $result['element'];
                unset($clonedElement['children']);
                $response['element'] = $clonedElement;
            }

            return $response;
        });
    }
}
