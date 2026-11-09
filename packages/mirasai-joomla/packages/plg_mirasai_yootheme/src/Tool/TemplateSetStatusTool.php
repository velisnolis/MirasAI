<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

class TemplateSetStatusTool extends AbstractTemplateOrderTool
{
    public function getName(): string
    {
        return 'template/set-status';
    }

    public function getDescription(): string
    {
        return 'Switches a YOOtheme template on or off, the same as the Builder switch. This changes what visitors see. '
            . 'The preview (before/after) lists the templates of the same type in the order YOOtheme checks them, whether an '
            . 'earlier template without conditions hides this one (shadowed_by), and which later ones it takes pages from. '
            . 'if_match is the collection_etag of template/list. Check a page of this type afterwards with site/public-fetch.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->baseSchema() + [
                'status' => ['type' => 'string', 'enum' => ['enabled', 'disabled'], 'description' => 'enabled switches it on; disabled switches it off.'],
            ],
            'required' => ['key', 'status', 'if_match'],
        ];
    }

    protected function change(array $templates, string $key, array $arguments): array
    {
        return TemplateOrder::withStatus($templates, $key, ($arguments['status'] ?? '') === 'enabled');
    }
}
