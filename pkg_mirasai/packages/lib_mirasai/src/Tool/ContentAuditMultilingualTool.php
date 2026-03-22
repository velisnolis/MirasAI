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

        $gaps = [];

        // 1. Audit articles
        $gaps = array_merge($gaps, $this->auditArticles($sourceLang, $targetLangs));

        // 2. Audit menus
        $gaps = array_merge($gaps, $this->auditMenus($sourceLang, $targetLangs));

        // 3. Audit categories
        $gaps = array_merge($gaps, $this->auditCategories($sourceLang, $targetLangs));

        // 4. Audit modules
        $gaps = array_merge($gaps, $this->auditModules());

        // 5. Audit language switcher
        $gaps = array_merge($gaps, $this->auditLanguageSwitcher());

        // 6. Audit SEO metadata
        $gaps = array_merge($gaps, $this->auditMetadata($targetLangs));

        // 7. Audit theme areas
        $gaps = array_merge($gaps, $this->auditThemeAreas());

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
        // The source language is the one with the most articles
        $query = $this->db->getQuery(true)
            ->select(['language', 'COUNT(*) AS cnt'])
            ->from($this->db->quoteName('#__content'))
            ->where('state >= 0')
            ->where('language != ' . $this->db->quote('*'))
            ->group('language')
            ->order('cnt DESC');

        $row = $this->db->setQuery($query, 0, 1)->loadAssoc();

        return $row ? $row['language'] : 'ca-ES';
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
    private function auditMetadata(array $targetLangs): array
    {
        $gaps = [];

        foreach ($targetLangs as $targetLang) {
            // Find articles in target language with empty metadesc
            $query = $this->db->getQuery(true)
                ->select(['id', 'title'])
                ->from($this->db->quoteName('#__content'))
                ->where('language = :lang')
                ->where('state >= 0')
                ->where('(metadesc IS NULL OR metadesc = ' . $this->db->quote('') . ')')
                ->bind(':lang', $targetLang);

            $articles = $this->db->setQuery($query)->loadAssocList();

            foreach ($articles as $article) {
                $gaps[] = [
                    'type' => 'meta_empty',
                    'severity' => 'low',
                    'article_id' => (int) $article['id'],
                    'article_title' => $article['title'],
                    'language' => $targetLang,
                    'field' => 'metadesc',
                    'hint' => "Article \"{$article['title']}\" ({$targetLang}) has no meta description.",
                    'fix' => null, // Can be fixed by re-running content/translate with include_meta
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
}
