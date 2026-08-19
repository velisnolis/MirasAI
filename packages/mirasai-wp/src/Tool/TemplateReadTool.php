<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class TemplateReadTool extends AbstractTool
{
    use TemplateElementSourceSupportTrait;

    public function getName(): string
    {
        return 'template/read';
    }

    public function getDescription(): string
    {
        return 'Reads one YOOtheme Builder layout by storage selector. Use one of key, post_id, or widget_id. Returns translatable_nodes for template/translate and template/widget-translate workflows. mode=outline returns a nested type/path/title tree with no props; mode=bindings_only returns Dynamic Source bindings only. The etag is always the full layout.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge($this->targetSelectorSchema(), [
                'mode' => YoothemeElementNavigator::readModeSchemaProperty(),
            ]),
        ];
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

        $response = [
            'storage' => $target['storage'],
            'id' => $target['id'],
            'label' => $target['label'],
            'etag' => $target['etag'],
            'mode' => $mode['mode'],
            'meta' => $target['meta'] ?? [],
            'layout' => $target['layout'],
            'raw' => $target['raw'],
        ];

        $helper = new YoothemeWpHelper();
        if ($target['storage'] === 'template' && is_array($target['raw'])) {
            $nodes = $helper->findTemplateTranslatableNodes($target['raw']);
            $response['language'] = $helper->templateLanguage($target['raw']);
            $response['dynamic_only'] = $nodes === [];
            $response['has_static_text'] = $nodes !== [];
            $response['assignment_fingerprint'] = $helper->buildTemplateAssignmentFingerprint($target['raw']);
            $response['translatable_nodes'] = $nodes;
        }

        if ($target['storage'] === 'widget') {
            $nodes = (new YoothemeLayoutProcessor())->findTranslatableNodes($target['layout']);
            $response['language'] = is_string($target['raw']['wpml_language'] ?? null)
                ? (string) $target['raw']['wpml_language']
                : null;
            $response['dynamic_only'] = $nodes === [];
            $response['has_static_text'] = $nodes !== [];
            $response['translatable_nodes'] = $nodes;
        }

        if ($mode['mode'] === 'full') {
            return $response;
        }

        unset($response['layout'], $response['raw'], $response['translatable_nodes'], $response['meta']);
        $layout = is_array($target['layout']) ? $target['layout'] : ['type' => 'layout'];

        if ($mode['mode'] === 'outline') {
            $response['tree'] = (new YoothemeElementNavigator())->outlineTree($layout);

            return $response;
        }

        $response['bindings'] = $this->bindingsOnlyFromLayout(new YoothemeElementNavigator(), $layout);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    protected function targetSelectorSchema(): array
    {
        return [
            'key' => [
                'type' => 'string',
                'description' => 'YOOtheme template key from template/list.',
            ],
            'post_id' => [
                'type' => 'integer',
                'description' => 'WordPress post/page ID with a YOOtheme Builder layout.',
            ],
            'widget_id' => [
                'type' => 'string',
                'description' => 'YOOtheme Builder widget instance ID from template/list.',
            ],
        ];
    }
}
