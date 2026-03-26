<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Rereplacer\Tool;

class RereplacerAttachConditionTool extends AbstractRereplacerTool
{
    public function getName(): string
    {
        return 'rereplacer/attach-condition';
    }

    public function getDescription(): string
    {
        return 'Attach an existing Regular Labs Conditions set to a ReReplacer item. Prefer this over broadening the replacement area when you need a surgical page, language, menu, or URL scope.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'item_id' => [
                    'type' => 'integer',
                    'description' => 'ReReplacer item ID.',
                ],
                'condition_id' => [
                    'type' => 'integer',
                    'description' => 'Existing Conditions set ID.',
                ],
            ],
            'required' => ['item_id', 'condition_id'],
        ];
    }

    public function handle(array $arguments): array
    {
        if (!$this->rereplacer->isPro()) {
            return [
                'error' => 'Attaching Conditions to ReReplacer items requires ReReplacer PRO.',
                'capabilities' => $this->rereplacer->getCapabilities(),
            ];
        }

        $condition = $this->conditions->getConditionHeader((int) ($arguments['condition_id'] ?? 0));

        if ($condition === null) {
            return ['error' => "Condition set {$arguments['condition_id']} not found."];
        }

        return $this->rereplacer->attachCondition(
            (int) ($arguments['item_id'] ?? 0),
            $condition,
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
