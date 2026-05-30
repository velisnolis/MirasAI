<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Rereplacer\Support;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

class ConditionsService
{
    public function __construct(private DatabaseInterface $db)
    {
    }

    public function exists(int $id): bool
    {
        return $this->getConditionHeader($id) !== null;
    }

    public function getConditionHeader(int $id): ?array
    {
        $query = $this->db->getQuery(true)
            ->select([
                'c.id',
                'c.name',
                'c.alias',
                'c.published',
                'c.match_all',
            ])
            ->from($this->db->quoteName('#__conditions', 'c'))
            ->where('c.id = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $row = $this->db->setQuery($query)->loadAssoc();

        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'alias' => (string) $row['alias'],
            'published' => (int) $row['published'],
            'match_all' => (bool) $row['match_all'],
        ];
    }

    public function listConditions(?string $searchText = null, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        $query = $this->db->getQuery(true)
            ->select([
                'c.id',
                'c.name',
                'c.alias',
                'c.published',
                'c.match_all',
                'COUNT(DISTINCT r.id) AS rule_count',
                'GROUP_CONCAT(DISTINCT r.type ORDER BY r.type SEPARATOR \',\') AS rule_types',
            ])
            ->from($this->db->quoteName('#__conditions', 'c'))
            ->join('LEFT', $this->db->quoteName('#__conditions_groups', 'g') . ' ON g.condition_id = c.id')
            ->join('LEFT', $this->db->quoteName('#__conditions_rules', 'r') . ' ON r.group_id = g.id')
            ->group('c.id')
            ->order('c.id DESC');

        if ($searchText !== null && trim($searchText) !== '') {
            $needle = '%' . trim($searchText) . '%';
            $query->where('(c.name LIKE :needle OR c.alias LIKE :needle)')
                ->bind(':needle', $needle);
        }

        $rows = $this->db->setQuery($query, 0, $limit)->loadAssocList();
        $items = [];

        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'alias' => (string) $row['alias'],
                'published' => (int) $row['published'],
                'rule_count' => (int) $row['rule_count'],
                'summary' => $this->buildRuleSummary((string) ($row['rule_types'] ?? '')),
            ];
        }

        return $items;
    }

    public function readCondition(int $id): ?array
    {
        $header = $this->getConditionHeader($id);

        if ($header === null) {
            return null;
        }

        $groupsQuery = $this->db->getQuery(true)
            ->select(['id', 'match_all', 'ordering'])
            ->from($this->db->quoteName('#__conditions_groups'))
            ->where('condition_id = :condition_id')
            ->bind(':condition_id', $id, ParameterType::INTEGER)
            ->order('ordering ASC, id ASC');

        $groupsRows = $this->db->setQuery($groupsQuery)->loadAssocList();
        $groups = [];

        foreach ($groupsRows as $row) {
            $groups[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'match_all' => (bool) $row['match_all'],
                'ordering' => (int) $row['ordering'],
                'rules' => [],
            ];
        }

        if ($groups !== []) {
            $ruleQuery = $this->db->getQuery(true)
                ->select(['id', 'group_id', 'type', 'exclude', 'params', 'ordering'])
                ->from($this->db->quoteName('#__conditions_rules'))
                ->where('group_id IN (' . implode(',', array_keys($groups)) . ')')
                ->order('ordering ASC, id ASC');

            $ruleRows = $this->db->setQuery($ruleQuery)->loadAssocList();

            foreach ($ruleRows as $row) {
                $groupId = (int) $row['group_id'];
                $rule = [
                    'id' => (int) $row['id'],
                    'group_id' => $groupId,
                    'type' => (string) $row['type'],
                    'exclude' => (bool) $row['exclude'],
                    'params' => $this->decodeJsonObject((string) $row['params']),
                    'ordering' => (int) $row['ordering'],
                ];

                if (isset($groups[$groupId])) {
                    $groups[$groupId]['rules'][] = $rule;
                }
            }
        }

        $usageQuery = $this->db->getQuery(true)
            ->select([
                'condition_id',
                'extension',
                'item_id',
                $this->db->quoteName('table'),
                'name_column',
            ])
            ->from($this->db->quoteName('#__conditions_map'))
            ->where('condition_id = :condition_id')
            ->bind(':condition_id', $id, ParameterType::INTEGER)
            ->order('extension ASC, item_id ASC');

        $usageRows = $this->db->setQuery($usageQuery)->loadAssocList();

        return $header + [
            'groups' => array_values($groups),
            'rules' => array_values(array_merge(...array_map(
                static fn(array $group): array => $group['rules'],
                array_values($groups),
            ))),
            'usage' => array_map(
                static fn(array $row): array => [
                    'extension' => (string) $row['extension'],
                    'item_id' => (int) $row['item_id'],
                    'table' => (string) $row['table'],
                    'name_column' => (string) $row['name_column'],
                ],
                $usageRows ?: [],
            ),
        ];
    }

    private function buildRuleSummary(string $ruleTypes): string
    {
        if ($ruleTypes === '') {
            return 'No rules';
        }

        $types = array_values(array_filter(array_map('trim', explode(',', $ruleTypes))));
        $types = array_slice($types, 0, 3);
        $labels = array_map(
            static fn(string $type): string => str_replace('__', '.', $type),
            $types,
        );

        return implode(', ', $labels);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(string $value): array
    {
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
