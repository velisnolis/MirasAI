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
        return 'Lists YOOtheme Builder elements for one layout by key, post_id, or widget_id. Paths can be used with template/element-read. In a path the number is the position among all the children of the parent, not among those of the same type: column[0]>text[2] is the third child of the column, whatever the first two are. Copy paths from here rather than building them. In full mode use subtree, max_depth and limit (default 500, max 2000); repeat filters with next_cursor to continue. A cursor expires when the layout, target or filters change. ETag and paths always refer to the full layout. mode=outline returns a nested type/path/title tree; mode=bindings_only returns Dynamic Source bindings only. Every mode carries status on an element the Builder keeps but does not output, so a disabled row is visible without reading its props; bindings_only adds disabled_by for a binding sitting inside a disabled ancestor.';
    }

    public function getInputSchema(): array
    {
        $schema = parent::getInputSchema();
        $schema['properties'] += YoothemeElementNavigator::pageSchema();
        return $schema;
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

        if ($mode['mode'] !== 'full' && array_intersect(array_keys($arguments), array_keys(YoothemeElementNavigator::pageSchema())) !== []) {
            return ['error' => 'Paging and subtree filters require mode=full.', 'code' => 'invalid_arguments'];
        }

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
        $page = YoothemeElementNavigator::pageElements($elements, $arguments, $response);
        if (isset($page['error'])) return $page;
        $response += $page;

        return $response;
    }
}
