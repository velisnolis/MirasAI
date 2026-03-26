<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Rereplacer\Tool;

class RereplacerPublishItemTool extends AbstractRereplacerTool
{
    public function getName(): string
    {
        return 'rereplacer/publish-item';
    }

    public function getDescription(): string
    {
        return 'Publish, unpublish, or trash a ReReplacer item. Use this after inspecting or creating an item, not as the first step.';
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
                'state' => [
                    'type' => 'string',
                    'enum' => ['published', 'unpublished', 'trashed'],
                    'description' => 'Target publication state.',
                ],
            ],
            'required' => ['id', 'state'],
        ];
    }

    public function handle(array $arguments): array
    {
        return $this->rereplacer->publishItem(
            (int) ($arguments['id'] ?? 0),
            (string) ($arguments['state'] ?? ''),
        );
    }

    public function getPermissions(): array
    {
        return [
            'readonly' => false,
            'destructive' => true,
            'requires_elevation' => false,
            'idempotent' => false,
        ];
    }
}
