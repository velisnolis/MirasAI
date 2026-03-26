<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Rereplacer\Tool;

class RereplacerPreviewMatchScopeTool extends AbstractRereplacerTool
{
    public function getName(): string
    {
        return 'rereplacer/preview-match-scope';
    }

    public function getDescription(): string
    {
        return 'Preview the likely scope and risk of a proposed simple ReReplacer change before writing. Use this when the request could be too broad, especially with short search terms or body-wide replacements without a condition.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'item_id' => [
                    'type' => 'integer',
                    'description' => 'Optional existing item ID to inspect.',
                ],
                'search' => ['type' => 'string'],
                'replace' => ['type' => 'string'],
                'area' => [
                    'type' => 'string',
                    'enum' => ['articles', 'body', 'head', 'everywhere'],
                ],
                'condition_id' => ['type' => 'integer'],
                'word_search' => ['type' => 'boolean'],
                'regex' => ['type' => 'boolean'],
                'treat_as_php' => ['type' => 'boolean'],
                'use_xml' => ['type' => 'boolean'],
            ],
        ];
    }

    public function handle(array $arguments): array
    {
        $existing = null;

        if (!empty($arguments['item_id'])) {
            $row = $this->rereplacer->getItemRow((int) $arguments['item_id']);

            if ($row === null) {
                return ['error' => "ReReplacer item {$arguments['item_id']} not found."];
            }

            $existing = $row;
        }

        $arguments['has_condition'] = !empty($arguments['condition_id']);

        return $this->rereplacer->previewMatchScope($arguments, $existing);
    }
}
