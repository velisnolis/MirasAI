<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Joomla\Database\ParameterType;

class ThemeExtractToModulesTool extends AbstractTool
{
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
        return 'Extracts a YOOtheme Builder theme area (footer, header, etc.) into per-language Joomla modules. Three steps: (1) creates mod_yootheme_builder modules per language with translated content, (2) replaces the theme area\'s Builder content with a module_position element that loads the per-language modules, (3) YOOtheme then serves the correct module based on the visitor\'s language.';
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

        // 1. Validate mod_yootheme_builder is installed
        if (!$this->isModuleTypeAvailable('mod_yootheme_builder')) {
            return ['error' => 'mod_yootheme_builder is not installed. YOOtheme Pro is required.'];
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

        // 7. Create a mod_yootheme_builder module per language
        $results = [];
        $conflicts = [];

        foreach ($languages as $lang) {
            // Check if a MirasAI-managed module already exists
            $existingId = $this->findExistingModule($position, $lang, $area);

            if ($existingId) {
                $results[] = [
                    'language' => $lang,
                    'action' => 'exists',
                    'module_id' => $existingId,
                ];
                continue;
            }

            // Check for non-MirasAI modules at the same position+language
            $conflictId = $this->findConflictingModule($position, $lang);
            if ($conflictId) {
                $conflicts[] = [
                    'language' => $lang,
                    'module_id' => $conflictId,
                    'position' => $position,
                    'reason' => 'Existing mod_yootheme_builder module not managed by MirasAI.',
                ];
                continue;
            }

            // Build the translated layout
            $translatedLayout = $layout;

            if (isset($translations[$lang]) && is_array($translations[$lang])) {
                $this->applyReplacements($translatedLayout, $translations[$lang], 'root');
            }

            $content = json_encode($translatedLayout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            // Create the module
            $moduleId = $this->createBuilderModule(
                $area,
                $lang,
                $position,
                $content,
            );

            $results[] = [
                'language' => $lang,
                'action' => 'created',
                'module_id' => $moduleId,
                'position' => $position,
            ];
        }

        // 8. If there were conflicts, abort the theme area replacement and report
        if (!empty($conflicts)) {
            return [
                'error' => 'Conflicts detected: existing modules not managed by MirasAI occupy the target position. Remove them or pass force: true (Phase 2).',
                'area' => $area,
                'position' => $position,
                'template_style_id' => $styleId,
                'translatable_nodes' => $translatableNodes,
                'modules' => $results,
                'conflicts' => $conflicts,
                'theme_area_replaced' => false,
            ];
        }

        // 9. Replace the theme area content with a module_position element
        $replaced = $this->replaceThemeAreaWithModulePosition($area, $position, $styleId);

        return [
            'area' => $area,
            'position' => $position,
            'template_style_id' => $styleId,
            'translatable_nodes' => $translatableNodes,
            'modules' => $results,
            'conflicts' => [],
            'theme_area_replaced' => $replaced,
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
     * @param  array<string, mixed>  $node
     * @param  array<string, string> $replacements
     */
    private function applyReplacements(array &$node, array $replacements, string $path): void
    {
        if (isset($node['props']) && is_array($node['props'])) {
            foreach ($node['props'] as $key => &$value) {
                $fullPath = "{$path}.{$key}";

                if (isset($replacements[$fullPath]) && is_string($value)) {
                    $value = $replacements[$fullPath];
                }
            }
        }

        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $i => &$child) {
                $childType = $child['type'] ?? 'unknown';
                $this->applyReplacements($child, $replacements, "{$path}>{$childType}[{$i}]");
            }
        }
    }

    /**
     * Replace the theme area's Builder content with a module_position element.
     *
     * Before: theme area has inline Builder content (e.g., buttons, text)
     * After:  theme area has a module_position element that loads modules from the position
     *
     * This way YOOtheme renders the per-language module instead of the static theme content.
     */
    private function replaceThemeAreaWithModulePosition(string $area, string $position, int $styleId): bool
    {
        $query = $this->db->getQuery(true)
            ->select('params')
            ->from($this->db->quoteName('#__template_styles'))
            ->where('id = :id')
            ->bind(':id', $styleId, ParameterType::INTEGER);

        $paramsJson = $this->db->setQuery($query)->loadResult();

        if (!$paramsJson) {
            return false;
        }

        $params = json_decode($paramsJson, true);
        $config = isset($params['config']) ? json_decode($params['config'], true) : [];

        if (!isset($config[$area]['content'])) {
            return false;
        }

        // Check if already replaced (module_position is already there)
        $existingContent = json_encode($config[$area]['content']);
        if (str_contains($existingContent, '"type":"module_position"')) {
            return false; // Already done
        }

        // Build the module_position layout
        $modulePositionLayout = [
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

        // Update the config
        $config[$area]['content'] = $modulePositionLayout;

        $params['config'] = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Write back to template_styles by id
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__template_styles'))
            ->set($this->db->quoteName('params') . ' = ' . $this->db->quote(
                json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ))
            ->where('id = :id')
            ->bind(':id', $styleId, ParameterType::INTEGER);

        $this->db->setQuery($query)->execute();

        return true;
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
        $note = self::buildMirasaiNote($area);

        $query = $this->db->getQuery(true)
            ->select('id')
            ->from($this->db->quoteName('#__modules'))
            ->where('module = ' . $this->db->quote('mod_yootheme_builder'))
            ->where('position = :pos')
            ->where('language = :lang')
            ->where('note = :note')
            ->where('client_id = 0')
            ->bind(':pos', $position)
            ->bind(':lang', $lang)
            ->bind(':note', $note);

        $result = $this->db->setQuery($query)->loadResult();

        return $result ? (int) $result : null;
    }

    /**
     * Detect non-MirasAI mod_yootheme_builder modules at the same position+language.
     */
    private function findConflictingModule(string $position, string $lang): ?int
    {
        $query = $this->db->getQuery(true)
            ->select('id')
            ->from($this->db->quoteName('#__modules'))
            ->where('module = ' . $this->db->quote('mod_yootheme_builder'))
            ->where('position = :pos')
            ->where('language = :lang')
            ->where('client_id = 0')
            ->where('(note IS NULL OR note NOT LIKE ' . $this->db->quote('mirasai:theme_area=%') . ')')
            ->bind(':pos', $position)
            ->bind(':lang', $lang);

        $result = $this->db->setQuery($query)->loadResult();

        return $result ? (int) $result : null;
    }

    /**
     * Build the standardised MirasAI note marker for a theme area module.
     */
    private static function buildMirasaiNote(string $area): string
    {
        return 'mirasai:theme_area=' . $area;
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
}
