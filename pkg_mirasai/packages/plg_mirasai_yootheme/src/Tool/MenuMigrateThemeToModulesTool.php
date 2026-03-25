<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Joomla\Database\ParameterType;
use Mirasai\Library\Tool\AbstractTool;
use Mirasai\Library\Tool\YooThemeHelper;

class MenuMigrateThemeToModulesTool extends AbstractTool
{
    /** @var list<string> */
    private const SUPPORTED_POSITIONS = ['navbar', 'dialog-mobile'];

    private YooThemeHelper $yooHelper;

    public function __construct()
    {
        parent::__construct();
        $this->yooHelper = new YooThemeHelper($this->db);
    }

    public function getName(): string
    {
        return 'menu/migrate-theme-to-modules';
    }

    public function getDescription(): string
    {
        return 'Migrates YOOtheme-managed menus to per-language Joomla mod_menu modules in navbar and dialog-mobile positions. Supports dry runs, safe reuse of compatible modules, and clearing theme menu assignments once modules are resolved.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'positions' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Theme menu positions to migrate. Supported in v1: ["navbar", "dialog-mobile"]. Defaults to both.',
                ],
                'languages' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Published site languages to configure (e.g. ["ca-ES", "es-ES", "en-GB"]).',
                ],
                'template_style_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: specific YOOtheme template style id. Defaults to the active site style.',
                ],
                'menutype_map' => [
                    'type' => 'object',
                    'description' => 'Optional explicit map of Joomla menutypes. Supports either language => menutype or position => { language => menutype }.',
                    'additionalProperties' => [
                        'oneOf' => [
                            ['type' => 'string'],
                            [
                                'type' => 'object',
                                'additionalProperties' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
                'dry_run' => [
                    'type' => 'boolean',
                    'description' => 'If true, validate and return the plan without writing modules or theme config.',
                ],
            ],
            'required' => ['languages'],
        ];
    }

    public function getPermissions(): array
    {
        return [
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ];
    }

    public function handle(array $arguments): array
    {
        $positions = $this->normalisePositions($arguments['positions'] ?? self::SUPPORTED_POSITIONS);
        $languages = $this->normaliseLanguages($arguments['languages'] ?? []);
        $menutypeMap = is_array($arguments['menutype_map'] ?? null) ? $arguments['menutype_map'] : [];
        $dryRun = !empty($arguments['dry_run']);

        if ($positions === []) {
            return ['error' => 'positions must contain at least one supported position.'];
        }

        if ($languages === []) {
            return ['error' => 'languages is required and must contain at least one published language code.'];
        }

        $unsupportedPositions = array_values(array_diff($positions, self::SUPPORTED_POSITIONS));

        if ($unsupportedPositions !== []) {
            return ['error' => 'Unsupported positions for v1: ' . implode(', ', $unsupportedPositions)];
        }

        $invalidLanguages = [];

        foreach ($languages as $language) {
            if (!$this->languageExists($language)) {
                $invalidLanguages[] = $language;
            }
        }

        if ($invalidLanguages !== []) {
            return ['error' => 'Languages not published: ' . implode(', ', $invalidLanguages)];
        }

        $styleId = isset($arguments['template_style_id'])
            ? (int) $arguments['template_style_id']
            : $this->yooHelper->resolveActiveStyleId();

        if (!$styleId) {
            return ['error' => 'No active YOOtheme template style found. Pass template_style_id explicitly if needed.'];
        }

        if (!$this->yooHelper->isYoothemeSiteStyle($styleId)) {
            return ['error' => "template_style_id {$styleId} is not a site-side YOOtheme style."];
        }

        $themeConfig = $this->yooHelper->loadStyleConfig($styleId) ?? [];
        $themeAssignments = $this->getThemeAssignments($themeConfig, $positions);

        $sourceLanguage = $this->detectLikelySourceLanguage();
        $languageTitles = $this->getPublishedLanguageTitleMap();
        $resolvedMenutypes = [];
        $conflicts = [];

        foreach ($positions as $position) {
            foreach ($languages as $language) {
                $menutype = $this->resolveMenutypeForSlot(
                    $position,
                    $language,
                    $sourceLanguage,
                    $themeAssignments[$position] ?? null,
                    $menutypeMap,
                );

                if ($menutype === null) {
                    $conflicts[] = [
                        'type' => 'menutype_unresolved',
                        'position' => $position,
                        'language' => $language,
                        'reason' => 'Could not resolve a unique Joomla menutype for this position and language. Pass menutype_map explicitly.',
                    ];
                    continue;
                }

                $resolvedMenutypes[$position][$language] = $menutype;
            }
        }

        $modulePlan = [];

        foreach ($positions as $position) {
            foreach ($languages as $language) {
                if (!isset($resolvedMenutypes[$position][$language])) {
                    continue;
                }

                $slotPlan = $this->planModuleSlot($position, $language, $resolvedMenutypes[$position][$language]);

                if (isset($slotPlan['conflict'])) {
                    $conflicts[] = $slotPlan['conflict'];
                    continue;
                }

                $modulePlan[] = $slotPlan['result'];
            }
        }

        $themeAssignmentResult = $this->buildThemeAssignmentResult($themeAssignments, $dryRun);

        if ($conflicts !== []) {
            return [
                'error' => 'Conflicts detected. No changes were applied.',
                'template_style_id' => $styleId,
                'positions' => $positions,
                'dry_run' => $dryRun,
                'theme_assignments' => $themeAssignmentResult,
                'modules' => $modulePlan,
                'conflicts' => $conflicts,
                'warnings' => [],
            ];
        }

        if ($dryRun) {
            return [
                'template_style_id' => $styleId,
                'positions' => $positions,
                'dry_run' => true,
                'theme_assignments' => $themeAssignmentResult,
                'modules' => $modulePlan,
                'conflicts' => [],
                'warnings' => [],
            ];
        }

        $executedModules = [];

        foreach ($modulePlan as $item) {
            $action = $item['action'];

            if ($action === 'exists') {
                $executedModules[] = $item;
                continue;
            }

            if ($action === 'would_reuse') {
                $this->markManagedModule((int) $item['module_id'], $item['position'], $item['menutype']);
                $item['action'] = 'reused';
                $executedModules[] = $item;
                continue;
            }

            if ($action === 'would_create') {
                $moduleId = $this->createMenuModule(
                    $item['position'],
                    $item['language'],
                    $item['menutype'],
                    $languageTitles[$item['language']] ?? $item['language'],
                );

                $item['action'] = 'created';
                $item['module_id'] = $moduleId;
                $executedModules[] = $item;
            }
        }

        $this->clearThemeAssignments($styleId, $positions);
        $themeAssignmentResult = $this->buildThemeAssignmentResult($themeAssignments, false);

        return [
            'template_style_id' => $styleId,
            'positions' => $positions,
            'dry_run' => false,
            'theme_assignments' => $themeAssignmentResult,
            'modules' => $executedModules,
            'conflicts' => [],
            'warnings' => [],
        ];
    }

    /**
     * @param mixed $positions
     * @return list<string>
     */
    private function normalisePositions(mixed $positions): array
    {
        if (!is_array($positions)) {
            return [];
        }

        $result = [];

        foreach ($positions as $position) {
            if (!is_string($position)) {
                continue;
            }

            $position = trim($position);

            if ($position === '') {
                continue;
            }

            if (!in_array($position, $result, true)) {
                $result[] = $position;
            }
        }

        return $result;
    }

    /**
     * @param mixed $languages
     * @return list<string>
     */
    private function normaliseLanguages(mixed $languages): array
    {
        if (!is_array($languages)) {
            return [];
        }

        $result = [];

        foreach ($languages as $language) {
            if (!is_string($language)) {
                continue;
            }

            $language = trim($language);

            if ($language === '' || $language === '*') {
                continue;
            }

            if (!in_array($language, $result, true)) {
                $result[] = $language;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $themeConfig
     * @param list<string> $positions
     * @return array<string, string>
     */
    private function getThemeAssignments(array $themeConfig, array $positions): array
    {
        $result = [];

        foreach ($positions as $position) {
            $menu = $themeConfig['menu']['positions'][$position]['menu'] ?? '';
            $result[$position] = is_string($menu) ? trim($menu) : '';
        }

        return $result;
    }

    /**
     * @param array<string, string> $themeAssignments
     * @return array<string, array{current_menu: string, action: string}>
     */
    private function buildThemeAssignmentResult(array $themeAssignments, bool $dryRun): array
    {
        $result = [];

        foreach ($themeAssignments as $position => $menu) {
            $result[$position] = [
                'current_menu' => $menu,
                'action' => $menu === ''
                    ? 'already_cleared'
                    : ($dryRun ? 'would_clear' : 'cleared'),
            ];
        }

        return $result;
    }

    private function resolveMenutypeForSlot(
        string $position,
        string $language,
        string $sourceLanguage,
        ?string $themeAssignedMenu,
        array $menutypeMap,
    ): ?string {
        $explicit = $this->resolveExplicitMenutype($menutypeMap, $position, $language);

        if (is_string($explicit) && trim($explicit) !== '') {
            return trim($explicit);
        }

        $existingSlotMenutype = $this->findUniquePublishedModuleMenutypeForPosition($position, $language);

        if ($existingSlotMenutype !== null) {
            return $existingSlotMenutype;
        }

        if ($language === $sourceLanguage && is_string($themeAssignedMenu) && trim($themeAssignedMenu) !== '') {
            return trim($themeAssignedMenu);
        }

        $homeMenutype = $this->findHomeMenutype($language);

        if ($homeMenutype !== null) {
            return $homeMenutype;
        }

        return $this->findUniquePublishedModuleMenutype($language);
    }

    /**
     * @param array<string, mixed> $menutypeMap
     */
    private function resolveExplicitMenutype(array $menutypeMap, string $position, string $language): ?string
    {
        $positionMap = $menutypeMap[$position] ?? null;

        if (is_array($positionMap)) {
            $positionSpecific = $positionMap[$language] ?? null;

            if (is_string($positionSpecific) && trim($positionSpecific) !== '') {
                return trim($positionSpecific);
            }
        }

        $flat = $menutypeMap[$language] ?? null;

        return is_string($flat) && trim($flat) !== '' ? trim($flat) : null;
    }

    private function findHomeMenutype(string $language): ?string
    {
        $query = $this->db->getQuery(true)
            ->select('DISTINCT ' . $this->db->quoteName('menutype'))
            ->from($this->db->quoteName('#__menu'))
            ->where('client_id = 0')
            ->where('home = 1')
            ->where('published = 1')
            ->where('language = :lang')
            ->bind(':lang', $language);

        $rows = $this->db->setQuery($query)->loadColumn();
        $menutypes = [];

        foreach ($rows as $row) {
            if (is_string($row) && trim($row) !== '') {
                $menutypes[] = trim($row);
            }
        }

        $menutypes = array_values(array_unique($menutypes));

        return count($menutypes) === 1 ? $menutypes[0] : null;
    }

    private function findUniquePublishedModuleMenutype(string $language): ?string
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('params'))
            ->from($this->db->quoteName('#__modules'))
            ->where('module = ' . $this->db->quote('mod_menu'))
            ->where('client_id = 0')
            ->where('published = 1')
            ->where('language = :lang')
            ->bind(':lang', $language);

        $rows = $this->db->setQuery($query)->loadColumn();
        $menutypes = [];

        foreach ($rows as $paramsJson) {
            $menutype = $this->extractMenutypeFromJson(is_string($paramsJson) ? $paramsJson : '');

            if ($menutype !== null) {
                $menutypes[] = $menutype;
            }
        }

        $menutypes = array_values(array_unique($menutypes));

        return count($menutypes) === 1 ? $menutypes[0] : null;
    }

    private function findUniquePublishedModuleMenutypeForPosition(string $position, string $language): ?string
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('params'))
            ->from($this->db->quoteName('#__modules'))
            ->where('module = ' . $this->db->quote('mod_menu'))
            ->where('client_id = 0')
            ->where('published = 1')
            ->where('position = :position')
            ->where('language = :lang')
            ->bind(':position', $position)
            ->bind(':lang', $language);

        $rows = $this->db->setQuery($query)->loadColumn();
        $menutypes = [];

        foreach ($rows as $paramsJson) {
            $menutype = $this->extractMenutypeFromJson(is_string($paramsJson) ? $paramsJson : '');

            if ($menutype !== null) {
                $menutypes[] = $menutype;
            }
        }

        $menutypes = array_values(array_unique($menutypes));

        return count($menutypes) === 1 ? $menutypes[0] : null;
    }

    /**
     * @return array{result?: array<string, mixed>, conflict?: array<string, mixed>}
     */
    private function planModuleSlot(string $position, string $language, string $menutype): array
    {
        $query = $this->db->getQuery(true)
            ->select(['id', 'title', 'language', 'note', 'params'])
            ->from($this->db->quoteName('#__modules'))
            ->where('module = ' . $this->db->quote('mod_menu'))
            ->where('position = :position')
            ->where('client_id = 0')
            ->where('published = 1')
            ->where('(language = :lang OR language = ' . $this->db->quote('*') . ')')
            ->bind(':position', $position)
            ->bind(':lang', $language);

        $rows = $this->db->setQuery($query)->loadAssocList();
        $wildcards = [];
        $exact = [];

        foreach ($rows as $row) {
            if (($row['language'] ?? null) === '*') {
                $wildcards[] = $row;
                continue;
            }

            $exact[] = $row;
        }

        if ($wildcards !== []) {
            return [
                'conflict' => [
                    'type' => 'module_conflict',
                    'position' => $position,
                    'language' => $language,
                    'reason' => 'A wildcard mod_menu already exists at this position and would overlap with per-language modules.',
                    'module_ids' => array_map(static fn(array $row): int => (int) $row['id'], $wildcards),
                ],
            ];
        }

        if (count($exact) > 1) {
            return [
                'conflict' => [
                    'type' => 'module_conflict',
                    'position' => $position,
                    'language' => $language,
                    'reason' => 'More than one published mod_menu exists for this position and language.',
                    'module_ids' => array_map(static fn(array $row): int => (int) $row['id'], $exact),
                ],
            ];
        }

        if ($exact === []) {
            return [
                'result' => [
                    'position' => $position,
                    'language' => $language,
                    'menutype' => $menutype,
                    'action' => 'would_create',
                ],
            ];
        }

        $row = $exact[0];
        $candidateMenutype = $this->extractMenutypeFromJson((string) ($row['params'] ?? ''));

        if ($candidateMenutype !== $menutype) {
            return [
                'conflict' => [
                    'type' => 'module_conflict',
                    'position' => $position,
                    'language' => $language,
                    'reason' => "Existing mod_menu uses menutype \"{$candidateMenutype}\" instead of expected \"{$menutype}\".",
                    'module_ids' => [(int) $row['id']],
                ],
            ];
        }

        return [
            'result' => [
                'position' => $position,
                'language' => $language,
                'menutype' => $menutype,
                'action' => $this->isManagedModuleRow($row, $position, $menutype) ? 'exists' : 'would_reuse',
                'module_id' => (int) $row['id'],
            ],
        ];
    }

    private function extractMenutypeFromJson(string $paramsJson): ?string
    {
        if ($paramsJson === '') {
            return null;
        }

        $params = json_decode($paramsJson, true);

        if (!is_array($params)) {
            return null;
        }

        $menutype = $params['menutype'] ?? null;

        return is_string($menutype) && trim($menutype) !== '' ? trim($menutype) : null;
    }

    /**
     * @param array{id?: mixed, note?: mixed} $row
     */
    private function isManagedModuleRow(array $row, string $position, string $menutype): bool
    {
        return ($row['note'] ?? null) === $this->buildManagedNote($position, $menutype);
    }

    private function buildManagedNote(string $position, string $menutype): string
    {
        return sprintf('mirasai:menu_position=%s;menutype=%s', $position, $menutype);
    }

    private function markManagedModule(int $moduleId, string $position, string $menutype): void
    {
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__modules'))
            ->set($this->db->quoteName('note') . ' = ' . $this->db->quote($this->buildManagedNote($position, $menutype)))
            ->where('id = :id')
            ->bind(':id', $moduleId, ParameterType::INTEGER);

        $this->db->setQuery($query)->execute();
    }

    private function createMenuModule(
        string $position,
        string $language,
        string $menutype,
        string $languageTitle,
    ): int {
        $title = $position === 'dialog-mobile'
            ? 'Menu ' . $languageTitle . ' Mobile'
            : 'Menu ' . $languageTitle;

        $params = json_encode([
            'layout' => '_:default',
            'menutype' => $menutype,
            'startLevel' => '1',
            'endLevel' => '0',
            'showAllChildren' => '1',
            'moduleclass_sfx' => '',
            'cache' => '0',
            'cache_time' => '900',
            'cachemode' => 'itemid',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $columns = [
            'title', 'note', 'content', 'ordering', 'position',
            'published', 'module', 'access', 'showtitle', 'params',
            'client_id', 'language',
        ];

        $values = [
            $this->db->quote($title),
            $this->db->quote($this->buildManagedNote($position, $menutype)),
            $this->db->quote(''),
            0,
            $this->db->quote($position),
            1,
            $this->db->quote('mod_menu'),
            1,
            0,
            $this->db->quote($params),
            0,
            $this->db->quote($language),
        ];

        $query = 'INSERT INTO ' . $this->db->quoteName('#__modules')
            . ' (' . implode(',', array_map([$this->db, 'quoteName'], $columns)) . ')'
            . ' VALUES (' . implode(',', $values) . ')';

        $this->db->setQuery($query)->execute();

        $moduleId = (int) $this->db->insertid();

        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__modules_menu'))
            ->columns(['moduleid', 'menuid'])
            ->values($moduleId . ', 0');

        $this->db->setQuery($query)->execute();

        return $moduleId;
    }

    /**
     * @return array<string, string>
     */
    private function getPublishedLanguageTitleMap(): array
    {
        $map = [];

        foreach ($this->getPublishedLanguages() as $language) {
            $map[$language['lang_code']] = $language['title'];
        }

        return $map;
    }

    /**
     * @param list<string> $positions
     */
    private function clearThemeAssignments(int $styleId, array $positions): void
    {
        $params = $this->yooHelper->loadStyleParams($styleId) ?? [];
        $configJson = $params['config'] ?? '{}';
        $config = is_string($configJson) ? json_decode($configJson, true) : [];

        if (!is_array($config)) {
            $config = [];
        }

        if (!isset($config['menu']) || !is_array($config['menu'])) {
            $config['menu'] = [];
        }

        if (!isset($config['menu']['positions']) || !is_array($config['menu']['positions'])) {
            $config['menu']['positions'] = [];
        }

        foreach ($positions as $position) {
            if (!isset($config['menu']['positions'][$position]) || !is_array($config['menu']['positions'][$position])) {
                $config['menu']['positions'][$position] = [];
            }

            $config['menu']['positions'][$position]['menu'] = '';
        }

        $params['config'] = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->yooHelper->writeStyleParams($styleId, $params);
    }
}
