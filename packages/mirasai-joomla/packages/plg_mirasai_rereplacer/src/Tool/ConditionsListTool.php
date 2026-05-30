<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Rereplacer\Tool;

class ConditionsListTool extends AbstractRereplacerTool
{
    public function getName(): string
    {
        return 'conditions/list';
    }

    public function getDescription(): string
    {
        return 'List reusable Regular Labs Conditions sets. Use this before creating or attaching ReReplacer items so you can reuse an existing language, menu, URL, component, or template condition instead of broadening the replacement scope.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'search_text' => [
                    'type' => 'string',
                    'description' => 'Optional text filter for condition name or alias.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 200,
                    'description' => 'Maximum number of condition sets to return. Default 50.',
                ],
            ],
        ];
    }

    public function handle(array $arguments): array
    {
        $items = $this->conditions->listConditions(
            isset($arguments['search_text']) ? (string) $arguments['search_text'] : null,
            (int) ($arguments['limit'] ?? 50),
        );

        return [
            'count' => count($items),
            'conditions' => $items,
        ];
    }
}
