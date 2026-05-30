<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class TemplateElementSourceSetTool extends AbstractTemplateElementWriteTool
{
    use TemplateElementSourceSupportTrait;

    public function getName(): string
    {
        return 'template/element-source-set';
    }

    public function getDescription(): string
    {
        return 'Sets the canonical YOOtheme Dynamic Source binding for one element at props.source. Requires if_match and uses dry_run/confirm_guarded_write before writing.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['path', 'if_match'],
            'properties' => array_merge($this->targetSelectorSchema(), $this->sourceInputProperties(), [
                'path' => ['type' => 'string', 'description' => 'Element path returned by template/element-list.'],
                'if_match' => ['type' => 'string', 'description' => 'Required current layout etag. Stale values are rejected before any write.'],
                'include_element' => ['type' => 'boolean', 'description' => 'If true, return the updated element object without children. Defaults to false.'],
                'include_raw' => ['type' => 'boolean', 'description' => 'Include raw source payloads in before/after. Defaults to false.'],
                'dry_run' => ['type' => 'boolean', 'description' => 'If true, validate and preview without writing.'],
                'confirm_guarded_write' => ['type' => 'boolean', 'description' => 'Required for the real write after review. Not required when dry_run=true.'],
            ]),
        ];
    }

    public function handle(array $arguments): array
    {
        $path = trim((string) ($arguments['path'] ?? ''));
        $includeElement = !empty($arguments['include_element']);
        $includeRaw = !empty($arguments['include_raw']);
        $source = $this->normalizeSourceArgument($arguments);

        if ($path === '') {
            return ['error' => 'path is required.', 'code' => 'invalid_path'];
        }

        if (isset($source['error'])) {
            return $source;
        }

        return $this->mutateTemplateElement(
            $arguments,
            function (array $layout) use ($path, $source, $includeElement, $includeRaw): array {
                $current = (new YoothemeElementNavigator())->findElement($layout, $path);

                if ($current === null) {
                    return ['error' => "Element path {$path} not found.", 'code' => 'element_not_found'];
                }

                $result = (new YoothemeElementNavigator())->setElementSource($layout, $path, $source);

                if (isset($result['error'])) {
                    return $result;
                }

                $before = $this->summarizeBinding($current['element']);
                $after = $this->summarizeBinding($result['element']);

                if (!$includeRaw) {
                    unset($before['raw_source'], $after['raw_source']);
                }

                $response = [
                    'layout' => $result['layout'],
                    'path' => $path,
                    'metadata' => $result['metadata'],
                    'before' => $before,
                    'after' => $after,
                ];

                if ($includeElement) {
                    $element = $result['element'];
                    unset($element['children']);
                    $response['element'] = $element;
                }

                return $response;
            },
        );
    }
}
