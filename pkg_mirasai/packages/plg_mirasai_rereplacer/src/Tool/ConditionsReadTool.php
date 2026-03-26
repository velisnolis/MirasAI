<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Rereplacer\Tool;

class ConditionsReadTool extends AbstractRereplacerTool
{
    public function getName(): string
    {
        return 'conditions/read';
    }

    public function getDescription(): string
    {
        return 'Read one Regular Labs Conditions set, including its groups, rules, and known usage. Use this to confirm whether an existing condition already provides the surgical scope you need before creating a new replacement.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                    'description' => 'Condition set ID.',
                ],
            ],
            'required' => ['id'],
        ];
    }

    public function handle(array $arguments): array
    {
        $id = (int) ($arguments['id'] ?? 0);
        $condition = $this->conditions->readCondition($id);

        if ($condition === null) {
            return ['error' => "Condition set {$id} not found."];
        }

        return $condition;
    }
}
