<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class TemplateMoveTool extends AbstractTemplateOrderTool
{
    public function getName(): string
    {
        return 'template/move';
    }

    public function getDescription(): string
    {
        return 'Moves a YOOtheme template among the templates of its type. YOOtheme uses the first enabled template whose type '
            . 'and conditions fit a page, so the order decides which one visitors get. position 0 is checked first. The preview '
            . '(before/after) shows the order and what this template does in it. if_match is the collection_etag of template/list.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->baseSchema() + [
                'position' => ['type' => 'integer', 'minimum' => 0, 'description' => 'New place among the templates of the same type; 0 is checked first.'],
            ],
            'required' => ['key', 'position', 'if_match'],
        ];
    }

    protected function change(array $templates, string $key, array $arguments): array
    {
        return TemplateOrder::moved($templates, $key, (int) ($arguments['position'] ?? 0));
    }
}
