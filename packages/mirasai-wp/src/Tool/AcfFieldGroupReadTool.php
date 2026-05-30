<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class AcfFieldGroupReadTool extends AbstractTool
{
    private AcfHelper $acf;

    public function __construct(?AcfHelper $acf = null)
    {
        $this->acf = $acf ?? new AcfHelper();
    }

    public function getName(): string
    {
        return 'acf/field-group/read';
    }

    public function getDescription(): string
    {
        return 'Reads one ACF field group and returns its fields, nested sub-fields, choices, required flags, return formats, and location rules.';
    }

    /**
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'key' => [
                    'type' => 'string',
                    'description' => 'ACF field group key, for example group_abc123.',
                ],
                'id' => [
                    'type' => 'integer',
                    'description' => 'ACF field group post ID.',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        if (!$this->acf->isAvailable()) {
            return [
                'ok' => true,
                'available' => false,
                'field_group' => null,
                'fields' => [],
            ];
        }

        $selector = isset($arguments['key']) && is_string($arguments['key']) && $arguments['key'] !== ''
            ? $arguments['key']
            : (isset($arguments['id']) ? (int) $arguments['id'] : null);

        if ($selector === null || $selector === 0) {
            return [
                'error' => 'Field group key or id is required.',
                'code' => 'invalid_arguments',
            ];
        }

        $group = $this->acf->fieldGroup($selector);
        if ($group === null) {
            return [
                'error' => 'ACF field group not found.',
                'code' => 'not_found',
                'selector' => $selector,
            ];
        }

        $fields = array_map(
            fn(array $field): array => $this->acf->summarizeField($field, false),
            $this->acf->fields($group)
        );

        return [
            'ok' => true,
            'available' => true,
            'field_group' => $this->acf->summarizeGroup($group),
            'fields' => $fields,
            'field_count' => count($fields),
        ];
    }
}
