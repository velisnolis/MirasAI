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
        return 'Clones one YOOtheme Builder layout element. The copy lands as the next sibling of the source unless before_path or after_path places it elsewhere. Supports templates, article layouts, and Builder modules. Requires if_match and uses dry_run/confirm_guarded_write before writing.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge($this->targetSelectorSchema(), [
                'path' => ['type' => 'string', 'description' => 'Element path to clone as returned by template/element-list. root cannot be cloned.'],
                'before_path' => ['type' => 'string', 'description' => 'Place the copy immediately before this sibling, which also determines its parent. Omit both before_path and after_path to leave the copy next to its source.'],
                'after_path' => ['type' => 'string', 'description' => 'Place the copy immediately after this sibling, which also determines its parent.'],
                'if_match' => ['type' => 'string', 'description' => 'Required current layout etag. Stale values are rejected before any write.'],
                'include_element' => ['type' => 'boolean', 'description' => 'If true, return the cloned element without children. Defaults to false.'],
                'dry_run' => ['type' => 'boolean', 'description' => 'If true, validate and preview without writing YOOtheme custom_data.'],
                'confirm_guarded_write' => ['type' => 'boolean', 'description' => 'Required for the real write after review. Not required when dry_run=true.'],
            ]),
            'required' => ['path', 'if_match'],
        ];
    }

    public function handle(array $arguments): array
    {
        return $this->mutateTemplateElement($arguments, function (array $layout, array $arguments): array {
            $path = trim((string) ($arguments['path'] ?? ''));
            $beforePath = trim((string) ($arguments['before_path'] ?? ''));
            $afterPath = trim((string) ($arguments['after_path'] ?? ''));
            $includeElement = !empty($arguments['include_element']);
            $navigator = new YooThemeElementNavigator();

            if ($beforePath !== '' && $afterPath !== '') {
                return [
                    'error' => 'before_path and after_path are mutually exclusive.',
                    'code' => 'invalid_placement',
                ];
            }

            if ($beforePath !== '' || $afterPath !== '') {
                $placement = $beforePath !== '' ? 'before_path' : 'after_path';
                $result = $navigator->cloneElementBeside(
                    $layout,
                    $path,
                    $beforePath !== '' ? $beforePath : $afterPath,
                    $beforePath !== '' ? 'before' : 'after'
                );
            } else {
                $placement = 'next_sibling';
                $result = $navigator->cloneElement($layout, $path);
            }

            if (isset($result['error'])) {
                return $result;
            }

            $response = [
                'source_path' => $result['source_path'],
                'new_path' => $result['new_path'],
                'placement' => $placement,
                'metadata' => $result['metadata'],
                'layout' => $result['layout'],
            ];

            if ($placement !== 'next_sibling') {
                $response['reference_path'] = $beforePath !== '' ? $beforePath : $afterPath;
                $response['parent_path'] = $result['reference_parent_path'];
            }

            if ($includeElement) {
                $clonedElement = $result['element'];
                unset($clonedElement['children']);
                $response['element'] = $clonedElement;
            }

            return $response;
        });
    }
}
