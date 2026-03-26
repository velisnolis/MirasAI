<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Rereplacer\Support;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

class RereplacerService
{
    /** @var array<string, mixed>|null */
    private ?array $capabilitiesCache = null;

    public function __construct(private DatabaseInterface $db)
    {
    }

    public function getCapabilities(): array
    {
        if ($this->capabilitiesCache !== null) {
            return $this->capabilitiesCache;
        }

        $query = $this->db->getQuery(true)
            ->select('manifest_cache')
            ->from($this->db->quoteName('#__extensions'))
            ->where('element = ' . $this->db->quote('com_rereplacer'))
            ->where('type = ' . $this->db->quote('component'))
            ->where('enabled = 1');

        $manifestRaw = (string) $this->db->setQuery($query)->loadResult();

        if ($manifestRaw === '') {
            return $this->capabilitiesCache = [
                'installed' => false,
                'version' => '',
                'is_pro' => false,
                'conditions_installed' => false,
                'features' => [
                    'simple_items' => false,
                    'conditions_read' => false,
                    'attach_condition' => false,
                ],
                'pro_required_for' => [
                    'attaching condition sets to ReReplacer items',
                    'condition-aware create/update through condition_id',
                ],
            ];
        }

        $decoded = json_decode($manifestRaw, true);
        $version = is_array($decoded) ? (string) ($decoded['version'] ?? '') : '';

        $conditionsInstalled = $this->extensionEnabled('com_conditions', 'component');
        $isPro = stripos($version, 'PRO') !== false;

        return $this->capabilitiesCache = [
            'installed' => true,
            'version' => $version,
            'is_pro' => $isPro,
            'conditions_installed' => $conditionsInstalled,
            'features' => [
                'simple_items' => true,
                'conditions_read' => $conditionsInstalled,
                'attach_condition' => $isPro && $conditionsInstalled,
            ],
            'pro_required_for' => [
                'attaching condition sets to ReReplacer items',
                'condition-aware create/update through condition_id',
            ],
        ];
    }

    public function isPro(): bool
    {
        return (bool) $this->getCapabilities()['is_pro'];
    }

    public function buildCapabilityNote(): string
    {
        $cap = $this->getCapabilities();

        if (!$cap['installed']) {
            return 'ReReplacer is not installed.';
        }

        if (!$cap['is_pro']) {
            return $cap['conditions_installed']
                ? 'ReReplacer Free detected. Simple item tools are available. Attaching Conditions to items requires ReReplacer PRO.'
                : 'ReReplacer Free detected. Simple item tools are available. Condition-based targeting also requires the Conditions component and ReReplacer PRO.';
        }

        if (!$cap['conditions_installed']) {
            return 'ReReplacer PRO detected, but the Conditions component is missing. Simple item tools are available, but condition-based targeting is unavailable.';
        }

        return 'ReReplacer PRO + Conditions detected. The full Phase 1 feature set is available, including condition attachment.';
    }

    private function extensionEnabled(string $element, string $type, string $folder = ''): bool
    {
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__extensions'))
            ->where('element = ' . $this->db->quote($element))
            ->where('type = ' . $this->db->quote($type))
            ->where('enabled = 1');

        if ($folder !== '') {
            $query->where('folder = ' . $this->db->quote($folder));
        }

        return (int) $this->db->setQuery($query)->loadResult() > 0;
    }

    public function listItems(array $filters = []): array
    {
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));
        $query = $this->db->getQuery(true)
            ->select(['id', 'name', 'description', 'search', $this->db->quoteName('replace'), 'area', 'params', 'published'])
            ->from($this->db->quoteName('#__rereplacer'))
            ->order('ordering ASC, id ASC');

        $published = (string) ($filters['published'] ?? 'all');

        if ($published !== 'all') {
            $query->where('published = :published_state')
                ->bind(':published_state', $this->publishedLabelToValue($published), ParameterType::INTEGER);
        }

        if (!empty($filters['area'])) {
            $query->where('area = :area')
                ->bind(':area', (string) $filters['area']);
        }

        if (!empty($filters['search_text'])) {
            $needle = '%' . trim((string) $filters['search_text']) . '%';
            $query->where('(name LIKE :needle_name OR search LIKE :needle_search OR `replace` LIKE :needle_replace)')
                ->bind(':needle_name', $needle)
                ->bind(':needle_search', $needle)
                ->bind(':needle_replace', $needle);
        }

        $rows = $this->db->setQuery($query, 0, $limit)->loadAssocList();

        return array_map(fn(array $row): array => $this->summarizeItemRow($row), $rows ?: []);
    }

    public function readItem(int $id): ?array
    {
        $row = $this->getItemRow($id);

        if ($row === null) {
            return null;
        }

        $params = $this->decodeParams((string) $row['params']);
        $reasons = $this->advancedReasons($row, $params);

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'description' => (string) $row['description'],
            'search' => (string) $row['search'],
            'replace' => (string) $row['replace'],
            'area' => (string) $row['area'],
            'published' => (int) $row['published'],
            'flags' => [
                'casesensitive' => ($params['casesensitive'] ?? '0') === '1',
                'word_search' => ($params['word_search'] ?? '0') === '1',
                'strip_p_tags' => ($params['strip_p_tags'] ?? '0') === '1',
                'has_conditions' => ($params['has_conditions'] ?? '0') === '1',
            ],
            'condition' => $this->conditionSummaryFromParams($params),
            'risk_summary' => [
                'is_simple_phase1_item' => $reasons === [],
                'advanced_reasons' => $reasons,
            ],
        ];
    }

    public function createSimpleItem(array $payload, ?array $condition = null): array
    {
        $validated = $this->validateSimplePayload($payload, false, null, $condition !== null);

        if ($validated['errors'] !== []) {
            return [
                'error' => 'Validation failed for rereplacer/create-item-simple.',
                'details' => $validated['errors'],
            ];
        }

        $data = $validated['data'];
        $params = $this->buildSimpleParams($data, $condition);
        $item = (object) [
            'name' => $data['name'],
            'description' => $data['description'],
            'category' => '',
            'color' => null,
            'search' => $data['search'],
            'replace' => $data['replace'],
            'area' => $data['area'],
            'params' => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'published' => $data['published'],
            'ordering' => $this->nextOrdering(),
        ];

        try {
            $this->db->transactionStart();
            $this->db->insertObject('#__rereplacer', $item, 'id');

            if ($condition !== null) {
                $this->syncConditionMap((int) $item->id, (int) $condition['id']);
            }

            $this->db->transactionCommit();
        } catch (\Throwable $e) {
            $this->db->transactionRollback();

            return [
                'error' => 'Failed to create ReReplacer item.',
                'details' => $e->getMessage(),
            ];
        }

        return [
            'created' => true,
            'item_id' => (int) $item->id,
            'draft_state' => $data['published'] === 1 ? 'published' : 'unpublished',
            'applied_defaults' => $validated['defaults'],
            'warnings' => $validated['warnings'],
        ];
    }

    public function updateSimpleItem(int $id, array $payload, ?array $condition = null, bool $conditionWasProvided = false): array
    {
        $row = $this->getItemRow($id);

        if ($row === null) {
            return ['error' => "ReReplacer item {$id} not found."];
        }

        $params = $this->decodeParams((string) $row['params']);
        $advancedReasons = $this->advancedReasons($row, $params);

        if ($advancedReasons !== []) {
            return [
                'error' => 'This item already uses advanced ReReplacer features and cannot be updated with the simple Phase 1 tool.',
                'advanced_reasons' => $advancedReasons,
                'requires_elevation' => true,
            ];
        }

        $validated = $this->validateSimplePayload($payload, true, $row, $condition !== null);

        if ($validated['errors'] !== []) {
            return [
                'error' => 'Validation failed for rereplacer/update-item-simple.',
                'details' => $validated['errors'],
            ];
        }

        $data = $validated['data'];
        $finalCondition = $conditionWasProvided ? $condition : $this->conditionSummaryFromParams($params);
        $mergedParams = $this->buildSimpleParams($data, $finalCondition);

        $item = (object) [
            'id' => $id,
            'name' => $data['name'],
            'description' => $data['description'],
            'search' => $data['search'],
            'replace' => $data['replace'],
            'area' => $data['area'],
            'params' => json_encode($mergedParams, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'published' => $data['published'],
        ];

        try {
            $this->db->transactionStart();
            $this->db->updateObject('#__rereplacer', $item, 'id');

            if ($conditionWasProvided) {
                if ($condition !== null) {
                    $this->syncConditionMap($id, (int) $condition['id']);
                } else {
                    $this->clearConditionMap($id);
                }
            }

            $this->db->transactionCommit();
        } catch (\Throwable $e) {
            $this->db->transactionRollback();

            return [
                'error' => 'Failed to update ReReplacer item.',
                'details' => $e->getMessage(),
            ];
        }

        return [
            'updated' => true,
            'item_id' => $id,
            'changes' => array_keys($payload),
            'warnings' => $validated['warnings'],
        ];
    }

    public function publishItem(int $id, string $state): array
    {
        $row = $this->getItemRow($id);

        if ($row === null) {
            return ['error' => "ReReplacer item {$id} not found."];
        }

        if (!in_array($state, ['published', 'unpublished', 'trashed'], true)) {
            return ['error' => "Unsupported state '{$state}'."];
        }

        $newState = $this->publishedLabelToValue($state);

        $item = (object) [
            'id' => $id,
            'published' => $newState,
        ];

        $this->db->updateObject('#__rereplacer', $item, 'id');

        return [
            'id' => $id,
            'old_state' => $this->publishedValueToLabel((int) $row['published']),
            'new_state' => $this->publishedValueToLabel($newState),
        ];
    }

    public function attachCondition(int $itemId, array $condition): array
    {
        $row = $this->getItemRow($itemId);

        if ($row === null) {
            return ['error' => "ReReplacer item {$itemId} not found."];
        }

        $params = $this->buildSimpleParams($this->simpleDataFromRow($row), $condition);
        $item = (object) [
            'id' => $itemId,
            'params' => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        try {
            $this->db->transactionStart();
            $this->db->updateObject('#__rereplacer', $item, 'id');
            $this->syncConditionMap($itemId, (int) $condition['id']);
            $this->db->transactionCommit();
        } catch (\Throwable $e) {
            $this->db->transactionRollback();

            return [
                'error' => 'Failed to attach Conditions set to ReReplacer item.',
                'details' => $e->getMessage(),
            ];
        }

        return [
            'attached' => true,
            'item_id' => $itemId,
            'condition_id' => (int) $condition['id'],
            'condition_name' => (string) $condition['name'],
        ];
    }

    public function previewMatchScope(array $payload, ?array $existing = null): array
    {
        $reasons = [];
        $recommendedConditionTypes = [];
        $requiresElevation = false;

        if (!empty($payload['regex']) || !empty($payload['treat_as_php']) || !empty($payload['use_xml'])) {
            $requiresElevation = true;
            $reasons[] = 'Advanced replacement features were requested.';
        }

        $area = (string) ($payload['area'] ?? ($existing['area'] ?? 'body'));

        if (in_array($area, ['head', 'everywhere'], true)) {
            $requiresElevation = true;
            $reasons[] = "Area '{$area}' is outside the Phase 1 safe subset.";
        }

        $search = trim((string) ($payload['search'] ?? ($existing['search'] ?? '')));
        $replace = (string) ($payload['replace'] ?? ($existing['replace'] ?? ''));
        $hasCondition = !empty($payload['condition_id']) || !empty($payload['has_condition']);
        $wordSearch = !empty($payload['word_search']);

        if ($search !== '' && strlen($search) < 6 && strpos($search, ' ') === false && !$wordSearch && !$hasCondition) {
            $reasons[] = 'Short single-word searches are broad unless narrowed by word search or a condition.';
        }

        if ($area === 'body' && !$hasCondition) {
            $reasons[] = 'Body-wide replacements without a condition can affect more pages than intended.';
            $recommendedConditionTypes = ['language', 'menu-item', 'url', 'component', 'template'];
        }

        if (stripos($replace, '<script') !== false || stripos($replace, '<?php') !== false) {
            $requiresElevation = true;
            $reasons[] = 'Script or PHP output is not allowed in the simple tool set.';
        }

        $riskLevel = 'low';

        if ($requiresElevation || count($reasons) >= 3) {
            $riskLevel = 'high';
        } elseif ($reasons !== []) {
            $riskLevel = 'medium';
        }

        return [
            'risk_level' => $riskLevel,
            'scope_summary' => sprintf(
                'Replacement in %s%s.',
                $area,
                $hasCondition ? ' with a linked condition set' : ' without a linked condition set'
            ),
            'reasons' => $reasons,
            'recommended_condition_types' => $recommendedConditionTypes,
            'requires_elevation' => $requiresElevation,
        ];
    }

    public function getItemRow(int $id): ?array
    {
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__rereplacer'))
            ->where('id = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $row = $this->db->setQuery($query)->loadAssoc();

        return $row ?: null;
    }

    /**
     * @return array{data: array<string, mixed>, errors: list<string>, warnings: list<string>, defaults: array<string, mixed>}
     */
    public function validateSimplePayload(array $payload, bool $partial = false, ?array $existing = null, bool $hasCondition = false): array
    {
        $errors = [];
        $warnings = [];
        $defaults = [];
        $existingData = $existing !== null ? $this->simpleDataFromRow($existing) : [];

        $data = [
            'name' => trim((string) ($payload['name'] ?? ($existing['name'] ?? ''))),
            'search' => trim((string) ($payload['search'] ?? ($existing['search'] ?? ''))),
            'replace' => (string) ($payload['replace'] ?? ($existing['replace'] ?? '')),
            'area' => (string) ($payload['area'] ?? ($existing['area'] ?? 'body')),
            'description' => trim((string) ($payload['description'] ?? ($existing['description'] ?? ''))),
            'published' => array_key_exists('published', $payload)
                ? ((bool) $payload['published'] ? 1 : 0)
                : (int) ($existing['published'] ?? 0),
            'casesensitive' => array_key_exists('casesensitive', $payload)
                ? !empty($payload['casesensitive'])
                : (bool) ($existingData['casesensitive'] ?? false),
            'word_search' => array_key_exists('word_search', $payload)
                ? !empty($payload['word_search'])
                : (bool) ($existingData['word_search'] ?? false),
            'strip_p_tags' => array_key_exists('strip_p_tags', $payload)
                ? !empty($payload['strip_p_tags'])
                : (bool) ($existingData['strip_p_tags'] ?? false),
        ];

        if (!$partial && !array_key_exists('published', $payload)) {
            $defaults['published'] = false;
        }

        if (!$partial && !array_key_exists('area', $payload)) {
            $defaults['area'] = 'body';
        }

        foreach (['regex', 'treat_as_php', 'use_xml', 'enable_in_admin', 'enable_in_edit_forms'] as $blockedKey) {
            if (!empty($payload[$blockedKey])) {
                $errors[] = "Field '{$blockedKey}' is outside the Phase 1 safe subset.";
            }
        }

        if ($data['name'] === '') {
            $errors[] = 'Field \'name\' is required.';
        }

        if ($data['search'] === '') {
            $errors[] = 'Field \'search\' is required.';
        }

        if ($data['replace'] === '') {
            $errors[] = 'Field \'replace\' is required.';
        }

        if (!in_array($data['area'], ['articles', 'body'], true)) {
            $errors[] = 'Only areas \'articles\' and \'body\' are allowed in Phase 1.';
        }

        if (
            $data['search'] !== ''
            && strlen($data['search']) < 6
            && strpos($data['search'], ' ') === false
            && !$data['word_search']
            && !$hasCondition
        ) {
            $errors[] = 'Short single-word searches require word_search=true or a linked condition to avoid broad matches.';
        }

        if (
            stripos($data['replace'], '<script') !== false
            || stripos($data['replace'], '<?php') !== false
            || preg_match('/<[^>]+\\son[a-z]+\\s*=/i', $data['replace']) === 1
            || preg_match('/javascript\\s*:/i', $data['replace']) === 1
        ) {
            $errors[] = 'Script or PHP output is not allowed in the simple tool set.';
        }

        if (stripos($data['replace'], '<iframe') !== false) {
            $warnings[] = 'Iframe output is allowed by the schema but often indicates a case better handled by a more targeted replacement strategy.';
        }

        return [
            'data' => $data,
            'errors' => $errors,
            'warnings' => $warnings,
            'defaults' => $defaults,
        ];
    }

    public function advancedReasons(array $row, array $params): array
    {
        $reasons = [];

        if (($params['regex'] ?? '0') === '1') {
            $reasons[] = 'regex enabled';
        }

        if (($params['treat_as_php'] ?? '0') === '1') {
            $reasons[] = 'PHP replacement enabled';
        }

        if (($params['use_xml'] ?? '0') === '1') {
            $reasons[] = 'XML replacement enabled';
        }

        if (($params['enable_in_admin'] ?? '0') !== '0') {
            $reasons[] = 'admin scope enabled';
        }

        if (($params['enable_in_edit_forms'] ?? '0') === '1') {
            $reasons[] = 'edit forms scope enabled';
        }

        if (in_array((string) $row['area'], ['head', 'everywhere'], true)) {
            $reasons[] = 'advanced area ' . $row['area'];
        }

        if (($params['between_start'] ?? '') !== '' || ($params['between_end'] ?? '') !== '') {
            $reasons[] = 'between markers configured';
        }

        return $reasons;
    }

    public function summarizeItemRow(array $row): array
    {
        $params = $this->decodeParams((string) $row['params']);
        $condition = $this->conditionSummaryFromParams($params);

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'published' => $this->publishedValueToLabel((int) $row['published']),
            'area' => (string) $row['area'],
            'has_conditions' => $condition !== null,
            'condition_id' => $condition['id'] ?? null,
            'condition_name' => $condition['name'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSimpleParams(array $data, ?array $condition): array
    {
        $base = [
            'xml' => '',
            'use_xml' => '0',
            'regex' => '0',
            'treat_as_list' => '0',
            'word_search' => !empty($data['word_search']) ? '1' : '0',
            's_modifier' => '1',
            'casesensitive' => !empty($data['casesensitive']) ? '1' : '0',
            'max_replacements' => '',
            'thorough' => '0',
            'strip_p_tags' => !empty($data['strip_p_tags']) ? '1' : '0',
            'treat_as_php' => '0',
            'enable_in_title' => '1',
            'enable_in_content' => '1',
            'enable_in_author' => '1',
            'enable_in_category' => '1',
            'enable_in_feeds' => '0',
            'enable_in_admin' => '0',
            'enable_in_edit_forms' => '0',
            'between_start' => '',
            'between_end' => '',
            'enable_tags' => '1',
            'limit_tagselect' => '0',
            'tagselect' => '*[title,alt]_meta[content]',
            'has_conditions' => $condition !== null ? '1' : '0',
            'condition_id' => $condition !== null ? (string) $condition['id'] : '',
            'condition_alias' => $condition !== null ? (string) $condition['alias'] : '',
            'condition_name' => $condition !== null ? (string) $condition['name'] : '',
            'other_doreplace' => '0',
            'other_replace' => '',
        ];

        return $base;
    }

    /**
     * @return array{name: string, search: string, replace: string, area: string, description: string, published: int, casesensitive: bool, word_search: bool, strip_p_tags: bool}
     */
    private function simpleDataFromRow(array $row): array
    {
        $params = $this->decodeParams((string) ($row['params'] ?? ''));

        return [
            'name' => (string) ($row['name'] ?? ''),
            'search' => (string) ($row['search'] ?? ''),
            'replace' => (string) ($row['replace'] ?? ''),
            'area' => (string) ($row['area'] ?? 'body'),
            'description' => (string) ($row['description'] ?? ''),
            'published' => (int) ($row['published'] ?? 0),
            'casesensitive' => ($params['casesensitive'] ?? '0') === '1',
            'word_search' => ($params['word_search'] ?? '0') === '1',
            'strip_p_tags' => ($params['strip_p_tags'] ?? '0') === '1',
        ];
    }

    public function conditionSummaryFromParams(array $params): ?array
    {
        if (($params['has_conditions'] ?? '0') !== '1' || empty($params['condition_id'])) {
            return null;
        }

        return [
            'id' => (int) $params['condition_id'],
            'alias' => (string) ($params['condition_alias'] ?? ''),
            'name' => (string) ($params['condition_name'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeParams(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function nextOrdering(): int
    {
        $query = $this->db->getQuery(true)
            ->select('COALESCE(MAX(ordering), 0)')
            ->from($this->db->quoteName('#__rereplacer'));

        return (int) $this->db->setQuery($query)->loadResult() + 1;
    }

    private function syncConditionMap(int $itemId, int $conditionId): void
    {
        $this->clearConditionMap($itemId);

        $map = (object) [
            'condition_id' => $conditionId,
            'extension' => 'com_rereplacer',
            'item_id' => $itemId,
            'table' => 'rereplacer',
            'name_column' => 'name',
        ];

        $this->db->insertObject('#__conditions_map', $map);
    }

    private function clearConditionMap(int $itemId): void
    {
        $query = $this->db->getQuery(true)
            ->delete($this->db->quoteName('#__conditions_map'))
            ->where('extension = ' . $this->db->quote('com_rereplacer'))
            ->where('item_id = :item_id')
            ->bind(':item_id', $itemId, ParameterType::INTEGER);

        $this->db->setQuery($query)->execute();
    }

    private function publishedLabelToValue(string $state): int
    {
        return match ($state) {
            'published' => 1,
            'unpublished' => 0,
            'trashed' => -2,
            default => 0,
        };
    }

    private function publishedValueToLabel(int $state): string
    {
        return match ($state) {
            1 => 'published',
            -2 => 'trashed',
            default => 'unpublished',
        };
    }
}
