<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Rereplacer\Tool;

class RereplacerReadItemTool extends AbstractRereplacerTool
{
    public function getName(): string
    {
        return 'rereplacer/read-item';
    }

    public function getDescription(): string
    {
        return 'Read a single ReReplacer item with its safe flags, condition link, and a risk summary indicating whether it still fits the Phase 1 simple subset.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                    'description' => 'ReReplacer item ID.',
                ],
            ],
            'required' => ['id'],
        ];
    }

    public function handle(array $arguments): array
    {
        $id = (int) ($arguments['id'] ?? 0);
        $item = $this->rereplacer->readItem($id);

        if ($item === null) {
            return ['error' => "ReReplacer item {$id} not found."];
        }

        return $item;
    }
}
