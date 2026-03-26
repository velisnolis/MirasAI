<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Rereplacer\Tool;

class RereplacerCreateItemSimpleTool extends AbstractRereplacerTool
{
    public function getName(): string
    {
        return 'rereplacer/create-item-simple';
    }

    public function getDescription(): string
    {
        return 'Create a safe Phase 1 ReReplacer item using plain search and replacement text or limited HTML. This tool only supports the simple subset: no regex, no PHP, no XML, and only the body or articles areas. By default the item is created unpublished. Linking a Conditions set requires ReReplacer PRO.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Human-readable item name.',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Literal text to search for.',
                ],
                'replace' => [
                    'type' => 'string',
                    'description' => 'Replacement output. Limited HTML is allowed, but script and PHP output are blocked.',
                ],
                'area' => [
                    'type' => 'string',
                    'enum' => ['articles', 'body'],
                    'description' => 'Safe Phase 1 output area. Default body.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Optional item description.',
                ],
                'published' => [
                    'type' => 'boolean',
                    'description' => 'Whether to publish immediately. Default false.',
                ],
                'casesensitive' => [
                    'type' => 'boolean',
                    'description' => 'Whether the literal search should be case-sensitive.',
                ],
                'word_search' => [
                    'type' => 'boolean',
                    'description' => 'Prefer true for short or common single-word searches.',
                ],
                'strip_p_tags' => [
                    'type' => 'boolean',
                    'description' => 'Whether ReReplacer should strip surrounding paragraph tags.',
                ],
                'condition_id' => [
                    'type' => 'integer',
                    'description' => 'Optional existing Conditions set to attach.',
                ],
            ],
            'required' => ['name', 'search', 'replace'],
        ];
    }

    public function handle(array $arguments): array
    {
        $condition = null;

        if (!empty($arguments['condition_id'])) {
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

        return $this->rereplacer->createSimpleItem($arguments, $condition);
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
