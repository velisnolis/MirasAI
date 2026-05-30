<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class TemplateElementListTool extends TemplateReadTool
{
    public function getName(): string
    {
        return 'template/element-list';
    }

    public function getDescription(): string
    {
        return 'Lists YOOtheme Builder elements for one layout by key, post_id, or widget_id. Paths can be used with template/element-read.';
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        $target = (new YoothemeWpHelper())->resolveTarget($arguments);

        if (isset($target['error'])) {
            return $target;
        }

        $elements = (new YoothemeElementNavigator())->listElements($target['layout']);

        return [
            'storage' => $target['storage'],
            'id' => $target['id'],
            'label' => $target['label'],
            'etag' => $target['etag'],
            'total' => count($elements),
            'elements' => $elements,
        ];
    }
}
