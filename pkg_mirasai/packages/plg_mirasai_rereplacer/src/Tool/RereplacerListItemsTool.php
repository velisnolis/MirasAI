<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Rereplacer\Tool;

class RereplacerListItemsTool extends AbstractRereplacerTool
{
    public function getName(): string
    {
        return 'rereplacer/list-items';
    }

    public function getDescription(): string
    {
        return 'List existing ReReplacer items in an agent-friendly summary. Use this first to inspect current replacements, avoid duplicate rules, and understand whether a condition set is already attached.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'published' => [
                    'type' => 'string',
                    'enum' => ['published', 'unpublished', 'trashed', 'all'],
                    'description' => 'Optional publication-state filter. Default all.',
                ],
                'area' => [
                    'type' => 'string',
                    'enum' => ['articles', 'body', 'head', 'everywhere'],
                    'description' => 'Optional area filter.',
                ],
                'search_text' => [
                    'type' => 'string',
                    'description' => 'Optional text filter for item name, search, or replacement.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 200,
                    'description' => 'Maximum number of items to return. Default 50.',
                ],
            ],
        ];
    }

    public function handle(array $arguments): array
    {
        $items = $this->rereplacer->listItems($arguments);

        return [
            'count' => count($items),
            'items' => $items,
        ];
    }
}
