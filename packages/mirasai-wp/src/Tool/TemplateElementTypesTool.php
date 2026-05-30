<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class TemplateElementTypesTool extends TemplateReadTool
{
    public function getName(): string
    {
        return 'template/element-types';
    }

    public function getDescription(): string
    {
        return 'Summarizes observed YOOtheme Builder element types and the installed runtime element registry. Can target all layouts or one key, post_id, or widget_id.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge($this->targetSelectorSchema(), [
                'include_runtime' => [
                    'type' => 'boolean',
                    'description' => 'Include installed YOOtheme runtime element types. Defaults to true.',
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
        $helper = new YoothemeWpHelper();
        $navigator = new YoothemeElementNavigator();
        $hasTarget = trim((string) ($arguments['key'] ?? '')) !== ''
            || (int) ($arguments['post_id'] ?? 0) > 0
            || trim((string) ($arguments['widget_id'] ?? '')) !== '';

        if ($hasTarget) {
            $target = $helper->resolveTarget($arguments);
            if (isset($target['error'])) {
                return $target;
            }

            $layouts = [$target['layout']];
            $targetMeta = [
                'storage' => $target['storage'],
                'id' => $target['id'],
                'label' => $target['label'],
                'etag' => $target['etag'],
            ];
        } else {
            $layouts = [];
            foreach ($helper->listLayouts(['template', 'post', 'widget']) as $row) {
                if (($row['storage'] ?? '') === 'template' && is_string($row['key'] ?? null)) {
                    $target = $helper->resolveTarget(['key' => $row['key']]);
                } elseif (($row['storage'] ?? '') === 'post' && isset($row['post_id'])) {
                    $target = $helper->resolveTarget(['post_id' => (int) $row['post_id']]);
                } elseif (($row['storage'] ?? '') === 'widget' && is_string($row['widget_id'] ?? null)) {
                    $target = $helper->resolveTarget(['widget_id' => $row['widget_id']]);
                } else {
                    continue;
                }

                if (!isset($target['error'])) {
                    $layouts[] = $target['layout'];
                }
            }

            $targetMeta = null;
        }

        $includeRuntime = !array_key_exists('include_runtime', $arguments) || (bool) $arguments['include_runtime'];
        $observed = $navigator->summarizeTypes($layouts);

        return [
            'target' => $targetMeta,
            'layout_count' => count($layouts),
            'observed_type_count' => count($observed),
            'observed_types' => $observed,
            'runtime_source' => $helper->elementsRuntimeSource(),
            'runtime_type_count' => $includeRuntime ? count($helper->listRuntimeElementTypes()) : null,
            'runtime_types' => $includeRuntime ? $helper->listRuntimeElementTypes() : null,
        ];
    }
}
