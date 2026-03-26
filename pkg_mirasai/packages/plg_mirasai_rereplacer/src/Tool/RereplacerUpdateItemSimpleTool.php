<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Rereplacer\Tool;

class RereplacerUpdateItemSimpleTool extends AbstractRereplacerTool
{
    public function getName(): string
    {
        return 'rereplacer/update-item-simple';
    }

    public function getDescription(): string
    {
        return 'Update an existing ReReplacer item if it still belongs to the Phase 1 simple subset. This tool refuses to edit items that already use advanced features such as regex, PHP, XML, head scope, or other elevated behaviors.';
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
                'name' => ['type' => 'string'],
                'search' => ['type' => 'string'],
                'replace' => ['type' => 'string'],
                'area' => [
                    'type' => 'string',
                    'enum' => ['articles', 'body'],
                ],
                'description' => ['type' => 'string'],
                'published' => ['type' => 'boolean'],
                'casesensitive' => ['type' => 'boolean'],
                'word_search' => ['type' => 'boolean'],
                'strip_p_tags' => ['type' => 'boolean'],
                'condition_id' => [
                    'type' => 'integer',
                    'description' => 'Optional replacement Conditions set. If omitted, the current linked condition is kept.',
                ],
            ],
            'required' => ['id'],
        ];
    }

    public function handle(array $arguments): array
    {
        $condition = null;
        $conditionWasProvided = array_key_exists('condition_id', $arguments);

        if ($conditionWasProvided && !empty($arguments['condition_id'])) {
            if (!$this->rereplacer->isPro()) {
                return [
                    'error' => 'Attaching Conditions to ReReplacer items requires ReReplacer PRO.',
                    'capabilities' => $this->rereplacer->getCapabilities(),
                ];
            }

            $condition = $this->conditions->getConditionHeader((int) $arguments['condition_id']);

            if ($condition === null) {
                return ['error' => "Condition set {$arguments['condition_id']} not found."];
            }
        }

        return $this->rereplacer->updateSimpleItem(
            (int) ($arguments['id'] ?? 0),
            $arguments,
            $condition,
            $conditionWasProvided,
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
