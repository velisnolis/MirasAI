<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class TemplateReadTool extends AbstractTool
{
    public function getName(): string
    {
        return 'template/read';
    }

    public function getDescription(): string
    {
        return 'Reads one YOOtheme Builder layout by storage selector. Use one of key, post_id, or widget_id. Returns translatable_nodes for template/translate and template/widget-translate workflows.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->targetSelectorSchema(),
        ];
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

        $response = [
            'storage' => $target['storage'],
            'id' => $target['id'],
            'label' => $target['label'],
            'etag' => $target['etag'],
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
