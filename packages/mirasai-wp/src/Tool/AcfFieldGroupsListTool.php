<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class AcfFieldGroupsListTool extends AbstractTool
{
    private AcfHelper $acf;

    public function __construct(?AcfHelper $acf = null)
    {
        $this->acf = $acf ?? new AcfHelper();
    }

    public function getName(): string
    {
        return 'acf/field-groups/list';
    }

    public function getDescription(): string
    {
        return 'Lists ACF field groups with key, title, active state, location rules, REST visibility, and display metadata.';
    }

    /**
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'active_only' => [
                    'type' => 'boolean',
                    'description' => 'Only return active field groups. Defaults to false.',
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
                'field_groups' => [],
                'count' => 0,
            ];
        }

        $activeOnly = !empty($arguments['active_only']);
        $groups = [];

        foreach ($this->acf->fieldGroups() as $group) {
            $summary = $this->acf->summarizeGroup($group);
            if ($activeOnly && $summary['active'] === false) {
                continue;
            }

            $groups[] = $summary;
        }

        return [
            'ok' => true,
            'available' => true,
            'count' => count($groups),
            'field_groups' => $groups,
        ];
    }
}
