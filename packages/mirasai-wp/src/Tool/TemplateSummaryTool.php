<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class TemplateSummaryTool extends TemplateReadTool
{
    public function getName(): string
    {
        return 'template/summary';
    }

    public function getDescription(): string
    {
        return 'Returns a compact structural summary of one YOOtheme Builder layout by key, post_id, or widget_id.';
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

        $nodes = [];

        if ($target['storage'] === 'template' && is_array($target['raw'])) {
            $nodes = (new YoothemeWpHelper())->findTemplateTranslatableNodes($target['raw']);
        } elseif ($target['storage'] === 'widget') {
            $nodes = (new YoothemeLayoutProcessor())->findTranslatableNodes($target['layout']);
        }

        return [
            'storage' => $target['storage'],
            'id' => $target['id'],
            'label' => $target['label'],
            'etag' => $target['etag'],
            'meta' => $target['meta'] ?? [],
            'has_static_text' => $nodes !== [],
            'dynamic_only' => in_array($target['storage'], ['template', 'widget'], true) ? $nodes === [] : null,
            'translatable_node_count' => count($nodes),
            'summary' => (new YoothemeLayoutSummarizer())->summarize($target['layout']),
        ];
    }
}
