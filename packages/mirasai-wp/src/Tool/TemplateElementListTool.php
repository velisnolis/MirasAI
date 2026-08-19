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
        return 'Lists YOOtheme Builder elements for one layout by key, post_id, or widget_id. Paths can be used with template/element-read. mode=outline returns a nested type/path/title tree; mode=bindings_only returns Dynamic Source bindings only. Every mode carries status on an element the Builder keeps but does not output, so a disabled row is visible without reading its props; bindings_only adds disabled_by for a binding sitting inside a disabled ancestor.';
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        $mode = YoothemeElementNavigator::normalizeReadMode($arguments['mode'] ?? null);

        if (isset($mode['error'])) {
            return $mode;
        }

        $target = (new YoothemeWpHelper())->resolveTarget($arguments);

        if (isset($target['error'])) {
            return $target;
        }

        $layout = is_array($target['layout']) ? $target['layout'] : ['type' => 'layout'];
        $navigator = new YoothemeElementNavigator();
        $response = [
            'storage' => $target['storage'],
            'id' => $target['id'],
            'label' => $target['label'],
            'etag' => $target['etag'],
            'mode' => $mode['mode'],
        ];

        if ($mode['mode'] === 'outline') {
            $response['tree'] = $navigator->outlineTree($layout);

            return $response;
        }

        if ($mode['mode'] === 'bindings_only') {
            $response['bindings'] = $this->bindingsOnlyFromLayout($navigator, $layout);
            $response['total'] = count($response['bindings']);

            return $response;
        }

        $elements = $navigator->listElements($layout);
        $response['total'] = count($elements);
        $response['elements'] = $elements;

        return $response;
    }
}
