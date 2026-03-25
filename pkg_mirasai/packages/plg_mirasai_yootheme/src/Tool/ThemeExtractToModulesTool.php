<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Joomla\Database\ParameterType;
use Mirasai\Library\Tool\AbstractTool;
use Mirasai\Library\Tool\YooThemeHelper;
use Mirasai\Library\Tool\YooThemeLayoutProcessor;

class ThemeExtractToModulesTool extends AbstractTool
{
    private const MIRASAI_BACKUP_LIMIT = 5;

    private YooThemeHelper $yooHelper;

    public function __construct()
    {
        parent::__construct();
        $this->yooHelper = new YooThemeHelper($this->db);
    }

    /** @var list<string> */
    private const TEXT_PROPS = [
        'content', 'title', 'meta', 'subtitle', 'text', 'video_title',
        'link_text', 'label', 'description', 'caption', 'alt',
        'button_text', 'heading', 'footer', 'header', 'placeholder',
    ];

    public function getName(): string
    {
        return 'theme/extract-to-modules';
    }

    public function getDescription(): string
    {
        return 'Converts a YOOtheme theme area (footer, header, top-bar, etc.) from a single-language Builder layout into per-language Joomla modules. '
            . 'Use this when content/audit-multilingual reports "theme area not translated". '
            . 'YOU must provide translated text in the "translations" parameter — this tool does NOT auto-translate. '
            . 'The tool creates one mod_yootheme_builder module per language, then replaces the original Builder content with a module_position element '
            . 'so YOOtheme serves the correct language automatically. Use dry_run=true first to preview changes.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'area' => [
                    'type' => 'string',
                    'description' => 'Theme area to extract: footer, header, top, bottom, sidebar.',
                ],
                'languages' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Languages to create modules for (e.g. ["ca-ES", "es-ES", "en-GB"]). Must include the source language.',
                ],
                'translations' => [
                    'type' => 'object',
                    'description' => 'Map of language => {path.field: translated_text}. The source language does not need translations (original text is kept).',
                    'additionalProperties' => [
                        'type' => 'object',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                ],
                'template_style_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: specific template_styles id to operate on. Defaults to the active YOOtheme style (client_id=0, home=1).',
                ],
                'dry_run' => [
                    'type' => 'boolean',
                    'description' => 'If true, validates and returns the execution plan without writing modules or changing the theme area.',
                ],
                'replace_theme_area' => [
                    'type' => 'boolean',
                    'description' => 'If true (default), replace the theme area content with a module_position wrapper after modules are ready.',
                ],
                'force' => [
                    'type' => 'boolean',
                    'description' => 'If true, attempt a safe takeover of an exact-language mod_yootheme_builder conflict instead of failing. Does not override arbitrary modules.',
                ],
            ],
            'required' => ['area', 'languages'],
        ];
    }

    public function getPermissions(): array
    {
        return [
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ];
    }

    public function handle(array $arguments): array
    {
        $area = $arguments['area'] ?? '';
        $languages = $arguments['languages'] ?? [];
        $translations = $arguments['translations'] ?? [];
        $dryRun = !empty($arguments['dry_run']);
        $force = !empty($arguments['force']);
        $replaceThemeArea = !array_key_exists('replace_theme_area', $arguments)
            || !empty($arguments['replace_theme_area']);

        if ($area === '' || empty($languages)) {
            return ['error' => 'area and languages are required.'];
        }

        // 0. Resolve the template_style id
        $styleId = isset($arguments['template_style_id'])
            ? (int) $arguments['template_style_id']
            : $this->resolveActiveStyleId();

        if (!$styleId) {
            return ['error' => 'No active YOOtheme template style found (client_id=0, home=1). Pass template_style_id explicitly if needed.'];
        }

        if (!$this->templateStyleExists($styleId)) {
            return ['error' => "Template style id={$styleId} does not exist or is not a YOOtheme site style."];
        }

        // 1. Validate mod_yootheme_builder is installed
        if (!$this->isModuleTypeAvailable('mod_yootheme_builder')) {
            return ['error' => 'mod_yootheme_builder is not installed. YOOtheme Pro is required.'];
        }

        $sourceLanguage = $this->detectSourceLanguage();

        if (!in_array($sourceLanguage, $languages, true)) {
            return ['error' => "languages must include the source language {$sourceLanguage}."];
        }

        // 2. Validate all requested languages are published
        $invalidLangs = [];
        foreach ($languages as $lang) {
            if (!$this->languageExists($lang)) {
                $invalidLangs[] = $lang;
            }
        }
        if (!empty($invalidLangs)) {
            return ['error' => 'Languages not published: ' . implode(', ', $invalidLangs)];
        }

        // 3. Read the theme area layout
        $layout = $this->readThemeAreaLayout($area, $styleId);

        if (!$layout) {
            return ['error' => "Theme area \"{$area}\" has no Builder content in template_style id={$styleId}."];
        }

        // 4. Validate the layout JSON structure
        if (!isset($layout['type']) && !isset($layout['children'])) {
            return ['error' => "Theme area \"{$area}\" has invalid Builder layout (missing type/children)."];
        }

        // 5. Find translatable nodes in the layout
        $translatableNodes = $this->findTranslatableNodes($layout, 'root');

        // 6. Determine the Joomla module position for this area
        $position = $this->resolvePosition($area);

        // 7. Plan module operations before writing anything
        $plan = [];
        $conflicts = [];
        $warnings = [];

        foreach ($languages as $lang) {
            // Check if a MirasAI-managed module already exists
            $existingId = $this->findExistingModule($position, $lang, $area);

            if ($existingId) {
                $plan[] = [
                    'language' => $lang,
                    'action' => 'exists',
                    'module_id' => $existingId,
                ];
                continue;
            }

            // Check for any non-MirasAI module that would also render at this position.
            $langConflicts = $this->findConflictingModules($position, $lang, $area);

            if (!empty($langConflicts)) {
                if ($force) {
                    $takeoverModuleId = $this->findTakeoverCandidate($langConflicts, $lang);

                    if ($takeoverModuleId !== null) {
                        foreach ($langConflicts as $conflict) {
                            $conflict['forced'] = true;
                            $warnings[] = sprintf(
                                'force: taking over existing module id=%d (%s) at position "%s" for %s.',
                                $conflict['module_id'],
                                $conflict['existing_title'],
                                $position,
                                $lang,
                            );
                            $conflicts[] = $conflict;
                        }

                        $plan[] = [
                            'language' => $lang,
                            'action' => $dryRun ? 'would_take_over' : 'take_over',
                            'module_id' => $takeoverModuleId,
                            'position' => $position,
                        ];
                        continue;
                    }

                    foreach ($langConflicts as $conflict) {
                        $conflict['forced'] = true;
                        $conflicts[] = $conflict;
                    }
                } else {
                    foreach ($langConflicts as $conflict) {
                        $conflicts[] = $conflict;
                    }
                    continue;
                }
            }

            $plan[] = [
                'language' => $lang,
                'action' => $dryRun ? 'would_create' : 'create',
                'position' => $position,
            ];
        }

        // 8. If there were conflicts without force, abort and report
        if (!empty($conflicts) && !$force) {
            return [
                'error' => 'Conflicts detected: existing modules occupy the target position and would render through module_position. Use force: true only to take over an exact-language mod_yootheme_builder module.',
                'area' => $area,
                'position' => $position,
                'template_style_id' => $styleId,
                'source_language' => $sourceLanguage,
                'dry_run' => $dryRun,
                'translatable_nodes' => $translatableNodes,
                'modules' => $plan,
                'conflicts' => $conflicts,
                'warnings' => $warnings,
                'theme_area' => [
                    'requested_replace' => $replaceThemeArea,
                    'status' => 'not_replaced',
                    'backup_reference' => null,
                ],
            ];
        }

        if (!empty($conflicts) && $force) {
            $blockingConflicts = array_values(array_filter(
                $conflicts,
                static fn(array $conflict): bool => empty($conflict['takeover_safe']),
            ));

            if ($blockingConflicts !== []) {
                return [
                    'error' => 'Conflicts detected that cannot be safely forced. Only exact-language mod_yootheme_builder modules can be taken over.',
                    'area' => $area,
                    'position' => $position,
                    'template_style_id' => $styleId,
                    'source_language' => $sourceLanguage,
                    'dry_run' => $dryRun,
                    'translatable_nodes' => $translatableNodes,
                    'modules' => $plan,
                    'conflicts' => $conflicts,
                    'warnings' => $warnings,
                    'theme_area' => [
                        'requested_replace' => $replaceThemeArea,
                        'status' => 'not_replaced',
                        'backup_reference' => null,
                    ],
                ];
            }
        }

        // 9. Execute module creation plan unless dry-run
        $results = [];

        foreach ($plan as $item) {
            if ($item['action'] === 'exists') {
                $results[] = $item;
                continue;
            }

            $lang = $item['language'];
            $translatedLayout = $layout;

            if (isset($translations[$lang]) && is_array($translations[$lang])) {
                $translatedLayout = (new YooThemeLayoutProcessor())->patchLayoutArray($translatedLayout, $translations[$lang]);
            }

            $content = json_encode($translatedLayout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (!is_string($content) || $content === '') {
                return ['error' => "Failed to encode Builder layout for {$lang}."];
            }

            if ($dryRun) {
                $results[] = $item;
                continue;
            }

            if ($item['action'] === 'take_over') {
                $moduleId = (int) $item['module_id'];
                $this->updateBuilderModule(
                    $moduleId,
                    $area,
                    $lang,
                    $position,
                    $content,
                );
            } else {
                $moduleId = $this->createBuilderModule(
                    $area,
                    $lang,
                    $position,
                    $content,
                );
            }

            $results[] = [
                'language' => $lang,
                'action' => $item['action'] === 'take_over' ? 'taken_over' : 'created',
                'module_id' => $moduleId,
                'position' => $position,
            ];
        }

        $themeAreaStatus = [
            'requested_replace' => $replaceThemeArea,
            'status' => 'not_replaced',
            'backup_reference' => null,
        ];

        if ($replaceThemeArea) {
            $themeAreaStatus = $this->replaceThemeAreaWithModulePosition(
                $area,
                $position,
                $styleId,
                $dryRun,
            );
        } else {
            $themeAreaStatus['status'] = $dryRun ? 'would_skip' : 'skipped';
        }

        if (($themeAreaStatus['warning'] ?? '') !== '') {
            $warnings[] = $themeAreaStatus['warning'];
            unset($themeAreaStatus['warning']);
        }

        // Build numeric summary for agents and auditing
        $modulesCreated = 0;
        $modulesReused = 0;

        foreach ($results as $r) {
            match ($r['action'] ?? null) {
                'created' => $modulesCreated++,
                'exists', 'taken_over' => $modulesReused++,
                default => null,
            };
        }

        return [
            'area' => $area,
            'position' => $position,
            'template_style_id' => $styleId,
            'source_language' => $sourceLanguage,
            'dry_run' => $dryRun,
            'modules_created' => $modulesCreated,
            'modules_reused' => $modulesReused,
            'translatable_nodes' => $translatableNodes,
            'modules' => $results,
            'conflicts' => $conflicts,
            'warnings' => $warnings,
            'theme_area' => $themeAreaStatus,
        ];
    }

    /**
     * Resolve the active YOOtheme template style id (site-side, home=1).
     */
    private function resolveActiveStyleId(): ?int
    {
        $query = $this->db->getQuery(true)
            ->select('id')
            ->from($this->db->quoteName('#__template_styles'))
            ->where('template = ' . $this->db->quote('yootheme'))
            ->where('client_id = 0')
            ->where('home = 1');

        $result = $this->db->setQuery($query)->loadResult();

        return $result ? (int) $result : null;
    }

    private function templateStyleExists(int $styleId): bool
    {
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__template_styles'))
            ->where('id = :id')
            ->where('template = ' . $this->db->quote('yootheme'))
            ->where('client_id = 0')
            ->bind(':id', $styleId, ParameterType::INTEGER);

        return (int) $this->db->setQuery($query)->loadResult() > 0;
    }

    /**
     * Check if a module type is installed and available.
     */
    private function isModuleTypeAvailable(string $module): bool
    {
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__extensions'))
            ->where('element = ' . $this->db->quote($module))
            ->where('type = ' . $this->db->quote('module'));

        return (int) $this->db->setQuery($query)->loadResult() > 0;
    }

    private function detectSourceLanguage(): string
    {
        $query = $this->db->getQuery(true)
            ->select(['language', 'COUNT(*) AS cnt'])
            ->from($this->db->quoteName('#__content'))
            ->where('state >= 0')
            ->where('language != ' . $this->db->quote('*'))
            ->group('language')
            ->order('cnt DESC');

        $row = $this->db->setQuery($query, 0, 1)->loadAssoc();

        return $row ? (string) $row['language'] : 'ca-ES';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readThemeAreaLayout(string $area, int $styleId): ?array
    {
        $query = $this->db->getQuery(true)
            ->select('params')
            ->from($this->db->quoteName('#__template_styles'))
            ->where('id = :id')
            ->bind(':id', $styleId, ParameterType::INTEGER);

        $params = $this->db->setQuery($query)->loadResult();

        if (!$params) {
            return null;
        }

        $config = json_decode($params, true);
        $configInner = isset($config['config']) ? json_decode($config['config'], true) : [];

        $areaData = $configInner[$area] ?? null;

        if (!$areaData || !isset($areaData['content'])) {
            return null;
        }

        return $areaData['content'];
    }

    /**
     * @return list<array{path: string, node_type: string, field: string, text: string}>
     */
    private function findTranslatableNodes(array $node, string $path): array
    {
        $results = [];
        $props = $node['props'] ?? [];
        $nodeType = $node['type'] ?? 'unknown';

        foreach ($props as $key => $value) {
            if (!is_string($value) || strlen(trim($value)) < 2) {
                continue;
            }

            if (in_array($key, self::TEXT_PROPS, true)) {
                $results[] = [
                    'path' => $path,
                    'node_type' => $nodeType,
                    'field' => $key,
                    'text' => $value,
                ];
            }
        }

        foreach ($node['children'] ?? [] as $i => $child) {
            $childType = $child['type'] ?? 'unknown';
            $results = array_merge($results, $this->findTranslatableNodes($child, "{$path}>{$childType}[{$i}]"));
        }

        return $results;
    }

    /**
     * Replace the theme area's Builder content with a module_position element.
     *
     * Before: theme area has inline Builder content (e.g., buttons, text)
     * After:  theme area has a module_position element that loads modules from the position
     *
     * This way YOOtheme renders the per-language module instead of the static theme content.
     */
    private function replaceThemeAreaWithModulePosition(
        string $area,
        string $position,
        int $styleId,
        bool $dryRun,
    ): array
    {
        $params = $this->loadTemplateStyleParams($styleId);

        if ($params === null) {
            return [
                'requested_replace' => true,
                'status' => 'not_replaced',
                'backup_reference' => null,
            ];
        }

        $config = isset($params['config']) ? json_decode($params['config'], true) : [];

        if (!isset($config[$area]['content'])) {
            return [
                'requested_replace' => true,
                'status' => 'not_replaced',
                'backup_reference' => null,
            ];
        }

        $modulePositionLayout = $this->buildModulePositionLayout($position);
        $existingContent = $config[$area]['content'];

        if ($this->layoutsAreEquivalent($existingContent, $modulePositionLayout)) {
            return [
                'requested_replace' => true,
                'status' => $dryRun ? 'would_keep_replaced' : 'already_replaced',
                'backup_reference' => null,
            ];
        }

        if ($this->layoutContainsModulePosition($existingContent)) {
            return [
                'requested_replace' => true,
                'status' => 'not_replaced',
                'backup_reference' => null,
                'warning' => 'Theme area already contains a custom module_position layout that does not match the MirasAI wrapper. Leaving it unchanged.',
            ];
        }

        if ($dryRun) {
            return [
                'requested_replace' => true,
                'status' => 'would_replace',
                'backup_reference' => null,
            ];
        }

        $backupReference = $this->storeThemeAreaBackup($styleId, $area, $existingContent, $position);

        // Update the config
        $config[$area]['content'] = $modulePositionLayout;
        $params['config'] = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->writeTemplateStyleParams($styleId, $params);

        return [
            'requested_replace' => true,
            'status' => 'replaced',
            'backup_reference' => $backupReference,
        ];
    }

    private function resolvePosition(string $area): string
    {
        // YOOtheme uses area names as position names
        // but some areas map to specific positions
        return match ($area) {
            'footer' => 'footer',
            'header' => 'header',
            'top' => 'top',
            'bottom' => 'bottom',
            'sidebar' => 'sidebar',
            default => $area,
        };
    }

    /**
     * Find an existing MirasAI-managed module for this position, language, and area.
     */
    private function findExistingModule(string $position, string $lang, string $area): ?int
    {
        $query = $this->db->getQuery(true)
            ->select(['id', 'title', 'module', 'language', 'note'])
            ->from($this->db->quoteName('#__modules'))
            ->where('module = ' . $this->db->quote('mod_yootheme_builder'))
            ->where('position = :pos')
            ->where('language = :lang')
            ->where('client_id = 0')
            ->bind(':pos', $position)
            ->bind(':lang', $lang);

        $rows = $this->db->setQuery($query)->loadAssocList();

        foreach ($rows as $row) {
            if ($this->isManagedModuleRow($row, $area, $lang)) {
                return (int) $row['id'];
            }
        }

        return null;
    }

    /**
     * Detect non-MirasAI mod_yootheme_builder modules at the same position+language.
     */
    private function findConflictingModules(string $position, string $lang, string $area): array
    {
        $query = $this->db->getQuery(true)
            ->select(['id', 'title', 'module', 'language', 'note'])
            ->from($this->db->quoteName('#__modules'))
            ->where('position = :pos')
            ->where('(language = :lang OR language = ' . $this->db->quote('*') . ')')
            ->where('client_id = 0')
            ->where('published = 1')
            ->bind(':pos', $position)
            ->bind(':lang', $lang);

        $rows = $this->db->setQuery($query)->loadAssocList();
        $conflicts = [];

        foreach ($rows as $row) {
            if ($this->isManagedModuleRow($row, $area, $lang)) {
                continue;
            }

            $conflicts[] = [
                'language' => $lang,
                'module_id' => (int) $row['id'],
                'position' => $position,
                'existing_module' => $row['module'],
                'existing_title' => $row['title'],
                'existing_language' => $row['language'],
                'reason' => 'Existing published module would also render through this module position.',
                'takeover_safe' => $this->isTakeoverSafeConflict($row, $lang),
            ];
        }

        return $conflicts;
    }

    /**
     * @param array<int, array<string, mixed>> $conflicts
     */
    private function findTakeoverCandidate(array $conflicts, string $lang): ?int
    {
        if (count($conflicts) !== 1) {
            return null;
        }

        $conflict = $conflicts[0];

        if (empty($conflict['takeover_safe']) || ($conflict['existing_language'] ?? null) !== $lang) {
            return null;
        }

        return (int) $conflict['module_id'];
    }

    /**
     * @param array{module?: mixed, language?: mixed} $row
     */
    private function isTakeoverSafeConflict(array $row, string $lang): bool
    {
        return ($row['module'] ?? null) === 'mod_yootheme_builder'
            && ($row['language'] ?? null) === $lang;
    }

    /**
     * Build the standardised MirasAI note marker for a theme area module.
     */
    private static function buildMirasaiNote(string $area): string
    {
        return 'mirasai:theme_area=' . $area;
    }

    /**
     * Accept both the current marker and the legacy "Created by MirasAI" note
     * when the module still matches the expected area, title, and language.
     *
     * @param array{id?: mixed, title?: mixed, module?: mixed, language?: mixed, note?: mixed} $row
     */
    private function isManagedModuleRow(array $row, string $area, string $lang): bool
    {
        if (($row['module'] ?? null) !== 'mod_yootheme_builder') {
            return false;
        }

        if (($row['language'] ?? null) !== $lang) {
            return false;
        }

        $expectedTitle = ucfirst($area) . ' (' . $lang . ')';
        $note = (string) ($row['note'] ?? '');
        $title = (string) ($row['title'] ?? '');

        if ($note === self::buildMirasaiNote($area)) {
            return true;
        }

        return $note === 'Created by MirasAI' && $title === $expectedTitle;
    }

    private function createBuilderModule(
        string $area,
        string $language,
        string $position,
        string $content,
    ): int {
        $title = ucfirst($area) . ' (' . $language . ')';

        $params = json_encode([
            'layout' => '_:default',
            'moduleclass_sfx' => '',
            'cache' => '0',
            'cache_time' => '900',
            'cachemode' => 'static',
        ], JSON_UNESCAPED_SLASHES);

        $columns = [
            'title', 'note', 'content', 'ordering', 'position',
            'published', 'module', 'access', 'showtitle', 'params',
            'client_id', 'language',
        ];

        $values = [
            $this->db->quote($title),
            $this->db->quote(self::buildMirasaiNote($area)),
            $this->db->quote($content),
            0,
            $this->db->quote($position),
            1,
            $this->db->quote('mod_yootheme_builder'),
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

        // Assign to all pages
        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__modules_menu'))
            ->columns(['moduleid', 'menuid'])
            ->values($moduleId . ', 0');

        $this->db->setQuery($query)->execute();

        return $moduleId;
    }

    private function updateBuilderModule(
        int $moduleId,
        string $area,
        string $language,
        string $position,
        string $content,
    ): void {
        $title = ucfirst($area) . ' (' . $language . ')';
        $params = json_encode([
            'layout' => '_:default',
            'moduleclass_sfx' => '',
            'cache' => '0',
            'cache_time' => '900',
            'cachemode' => 'static',
        ], JSON_UNESCAPED_SLASHES);

        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__modules'))
            ->set($this->db->quoteName('title') . ' = ' . $this->db->quote($title))
            ->set($this->db->quoteName('note') . ' = ' . $this->db->quote(self::buildMirasaiNote($area)))
            ->set($this->db->quoteName('content') . ' = ' . $this->db->quote($content))
            ->set($this->db->quoteName('position') . ' = ' . $this->db->quote($position))
            ->set($this->db->quoteName('published') . ' = 1')
            ->set($this->db->quoteName('module') . ' = ' . $this->db->quote('mod_yootheme_builder'))
            ->set($this->db->quoteName('access') . ' = 1')
            ->set($this->db->quoteName('showtitle') . ' = 0')
            ->set($this->db->quoteName('params') . ' = ' . $this->db->quote($params))
            ->set($this->db->quoteName('client_id') . ' = 0')
            ->set($this->db->quoteName('language') . ' = ' . $this->db->quote($language))
            ->where('id = :id')
            ->bind(':id', $moduleId, ParameterType::INTEGER);

        $this->db->setQuery($query)->execute();

        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__modules_menu'))
            ->where('moduleid = :moduleid')
            ->where('menuid = 0')
            ->bind(':moduleid', $moduleId, ParameterType::INTEGER);

        $hasAllPagesAssignment = (int) $this->db->setQuery($query)->loadResult() > 0;

        if (!$hasAllPagesAssignment) {
            $query = $this->db->getQuery(true)
                ->insert($this->db->quoteName('#__modules_menu'))
                ->columns(['moduleid', 'menuid'])
                ->values($moduleId . ', 0');

            $this->db->setQuery($query)->execute();
        }
    }

    private function buildModulePositionLayout(string $position): array
    {
        return [
            'type' => 'layout',
            'children' => [
                [
                    'type' => 'section',
                    'props' => [
                        'style' => 'default',
                        'width' => 'default',
                        'vertical_align' => 'middle',
                        'image_position' => 'center-center',
                        'padding_top' => 'none',
                        'padding_bottom' => 'xsmall',
                    ],
                    'children' => [
                        [
                            'type' => 'row',
                            'props' => [],
                            'children' => [
                                [
                                    'type' => 'column',
                                    'props' => [
                                        'image_position' => 'center-center',
                                        'position_sticky_breakpoint' => 'm',
                                    ],
                                    'children' => [
                                        [
                                            'type' => 'module_position',
                                            'props' => [
                                                'layout' => 'stack',
                                                'breakpoint' => 'm',
                                                'content' => $position,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'version' => '5.0.24',
        ];
    }

    private function loadTemplateStyleParams(int $styleId): ?array
    {
        $query = $this->db->getQuery(true)
            ->select('params')
            ->from($this->db->quoteName('#__template_styles'))
            ->where('id = :id')
            ->bind(':id', $styleId, ParameterType::INTEGER);

        $paramsJson = $this->db->setQuery($query)->loadResult();

        if (!is_string($paramsJson) || $paramsJson === '') {
            return null;
        }

        $params = json_decode($paramsJson, true);

        return is_array($params) ? $params : null;
    }

    private function writeTemplateStyleParams(int $styleId, array $params): void
    {
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__template_styles'))
            ->set($this->db->quoteName('params') . ' = ' . $this->db->quote(
                json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ))
            ->where('id = :id')
            ->bind(':id', $styleId, ParameterType::INTEGER);

        $this->db->setQuery($query)->execute();
    }

    private function layoutsAreEquivalent(array $left, array $right): bool
    {
        return json_encode($this->normaliseLayoutForComparison($left), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            === json_encode($this->normaliseLayoutForComparison($right), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function layoutContainsModulePosition(array $node): bool
    {
        if (($node['type'] ?? null) === 'module_position') {
            return true;
        }

        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child) && $this->layoutContainsModulePosition($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compare Builder layouts by structure and meaningful module-position data,
     * ignoring decorative YOOtheme props that legacy wrappers may carry.
     *
     * @return array<string, mixed>
     */
    private function normaliseLayoutForComparison(array $node): array
    {
        $normalised = [
            'type' => $node['type'] ?? null,
        ];

        if (($node['type'] ?? null) === 'module_position') {
            $normalised['content'] = $node['props']['content'] ?? null;
        }

        $children = [];

        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $children[] = $this->normaliseLayoutForComparison($child);
            }
        }

        if ($children !== []) {
            $normalised['children'] = $children;
        }

        return $normalised;
    }

    private function storeThemeAreaBackup(
        int $styleId,
        string $area,
        array $content,
        string $position,
    ): string {
        $params = $this->loadTemplateStyleParams($styleId) ?? [];
        $timestamp = gmdate('YmdHis');
        $backupReference = sprintf('style:%d:theme-area:%s:%s', $styleId, $area, $timestamp);

        if (!isset($params['mirasai_backups']) || !is_array($params['mirasai_backups'])) {
            $params['mirasai_backups'] = [];
        }

        if (!isset($params['mirasai_backups']['theme_areas']) || !is_array($params['mirasai_backups']['theme_areas'])) {
            $params['mirasai_backups']['theme_areas'] = [];
        }

        $params['mirasai_backups']['theme_areas'][$backupReference] = [
            'area' => $area,
            'position' => $position,
            'created_at' => gmdate('c'),
            'content' => $content,
        ];

        while (count($params['mirasai_backups']['theme_areas']) > self::MIRASAI_BACKUP_LIMIT) {
            array_shift($params['mirasai_backups']['theme_areas']);
        }

        $this->writeTemplateStyleParams($styleId, $params);

        return $backupReference;
    }
}
