<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

class ContentAuditMultilingualTool extends AbstractTool
{
    public function getName(): string
    {
        return 'content/audit-multilingual';
    }

    public function getDescription(): string
    {
        return 'Scans the entire Joomla site and returns a structured diagnostic of multilingual completeness. Reports gaps in articles, menus, modules, categories, metadata, language switcher, and theme areas. Each gap includes the MCP tool call needed to fix it.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'languages' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Target languages to audit (e.g. ["es-ES", "en-GB"]). If omitted, audits all published languages except the default.',
                ],
            ],
        ];
    }

    public function handle(array $arguments): array
    {
        // Determine source and target languages
        $allLangs = $this->getPublishedLanguages();
        $sourceLang = $this->detectSourceLanguage();
        $targetLangs = $arguments['languages'] ?? [];

        if (empty($targetLangs)) {
            $targetLangs = array_filter(
                array_column($allLangs, 'lang_code'),
                fn($l) => $l !== $sourceLang && $l !== '*',
            );
        }

        $targetLangs = array_values($targetLangs);

        $gaps = [];

        // 1. Audit articles
        $gaps = array_merge($gaps, $this->auditArticles($sourceLang, $targetLangs));

        // 2. Audit menus
        $gaps = array_merge($gaps, $this->auditMenus($sourceLang, $targetLangs));
        $gaps = array_merge($gaps, $this->auditThemeManagedMenus());

        // 3. Audit categories
        $gaps = array_merge($gaps, $this->auditCategories($sourceLang, $targetLangs));

        // 4. Audit modules
        $gaps = array_merge($gaps, $this->auditModules());

        // 5. Audit language switcher
        $gaps = array_merge($gaps, $this->auditLanguageSwitcher());

        // 6. Audit SEO metadata
        $gaps = array_merge($gaps, $this->auditMetadata($sourceLang, $targetLangs));

        // 7. Audit theme areas
        $gaps = array_merge($gaps, $this->auditThemeAreas());
        $gaps = array_merge($gaps, $this->auditTemplates($sourceLang, $targetLangs));

        // Summary
        $byType = [];
        foreach ($gaps as $gap) {
            $byType[$gap['type']] = ($byType[$gap['type']] ?? 0) + 1;
        }

        return [
            'source_language' => $sourceLang,
            'target_languages' => array_values($targetLangs),
            'total_gaps' => count($gaps),
            'gaps_by_type' => $byType,
            'gaps' => $gaps,
        ];
    }

    private function detectSourceLanguage(): string
    {
        return $this->detectLikelySourceLanguage();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditArticles(string $sourceLang, array $targetLangs): array
    {
        $query = $this->db->getQuery(true)
            ->select(['id', 'title', 'language'])
            ->from($this->db->quoteName('#__content'))
            ->where('language = :lang')
            ->where('state >= 0')
            ->bind(':lang', $sourceLang);

        $articles = $this->db->setQuery($query)->loadAssocList();
        $gaps = [];

        foreach ($articles as $article) {
            foreach ($targetLangs as $targetLang) {
                $translationId = $this->findTranslation((int) $article['id'], $targetLang, 'com_content.item');

                if (!$translationId) {
                    $gaps[] = [
                        'type' => 'article_untranslated',
                        'severity' => 'high',
                        'source_id' => (int) $article['id'],
                        'source_title' => $article['title'],
                        'missing_in' => $targetLang,
                        'fix' => [
                            'tool' => 'content/translate',
                            'arguments' => [
                                'source_id' => (int) $article['id'],
                                'target_language' => $targetLang,
                                'translated_title' => '[TRANSLATE: ' . $article['title'] . ']',
                            ],
                        ],
                    ];
                }
            }
        }

        return $gaps;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditMenus(string $sourceLang, array $targetLangs): array
    {
        $gaps = [];

        // Check each target language has a home page
        foreach ($targetLangs as $targetLang) {
            $query = $this->db->getQuery(true)
                ->select('COUNT(*)')
                ->from($this->db->quoteName('#__menu'))
                ->where('home = 1')
                ->where('language = :lang')
                ->where('client_id = 0')
                ->where('published = 1')
                ->bind(':lang', $targetLang);

            $hasHome = (int) $this->db->setQuery($query)->loadResult() > 0;

            if (!$hasHome) {
                $gaps[] = [
                    'type' => 'menu_no_home_page',
                    'severity' => 'critical',
                    'missing_in' => $targetLang,
                    'hint' => "Language {$targetLang} has no published home menu item.",
                    'fix' => null, // Manual fix required
                ];
            }
        }

        // Check source menu items have translations
        $query = $this->db->getQuery(true)
            ->select(['id', 'title', 'language'])
            ->from($this->db->quoteName('#__menu'))
            ->where('language = :lang')
            ->where('client_id = 0')
            ->where('published >= 0')
            ->bind(':lang', $sourceLang);

        $menuItems = $this->db->setQuery($query)->loadAssocList();

        foreach ($menuItems as $item) {
            foreach ($targetLangs as $targetLang) {
                $translationId = $this->findTranslation((int) $item['id'], $targetLang, 'com_menus.item');

                if (!$translationId) {
                    $gaps[] = [
                        'type' => 'menu_item_untranslated',
                        'severity' => 'medium',
                        'source_id' => (int) $item['id'],
                        'source_title' => $item['title'],
                        'missing_in' => $targetLang,
                        'hint' => "Menu item \"{$item['title']}\" has no equivalent in {$targetLang}.",
                        'fix' => null, // Created automatically by content/translate
                    ];
                }
            }
        }

        return $gaps;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditThemeManagedMenus(): array
    {
        $styleId = $this->resolveActiveYoothemeStyleId();

        if (!$styleId) {
            return [];
        }

        $config = $this->loadYoothemeStyleConfig($styleId);

        if ($config === null) {
            return [];
        }

        $positions = ['navbar', 'dialog-mobile'];
        $themeAssignments = [];

        foreach ($positions as $position) {
            $menu = $config['menu']['positions'][$position]['menu'] ?? '';
            $themeAssignments[$position] = is_string($menu) ? trim($menu) : '';
        }

        $languages = [];

        foreach ($this->getPublishedLanguages() as $language) {
            if ($language['lang_code'] !== '*') {
                $languages[] = $language['lang_code'];
            }
        }

        if ($languages === []) {
            return [];
        }

        $query = $this->db->getQuery(true)
            ->select(['id', 'title', 'position', 'language', 'params'])
            ->from($this->db->quoteName('#__modules'))
            ->where('module = ' . $this->db->quote('mod_menu'))
            ->where('client_id = 0')
            ->where('published = 1')
            ->where('position IN (' . implode(',', array_map([$this->db, 'quote'], $positions)) . ')');

        $moduleRows = $this->db->setQuery($query)->loadAssocList();
        $hasAnyModules = !empty($moduleRows);
        $conflicts = [];
        $languagesMissing = [];

        foreach ($positions as $position) {
            $rowsForPosition = array_values(array_filter(
                $moduleRows,
                static fn(array $row): bool => ($row['position'] ?? null) === $position,
            ));

            foreach ($rowsForPosition as $row) {
                if (($row['language'] ?? null) === '*') {
                    $conflicts[] = [
                        'position' => $position,
                        'language' => '*',
                        'module_id' => (int) $row['id'],
                        'reason' => 'Wildcard mod_menu at a multilingual theme menu position.',
                    ];
                }
            }

            foreach ($languages as $language) {
                $candidates = array_values(array_filter(
                    $rowsForPosition,
                    static fn(array $row): bool => ($row['language'] ?? null) === $language,
                ));

                if (count($candidates) === 0) {
                    $languagesMissing[$position][] = $language;
                    continue;
                }

                if (count($candidates) > 1) {
                    $conflicts[] = [
                        'position' => $position,
                        'language' => $language,
                        'module_ids' => array_map(static fn(array $row): int => (int) $row['id'], $candidates),
                        'reason' => 'More than one published mod_menu exists for this position and language.',
                    ];
                    continue;
                }

                $candidate = $candidates[0];
                $candidateMenutype = $this->extractModuleMenutypeForAudit((string) ($candidate['params'] ?? ''));

                if ($candidateMenutype === null) {
                    $conflicts[] = [
                        'position' => $position,
                        'language' => $language,
                        'module_id' => (int) $candidate['id'],
                        'reason' => 'mod_menu is missing a valid menutype parameter.',
                    ];
                }
            }
        }

        $fix = [
            'tool' => 'menu/migrate-theme-to-modules',
            'arguments' => [
                'positions' => $positions,
                'languages' => $languages,
                'template_style_id' => $styleId,
                'dry_run' => true,
            ],
        ];

        if ($conflicts !== []) {
            return [[
                'type' => 'theme_menu_module_conflict',
                'severity' => 'medium',
                'positions' => array_values(array_unique(array_map(
                    static fn(array $conflict): string => $conflict['position'],
                    $conflicts,
                ))),
                'current_theme_assignments' => $themeAssignments,
                'hint' => 'Theme menu positions have conflicting mod_menu candidates. Resolve conflicts before migrating or declaring the header multilingual-ready.',
                'conflicts' => $conflicts,
                'fix' => $fix,
            ]];
        }

        $hasThemeAssignments = array_filter($themeAssignments, static fn(string $menu): bool => $menu !== '') !== [];
        $hasMissingModules = array_filter($languagesMissing, static fn(array $langs): bool => $langs !== []) !== [];

        if (!$hasThemeAssignments && !$hasMissingModules) {
            return [];
        }

        if ($hasThemeAssignments && !$hasAnyModules) {
            return [[
                'type' => 'theme_menu_still_assigned',
                'severity' => 'high',
                'positions' => array_keys(array_filter($themeAssignments, static fn(string $menu): bool => $menu !== '')),
                'current_theme_assignments' => $themeAssignments,
                'hint' => 'YOOtheme still manages the header menus directly. Clear the theme menu assignments and replace them with per-language mod_menu modules.',
                'fix' => $fix,
            ]];
        }

        if ($hasThemeAssignments) {
            return [[
                'type' => 'theme_menu_mixed_mode',
                'severity' => 'high',
                'positions' => $positions,
                'languages_missing' => $languagesMissing,
                'current_theme_assignments' => $themeAssignments,
                'hint' => 'Header menus are split between YOOtheme menu assignments and Joomla menu modules. Finish the migration to per-language modules before considering the site multilingual-ready.',
                'fix' => $fix,
            ]];
        }

        return [[
            'type' => 'theme_menu_modules_missing',
            'severity' => 'high',
            'positions' => array_keys($languagesMissing),
            'languages_missing' => $languagesMissing,
            'current_theme_assignments' => $themeAssignments,
            'hint' => 'YOOtheme no longer manages the header menus, but one or more per-language mod_menu modules are missing.',
            'fix' => $fix,
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditCategories(string $sourceLang, array $targetLangs): array
    {
        $query = $this->db->getQuery(true)
            ->select(['id', 'title', 'language'])
            ->from($this->db->quoteName('#__categories'))
            ->where('extension = ' . $this->db->quote('com_content'))
            ->where('language = :lang')
            ->where('published >= 0')
            ->bind(':lang', $sourceLang);

        $categories = $this->db->setQuery($query)->loadAssocList();
        $gaps = [];

        foreach ($categories as $cat) {
            foreach ($targetLangs as $targetLang) {
                $translationId = $this->findTranslation((int) $cat['id'], $targetLang, 'com_categories.item');

                if (!$translationId) {
                    $gaps[] = [
                        'type' => 'category_untranslated',
                        'severity' => 'medium',
                        'source_id' => (int) $cat['id'],
                        'source_title' => $cat['title'],
                        'missing_in' => $targetLang,
                        'fix' => [
                            'tool' => 'category/translate',
                            'arguments' => [
                                'source_id' => (int) $cat['id'],
                                'target_language' => $targetLang,
                                'translated_title' => '[TRANSLATE: ' . $cat['title'] . ']',
                            ],
                        ],
                    ];
                }
            }
        }

        return $gaps;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditModules(): array
    {
        // Find modules assigned to all languages that might need per-language versions
        $query = $this->db->getQuery(true)
            ->select(['id', 'title', 'module', 'position', 'language', 'published'])
            ->from($this->db->quoteName('#__modules'))
            ->where('client_id = 0')
            ->where('published = 1')
            ->where('language = ' . $this->db->quote('*'))
            ->where('position != ' . $this->db->quote(''));

        $modules = $this->db->setQuery($query)->loadAssocList();
        $gaps = [];

        foreach ($modules as $mod) {
            // Skip system modules that don't need translation
            if (in_array($mod['module'], ['mod_menu', 'mod_languages', 'mod_login', 'mod_search', 'mod_finder'], true)) {
                continue;
            }

            $gaps[] = [
                'type' => 'module_all_languages',
                'severity' => 'low',
                'module_id' => (int) $mod['id'],
                'module_title' => $mod['title'],
                'module_type' => $mod['module'],
                'position' => $mod['position'],
                'hint' => "Module \"{$mod['title']}\" ({$mod['module']}) at position \"{$mod['position']}\" is shared across all languages. If it contains text, it may need per-language versions.",
                'fix' => null, // Requires module/translate tool (future)
            ];
        }

        return $gaps;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditLanguageSwitcher(): array
    {
        // Check for mod_languages
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__modules'))
            ->where('module = ' . $this->db->quote('mod_languages'))
            ->where('client_id = 0')
            ->where('published = 1');

        $hasModLanguages = (int) $this->db->setQuery($query)->loadResult() > 0;

        if ($hasModLanguages) {
            return [];
        }

        // Check if YOOtheme has a language switcher in the theme config
        $query = $this->db->getQuery(true)
            ->select('custom_data')
            ->from($this->db->quoteName('#__extensions'))
            ->where('element = ' . $this->db->quote('yootheme'))
            ->where('folder = ' . $this->db->quote('system'));

        $customData = $this->db->setQuery($query)->loadResult();
        $hasYtSwitcher = $customData && str_contains($customData, 'language');

        if ($hasYtSwitcher) {
            return [];
        }

        return [[
            'type' => 'no_language_switcher',
            'severity' => 'high',
            'hint' => 'No language switcher found. Visitors cannot change language. Add mod_languages or configure YOOtheme\'s built-in switcher.',
            'fix' => [
                'tool' => 'site/setup-language-switcher',
                'arguments' => [],
            ],
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditMetadata(string $sourceLang, array $targetLangs): array
    {
        $languages = array_values(array_unique(array_merge([$sourceLang], $targetLangs)));

        $query = $this->db->getQuery(true)
            ->select([
                'c.id', 'c.title', 'c.language', 'c.metadesc',
                $this->db->quoteName('a.key', 'association_key'),
            ])
            ->from($this->db->quoteName('#__content', 'c'))
            ->join(
                'LEFT',
                $this->db->quoteName('#__associations', 'a')
                . ' ON a.id = c.id AND a.context = ' . $this->db->quote('com_content.item')
            )
            ->where('c.state >= 0')
            ->where('c.language IN (' . implode(',', array_map([$this->db, 'quote'], $languages)) . ')');

        $rows = $this->db->setQuery($query)->loadAssocList();

        if ($rows === []) {
            return [];
        }

        $rowsById = [];
        $groupedByAssociation = [];
        $standaloneRows = [];

        foreach ($rows as $row) {
            $row['id'] = (int) $row['id'];
            $row['metadesc'] = is_string($row['metadesc'] ?? null) ? trim((string) $row['metadesc']) : '';
            $rowsById[$row['id']] = $row;

            $associationKey = is_string($row['association_key'] ?? null) ? trim((string) $row['association_key']) : '';

            if ($associationKey === '') {
                $standaloneRows[] = $row;
                continue;
            }

            $groupedByAssociation[$associationKey][] = $row;
        }

        $gaps = [];

        foreach ($groupedByAssociation as $associationKey => $groupRows) {
            $groupGap = $this->buildMetadataGapForAssociationGroup($associationKey, $groupRows, $sourceLang, $targetLangs);

            if ($groupGap !== null) {
                $gaps[] = $groupGap;
            }
        }

        foreach ($standaloneRows as $row) {
            if (!in_array($row['language'], $targetLangs, true) || $row['metadesc'] !== '') {
                continue;
            }

            $gaps[] = [
                'type' => 'meta_empty',
                'severity' => 'low',
                'article_id' => $row['id'],
                'article_title' => $row['title'],
                'language' => $row['language'],
                'field' => 'metadesc',
                'hint' => "Article \"{$row['title']}\" ({$row['language']}) has no meta description.",
                'fix' => null,
            ];
        }

        return $gaps;
    }

    /**
     * @param list<array<string, mixed>> $groupRows
     * @param list<string> $targetLangs
     * @return array<string, mixed>|null
     */
    private function buildMetadataGapForAssociationGroup(string $associationKey, array $groupRows, string $sourceLang, array $targetLangs): ?array
    {
        $rowsByLanguage = [];

        foreach ($groupRows as $row) {
            $language = (string) ($row['language'] ?? '');

            if ($language === '') {
                continue;
            }

            $rowsByLanguage[$language] = $row;
        }

        $sourceRow = $rowsByLanguage[$sourceLang] ?? null;
        $emptyTargets = [];

        foreach ($targetLangs as $targetLang) {
            $targetRow = $rowsByLanguage[$targetLang] ?? null;

            if ($targetRow === null) {
                continue;
            }

            if (($targetRow['metadesc'] ?? '') === '') {
                $emptyTargets[] = $targetRow;
            }
        }

        if ($emptyTargets === []) {
            return null;
        }

        $sourceMetaEmpty = $sourceRow === null || ($sourceRow['metadesc'] ?? '') === '';

        if ($sourceMetaEmpty) {
            $emptyLanguages = [];
            $articleIds = [];

            foreach ($groupRows as $row) {
                if (($row['metadesc'] ?? '') !== '') {
                    continue;
                }

                $emptyLanguages[] = (string) $row['language'];
                $articleIds[] = (int) $row['id'];
            }

            return [
                'type' => 'meta_empty_group',
                'severity' => 'low',
                'association_key' => $associationKey,
                'source_id' => (int) ($sourceRow['id'] ?? 0),
                'source_title' => $sourceRow['title'] ?? '[Unknown source]',
                'languages' => array_values(array_unique($emptyLanguages)),
                'article_ids' => array_values(array_unique($articleIds)),
                'field' => 'metadesc',
                'hint' => sprintf(
                    'The association group for "%s" has no meta description in the source language, so translated articles are empty too.',
                    $sourceRow['title'] ?? '[Unknown source]'
                ),
                'fix' => null,
            ];
        }

        if (count($emptyTargets) === 1) {
            $targetRow = $emptyTargets[0];

            return [
                'type' => 'meta_empty',
                'severity' => 'low',
                'article_id' => (int) $targetRow['id'],
                'article_title' => $targetRow['title'],
                'language' => $targetRow['language'],
                'field' => 'metadesc',
                'hint' => sprintf(
                    'Article "%s" (%s) has no meta description even though the source article "%s" does.',
                    $targetRow['title'],
                    $targetRow['language'],
                    $sourceRow['title'] ?? '[Unknown source]'
                ),
                'fix' => [
                    'tool' => 'content/translate',
                    'arguments' => [
                        'source_id' => (int) $sourceRow['id'],
                        'target_language' => $targetRow['language'],
                        'translated_title' => $targetRow['title'],
                        'translated_metadesc' => '[TRANSLATE META DESCRIPTION]',
                        'overwrite' => true,
                    ],
                ],
            ];
        }

        return [
            'type' => 'meta_empty_translation_group',
            'severity' => 'low',
            'association_key' => $associationKey,
            'source_id' => (int) ($sourceRow['id'] ?? 0),
            'source_title' => $sourceRow['title'] ?? '[Unknown source]',
            'missing_in' => array_values(array_map(
                static fn(array $row): string => (string) $row['language'],
                $emptyTargets
            )),
            'field' => 'metadesc',
            'hint' => sprintf(
                'The source article "%s" has a meta description, but some translated articles in this association group do not.',
                $sourceRow['title'] ?? '[Unknown source]'
            ),
            'fix' => null,
        ];
    }

    /**
     * @param list<string> $targetLangs
     * @return list<array<string, mixed>>
     */
    private function auditTemplates(string $sourceLang, array $targetLangs): array
    {
        $templates = $this->loadYoothemeTemplates();

        if ($templates === []) {
            return [];
        }

        $records = [];

        foreach ($templates as $key => $template) {
            if (!is_array($template)) {
                continue;
            }

            $records[] = [
                'key' => (string) $key,
                'name' => $this->getYoothemeTemplateName($template),
                'type' => is_string($template['type'] ?? null) ? $template['type'] : '',
                'language' => $this->getYoothemeTemplateLanguage($template),
                'has_static_text' => $this->yoothemeTemplateHasStaticText($template),
                'fingerprint' => $this->buildYoothemeTemplateAssignmentFingerprint($template),
            ];
        }

        if ($records === []) {
            return [];
        }

        $groups = [];

        foreach ($records as $record) {
            $groups[$record['fingerprint']][] = $record;
        }

        $gaps = [];

        foreach ($groups as $group) {
            $byLanguage = [];
            $hasWildcard = false;
            $hasStaticWildcard = false;

            foreach ($group as $record) {
                $lang = $record['language'] === '' ? '*' : $record['language'];

                if (isset($byLanguage[$lang])) {
                    $gaps[] = [
                        'type' => 'template_assignment_conflict',
                        'severity' => 'high',
                        'template_keys' => [$byLanguage[$lang]['key'], $record['key']],
                        'language' => $lang,
                        'hint' => 'More than one YOOtheme template shares the same assignment fingerprint and language.',
                        'fix' => null,
                    ];
                    continue 2;
                }

                $byLanguage[$lang] = $record;
                $hasWildcard = $hasWildcard || $lang === '*';
                $hasStaticWildcard = $hasStaticWildcard || ($lang === '*' && $record['has_static_text']);
            }

            if ($hasWildcard && count($group) > 1) {
                $gaps[] = [
                    'type' => 'template_assignment_conflict',
                    'severity' => 'high',
                    'template_keys' => array_map(static fn(array $record): string => $record['key'], $group),
                    'hint' => 'A wildcard YOOtheme template overlaps with language-specific variants for the same assignment.',
                    'fix' => null,
                ];
                continue;
            }

            if ($hasStaticWildcard) {
                /** @var array<string, mixed> $sourceRecord */
                $sourceRecord = $byLanguage['*'];
                $gaps[] = [
                    'type' => 'template_static_text_shared_all_languages',
                    'severity' => 'high',
                    'template_key' => $sourceRecord['key'],
                    'template_name' => $sourceRecord['name'],
                    'template_type' => $sourceRecord['type'],
                    'hint' => 'This template contains fixed text but is still assigned to all languages.',
                    'fix' => [
                        'tool' => 'template/translate',
                        'arguments' => [
                            'key' => $sourceRecord['key'],
                            'target_language' => $targetLangs[0] ?? '',
                            'translated_name' => '[TRANSLATE TEMPLATE]',
                        ],
                    ],
                ];
                continue;
            }

            if ($hasWildcard) {
                continue;
            }

            /** @var array<string, mixed>|null $sourceRecord */
            $sourceRecord = $byLanguage[$sourceLang] ?? null;

            if ($sourceRecord === null) {
                $sourceRecord = reset($group) ?: null;
            }

            if ($sourceRecord === null) {
                continue;
            }

            foreach ($targetLangs as $targetLang) {
                if (isset($byLanguage[$targetLang])) {
                    continue;
                }

                $gaps[] = [
                    'type' => $sourceRecord['has_static_text']
                        ? 'template_language_variant_missing'
                        : 'template_dynamic_only_language_limited',
                    'severity' => $sourceRecord['has_static_text'] ? 'high' : 'medium',
                    'template_key' => $sourceRecord['key'],
                    'template_name' => $sourceRecord['name'],
                    'template_type' => $sourceRecord['type'],
                    'missing_in' => $targetLang,
                    'hint' => $sourceRecord['has_static_text']
                        ? "Template \"{$sourceRecord['name']}\" is missing a translated variant in {$targetLang}."
                        : "Dynamic-only template \"{$sourceRecord['name']}\" is limited to specific languages and has no {$targetLang} variant.",
                    'fix' => [
                        'tool' => 'template/translate',
                        'arguments' => [
                            'key' => $sourceRecord['key'],
                            'target_language' => $targetLang,
                            'translated_name' => $sourceRecord['has_static_text'] ? '[TRANSLATE TEMPLATE]' : '',
                        ],
                    ],
                ];
            }
        }

        return $gaps;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditThemeAreas(): array
    {
        // Check if theme areas (footer, header, etc.) are built with the Builder
        // but not using modules — meaning they can't be translated
        $query = $this->db->getQuery(true)
            ->select('custom_data')
            ->from($this->db->quoteName('#__extensions'))
            ->where('element = ' . $this->db->quote('yootheme'))
            ->where('folder = ' . $this->db->quote('system'));

        $customData = $this->db->setQuery($query)->loadResult();

        if (!$customData) {
            return [];
        }

        $data = json_decode($customData, true);

        if (!$data) {
            return [];
        }

        // Also check template_styles params for Builder areas
        $query = $this->db->getQuery(true)
            ->select('params')
            ->from($this->db->quoteName('#__template_styles'))
            ->where('template = ' . $this->db->quote('yootheme'));

        $params = $this->db->setQuery($query)->loadResult();
        $templateConfig = $params ? json_decode($params, true) : [];

        // Check config for Builder areas
        $config = isset($templateConfig['config']) ? json_decode($templateConfig['config'], true) : [];

        $gaps = [];
        $areas = ['footer', 'header', 'top', 'bottom', 'sidebar'];

        foreach ($areas as $area) {
            // Check if the area has Builder content in the theme config
            $hasBuilderContent = false;

            if (isset($config[$area]) && is_array($config[$area])) {
                $areaJson = json_encode($config[$area]);
                if (str_contains($areaJson, '"children"') || str_contains($areaJson, '"type"')) {
                    $hasBuilderContent = true;
                }
            }

            if (!$hasBuilderContent) {
                continue;
            }

            // Check if there are per-language modules in this position
            $query = $this->db->getQuery(true)
                ->select('COUNT(*)')
                ->from($this->db->quoteName('#__modules'))
                ->where('position = :pos')
                ->where('client_id = 0')
                ->where('published = 1')
                ->where('language != ' . $this->db->quote('*'))
                ->bind(':pos', $area);

            $hasPerLangModules = (int) $this->db->setQuery($query)->loadResult() > 0;

            if (!$hasPerLangModules) {
                $gaps[] = [
                    'type' => 'theme_area_not_translatable',
                    'severity' => 'high',
                    'area' => $area,
                    'hint' => "Theme area \"{$area}\" is built with the YOOtheme Builder but has no per-language modules. Content in this area will show in the source language for all visitors.",
                    'fix' => [
                        'tool' => 'theme/extract-to-modules',
                        'arguments' => ['area' => $area],
                    ],
                ];
            }
        }

        return $gaps;
    }

    private function extractModuleMenutypeForAudit(string $paramsJson): ?string
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

}
