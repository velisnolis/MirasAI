<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class TemplateElementSourceReadTool extends TemplateReadTool
{
    use TemplateElementSourceSupportTrait;

    public function getName(): string
    {
        return 'template/element-source-read';
    }

    public function getDescription(): string
    {
        return 'Reads the Dynamic Source binding for one YOOtheme Builder element from a template, post/page layout, or Builder widget.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['path'],
            'properties' => array_merge($this->targetSelectorSchema(), [
                'path' => [
                    'type' => 'string',
                    'description' => 'Element path returned by template/element-list.',
                ],
                'include_raw' => [
                    'type' => 'boolean',
                    'description' => 'Include the raw source binding payload. Defaults to false.',
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
        $includeRaw = !empty($arguments['include_raw']);

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

        $binding = $this->summarizeBinding($result['element']);

        if (!$includeRaw) {
            unset($binding['raw_source']);
        }

        return [
            'storage' => $target['storage'],
            'id' => $target['id'],
            'label' => $target['label'],
            'etag' => $target['etag'],
            'metadata' => $result['metadata'],
            'binding' => $binding,
        ];
    }
}
