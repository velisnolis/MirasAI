<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class TemplateElementReadTool extends TemplateReadTool
{
    public function getName(): string
    {
        return 'template/element-read';
    }

    public function getDescription(): string
    {
        return 'Reads one YOOtheme Builder element by path from a template, post/page layout, or Builder widget.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['path'],
            'properties' => array_merge($this->targetSelectorSchema(), [
                'path' => [
                    'type' => 'string',
                    'description' => 'Element path returned by template/element-list, for example root>section[0]>row[0].',
                ],
                'include_children' => [
                    'type' => 'boolean',
                    'description' => 'If true, include the full child subtree. Defaults to false.',
                ],
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        $path = trim((string) ($arguments['path'] ?? ''));

        if ($path === '') {
            return ['error' => 'path is required.', 'code' => 'missing_path'];
        }

        $target = (new YoothemeWpHelper())->resolveTarget($arguments);

        if (isset($target['error'])) {
            return $target;
        }

        $result = (new YoothemeElementNavigator())->findElement($target['layout'], $path);

        if ($result === null) {
            return ['error' => "Element path {$path} not found.", 'code' => 'element_not_found'];
        }

        $element = $result['element'];
        $includeChildren = !empty($arguments['include_children']);

        if (!$includeChildren) {
            unset($element['children']);
        }

        return [
            'storage' => $target['storage'],
            'id' => $target['id'],
            'label' => $target['label'],
            'etag' => $target['etag'],
            'metadata' => $result['metadata'],
            'include_children' => $includeChildren,
            'element' => $element,
        ];
    }
}
