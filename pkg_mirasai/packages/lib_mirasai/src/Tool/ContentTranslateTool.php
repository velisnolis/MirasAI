<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Joomla\Database\ParameterType;

class ContentTranslateTool extends AbstractTool
{
    public function getName(): string
    {
        return 'content/translate';
    }

    public function getDescription(): string
    {
        return 'Translates an article to a target language. Duplicates the article, sets the target language, creates the language association, and optionally accepts pre-translated content. For YOOtheme articles, the layout structure is preserved — only text nodes are replaced.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'source_id' => [
                    'type' => 'integer',
                    'description' => 'ID of the source article to translate.',
                ],
                'target_language' => [
                    'type' => 'string',
                    'description' => 'Target language code (e.g. es-ES, en-GB).',
                ],
                'translated_title' => [
                    'type' => 'string',
                    'description' => 'Translated article title.',
                ],
                'translated_alias' => [
                    'type' => 'string',
                    'description' => 'URL alias for the translated article. Auto-generated from title if omitted.',
                ],
                'translated_introtext' => [
                    'type' => 'string',
                    'description' => 'Translated introtext HTML. If omitted and article has YOOtheme, introtext is cleared for re-render.',
                ],
                'translated_fulltext' => [
                    'type' => 'string',
                    'description' => 'Translated fulltext/YOOtheme layout JSON. If the article uses YOOtheme Builder, provide the translated layout JSON (the tool wraps it in <!-- --> automatically).',
                ],
                'yootheme_text_replacements' => [
                    'type' => 'object',
                    'description' => 'Alternative to translated_fulltext for YOOtheme articles. Map of "path.field" => "translated text". The tool patches the original layout preserving all structure.',
                    'additionalProperties' => ['type' => 'string'],
                ],
                'target_category_id' => [
                    'type' => 'integer',
                    'description' => 'Category ID for the translated article. If omitted, uses the associated category for the target language.',
                ],
                'translated_metadesc' => [
                    'type' => 'string',
                    'description' => 'Translated meta description for SEO. If omitted, left empty.',
                ],
                'translated_metakey' => [
                    'type' => 'string',
                    'description' => 'Translated meta keywords. If omitted, left empty.',
                ],
                'translated_page_title' => [
                    'type' => 'string',
                    'description' => 'Translated page title (shown in browser tab). If omitted, uses translated_title.',
                ],
                'require_translated_meta_if_source_has_meta' => [
                    'type' => 'boolean',
                    'description' => 'If true, the call fails unless translated_metadesc and/or translated_metakey are provided whenever the source article has those SEO fields filled.',
                ],
                'overwrite' => [
                    'type' => 'boolean',
                    'description' => 'If true, overwrites an existing translation. Default: false (returns error if translation exists).',
                ],
            ],
            'required' => ['source_id', 'target_language', 'translated_title'],
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
        $sourceId = (int) ($arguments['source_id'] ?? 0);
        $targetLang = $arguments['target_language'] ?? '';
        $overwrite = !empty($arguments['overwrite']);

        if ($sourceId <= 0 || $targetLang === '') {
            return ['error' => 'source_id and target_language are required.'];
        }

        // Read source article
        $source = $this->loadArticle($sourceId);

        if (!$source) {
            return ['error' => "Source article {$sourceId} not found."];
        }

        // Check target language exists
        if (!$this->languageExists($targetLang)) {
            return ['error' => "Language {$targetLang} is not installed or not published."];
        }

        // Check for existing translation
        $existing = $this->findTranslation($sourceId, $targetLang);

        if ($existing && !$overwrite) {
            return [
                'error' => "Translation already exists for {$targetLang} (article ID: {$existing}).",
                'existing_id' => $existing,
                'hint' => 'Set overwrite: true to replace the existing translation.',
            ];
        }

        // Determine target category
        $targetCatId = $this->resolveTargetCategory(
            (int) $source['catid'],
            $targetLang,
            $arguments['target_category_id'] ?? null,
        );

        // Build translated content
        $translatedTitle = $arguments['translated_title'];
        $translatedAlias = $arguments['translated_alias']
            ?? $this->generateAlias($translatedTitle);

        $metaValidation = $this->validateTranslatedMetaRequirements($source, $arguments);

        if ($metaValidation !== null) {
            return $metaValidation;
        }

        if ((new YooThemeLayoutProcessor())->detectLayout((string) ($source['fulltext'] ?? ''))
            && empty($arguments['translated_fulltext'])
            && empty($arguments['yootheme_text_replacements'])) {
            return [
                'error' => 'YOOtheme articles require translated_fulltext or yootheme_text_replacements. Refusing to copy the source layout unchanged.',
            ];
        }

        $translatedIntrotext = $arguments['translated_introtext'] ?? '';
        $translatedFulltext = $this->buildTranslatedFulltext(
            $source,
            $arguments,
        );

        // SEO metadata
        $metadesc = $arguments['translated_metadesc'] ?? '';
        $metakey = $arguments['translated_metakey'] ?? '';

        if ($existing && $overwrite) {
            // Update existing
            $updateFields = [
                'title' => $translatedTitle,
                'alias' => $translatedAlias,
                'introtext' => $translatedIntrotext,
                'fulltext' => $translatedFulltext,
                'catid' => $targetCatId,
            ];

            if ($metadesc !== '') {
                $updateFields['metadesc'] = $metadesc;
            }

            if ($metakey !== '') {
                $updateFields['metakey'] = $metakey;
            }

            $this->updateArticle($existing, $updateFields);
            $this->ensureWorkflowAssociation($existing, 'com_content.article', $sourceId);

            $introtextResult = $this->regenerateIntrotext($existing);
            $linkWarnings = $this->checkInternalLinks($existing, $targetLang);

            $result = [
                'action' => 'updated',
                'article_id' => $existing,
                'source_id' => $sourceId,
                'target_language' => $targetLang,
                'title' => $translatedTitle,
                'introtext_regenerated' => $introtextResult,
            ];

            if (!empty($linkWarnings)) {
                $result['link_warnings'] = $linkWarnings;
            }

            return $result;
        }

        // Create new article
        $overrides = [
            'title' => $translatedTitle,
            'alias' => $translatedAlias,
            'language' => $targetLang,
            'introtext' => $translatedIntrotext,
            'fulltext' => $translatedFulltext,
            'catid' => $targetCatId,
        ];

        if ($metadesc !== '') {
            $overrides['metadesc'] = $metadesc;
        }

        if ($metakey !== '') {
            $overrides['metakey'] = $metakey;
        }

        $newId = $this->duplicateArticle($source, $overrides);

        // Create association
        $this->createAssociation($sourceId, $newId);
        $this->ensureWorkflowAssociation($newId, 'com_content.article', $sourceId);

        // Create menu item if the source article has one
        $menuResult = $this->createMenuItemForTranslation(
            $sourceId,
            $newId,
            $targetLang,
            $translatedTitle,
            $translatedAlias,
        );

        // Update menu item page_title if provided
        $pageTitle = $arguments['translated_page_title'] ?? '';
        if ($pageTitle !== '' && isset($menuResult['menu_item_id'])) {
            $this->updateMenuItemPageTitle($menuResult['menu_item_id'], $pageTitle);
        }

        // Regenerate introtext via YOOtheme Builder if article has YOOtheme layout
        $introtextResult = $this->regenerateIntrotext($newId);

        // Check for internal links without translated destinations
        $linkWarnings = $this->checkInternalLinks($newId, $targetLang);

        $result = [
            'action' => 'created',
            'article_id' => $newId,
            'source_id' => $sourceId,
            'target_language' => $targetLang,
            'title' => $translatedTitle,
            'menu_item' => $menuResult,
            'introtext_regenerated' => $introtextResult,
        ];

        if (!empty($linkWarnings)) {
            $result['link_warnings'] = $linkWarnings;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|null
     */
    private function validateTranslatedMetaRequirements(array $source, array $arguments): ?array
    {
        if (empty($arguments['require_translated_meta_if_source_has_meta'])) {
            return null;
        }

        $missing = [];
        $sourceHasMetaDesc = trim((string) ($source['metadesc'] ?? '')) !== '';
        $sourceHasMetaKey = trim((string) ($source['metakey'] ?? '')) !== '';

        if ($sourceHasMetaDesc && trim((string) ($arguments['translated_metadesc'] ?? '')) === '') {
            $missing[] = 'translated_metadesc';
        }

        if ($sourceHasMetaKey && trim((string) ($arguments['translated_metakey'] ?? '')) === '') {
            $missing[] = 'translated_metakey';
        }

        if ($missing === []) {
            return null;
        }

        return [
            'error' => 'Source article has SEO metadata. Provide translated values for the required SEO fields or disable require_translated_meta_if_source_has_meta.',
            'missing_fields' => $missing,
            'source_has_metadesc' => $sourceHasMetaDesc,
            'source_has_metakey' => $sourceHasMetaKey,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadArticle(int $id): ?array
    {
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__content'))
            ->where('id = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        return $this->db->setQuery($query)->loadAssoc();
    }


    // findTranslation() is now in AbstractTool

    private function resolveTargetCategory(int $sourceCatId, string $targetLang, ?int $explicit): int
    {
        if ($explicit) {
            return $explicit;
        }

        // Try to find associated category in target language
        $query = $this->db->getQuery(true)
            ->select('a2.id')
            ->from($this->db->quoteName('#__associations', 'a1'))
            ->join('INNER', $this->db->quoteName('#__associations', 'a2')
                . ' ON a1.' . $this->db->quoteName('key') . ' = a2.' . $this->db->quoteName('key')
                . ' AND a2.id != a1.id')
            ->join('INNER', $this->db->quoteName('#__categories', 'cat') . ' ON cat.id = a2.id')
            ->where('a1.context = ' . $this->db->quote('com_categories.item'))
            ->where('a2.context = ' . $this->db->quote('com_categories.item'))
            ->where('a1.id = :catid')
            ->where('cat.language = :lang')
            ->bind(':catid', $sourceCatId, ParameterType::INTEGER)
            ->bind(':lang', $targetLang);

        $result = $this->db->setQuery($query)->loadResult();

        return $result ? (int) $result : $sourceCatId;
    }

    /**
     * @param  array<string, mixed> $source
     * @param  array<string, mixed> $arguments
     */
    private function buildTranslatedFulltext(array $source, array $arguments): string
    {
        // Option 1: Direct translated_fulltext provided
        if (!empty($arguments['translated_fulltext'])) {
            $ft = $arguments['translated_fulltext'];

            // If it's raw JSON (not wrapped in comment), wrap it
            if (str_starts_with(trim($ft), '{')) {
                return '<!-- ' . $ft . ' -->';
            }

            return $ft;
        }

        // Option 2: YOOtheme text replacements
        if (!empty($arguments['yootheme_text_replacements'])) {
            return (new YooThemeLayoutProcessor())->replaceText(
                $source['fulltext'] ?? '',
                $arguments['yootheme_text_replacements'],
            );
        }

        // Fallback: copy source fulltext
        return $source['fulltext'] ?? '';
    }

    /**
     * @param  array<string, mixed> $source
     * @param  array<string, mixed> $overrides
     */
    /** @var list<string> */
    private const NULLABLE_COLUMNS = [
        'publish_up', 'publish_down', 'checked_out', 'checked_out_time',
    ];

    /**
     * @param  array<string, mixed> $source
     * @param  array<string, mixed> $overrides
     */
    private function duplicateArticle(array $source, array $overrides): int
    {
        $fields = array_merge($source, $overrides);
        unset($fields['id'], $fields['asset_id'], $fields['checked_out'], $fields['checked_out_time']);

        $fields['created'] = date('Y-m-d H:i:s');
        $fields['modified'] = date('Y-m-d H:i:s');
        $fields['hits'] = 0;
        $fields['version'] = 1;

        $columns = [];
        $values = [];

        foreach ($fields as $col => $val) {
            if ($val === null || ($val === '' && in_array($col, self::NULLABLE_COLUMNS, true))) {
                $columns[] = $this->db->quoteName($col);
                $values[] = 'NULL';
            } else {
                $columns[] = $this->db->quoteName($col);
                $values[] = $this->db->quote((string) $val);
            }
        }

        $query = 'INSERT INTO ' . $this->db->quoteName('#__content')
            . ' (' . implode(',', $columns) . ')'
            . ' VALUES (' . implode(',', $values) . ')';

        $this->db->setQuery($query)->execute();

        $newId = (int) $this->db->insertid();

        // Create asset for the new article (required for Joomla ACL)
        $this->createAssetForContent($newId, $overrides['title'] ?? 'Untitled');

        return $newId;
    }

    // createAsset → now createAssetForContent in AbstractTool

    private function updateArticle(int $id, array $fields): void
    {
        $fields['modified'] = date('Y-m-d H:i:s');
        $sets = [];

        foreach ($fields as $col => $val) {
            $sets[] = $this->db->quoteName($col) . ' = ' . $this->db->quote((string) ($val ?? ''));
        }

        $query = 'UPDATE ' . $this->db->quoteName('#__content')
            . ' SET ' . implode(', ', $sets)
            . ' WHERE id = ' . (int) $id;

        $this->db->setQuery($query)->execute();
    }

    // createAssociation → now in AbstractTool (with context parameter)

    /**
     * Regenerate introtext by calling the YOOtheme Builder via the standalone script.
     *
     * @return array{status: string, introtext_length?: int, message?: string}
     */
    private function regenerateIntrotext(int $articleId): array
    {
        $script = JPATH_ROOT . '/regenerate-introtext.php';

        if (!file_exists($script)) {
            return ['status' => 'skipped', 'message' => 'regenerate-introtext.php not found'];
        }

        $cmd = sprintf(
            'cd %s && php %s %d 2>&1',
            escapeshellarg(JPATH_ROOT),
            escapeshellarg($script),
            $articleId,
        );

        $output = shell_exec($cmd);

        if (!$output) {
            return ['status' => 'error', 'message' => 'No output from regenerate script'];
        }

        $result = json_decode($output, true);

        if (!$result || !isset($result['results'][0])) {
            return ['status' => 'error', 'message' => 'Invalid output: ' . substr($output, 0, 200)];
        }

        return $result['results'][0];
    }

    /**
     * Scan a translated article for internal links pointing to articles
     * that don't have a translation in the target language yet.
     *
     * @return list<array{type: string, target_article_id: int, target_title: string, link: string}>
     */
    private function checkInternalLinks(int $articleId, string $targetLang): array
    {
        // Load the translated article content
        $query = $this->db->getQuery(true)
            ->select(['introtext', $this->db->quoteName('fulltext', 'fulltext_raw')])
            ->from($this->db->quoteName('#__content'))
            ->where('id = :id')
            ->bind(':id', $articleId, ParameterType::INTEGER);

        $row = $this->db->setQuery($query)->loadAssoc();

        if (!$row) {
            return [];
        }

        // Collect all referenced article IDs from the content
        $referencedIds = [];

        // 1. Scan HTML links in introtext and fulltext
        $htmlContent = ($row['introtext'] ?? '') . ' ' . ($row['fulltext_raw'] ?? '');
        $this->extractArticleIdsFromHtml($htmlContent, $referencedIds);

        // 2. Scan YOOtheme layout JSON for link props
        $fulltext = trim($row['fulltext_raw'] ?? '');
        if (str_starts_with($fulltext, '<!-- {')) {
            $end = strrpos($fulltext, ' -->');
            if ($end !== false) {
                $json = substr($fulltext, 5, $end - 5);
                $layout = json_decode($json, true);
                if ($layout) {
                    $this->extractArticleIdsFromLayout($layout, $referencedIds);
                }
            }
        }

        if (empty($referencedIds)) {
            return [];
        }

        // Remove the article itself and deduplicate
        $referencedIds = array_unique($referencedIds);
        $referencedIds = array_filter($referencedIds, fn($id) => $id !== $articleId);

        if (empty($referencedIds)) {
            return [];
        }

        // Check which referenced articles have a translation in the target language
        $warnings = [];

        foreach ($referencedIds as $refId) {
            $translation = $this->findTranslation($refId, $targetLang);

            if ($translation !== null) {
                continue; // Has translation — OK
            }

            // Get the title of the untranslated article
            $query = $this->db->getQuery(true)
                ->select(['title', 'language'])
                ->from($this->db->quoteName('#__content'))
                ->where('id = :id')
                ->bind(':id', $refId, ParameterType::INTEGER);

            $ref = $this->db->setQuery($query)->loadAssoc();

            if ($ref) {
                $warnings[] = [
                    'type' => 'missing_translation',
                    'target_article_id' => $refId,
                    'target_title' => $ref['title'],
                    'target_language' => $ref['language'],
                    'missing_in' => $targetLang,
                    'hint' => "Article \"{$ref['title']}\" (ID:{$refId}) has no {$targetLang} translation. Links to it will 404.",
                ];
            }
        }

        return $warnings;
    }

    /**
     * Extract article IDs from HTML href attributes.
     *
     * @param  list<int> &$ids
     */
    private function extractArticleIdsFromHtml(string $html, array &$ids): void
    {
        // Match Joomla SEF URLs like /ca/coneix-nos or /es/conocenos
        // and non-SEF like index.php?option=com_content&view=article&id=2
        if (preg_match_all('/[?&]id=(\d+)/', $html, $matches)) {
            foreach ($matches[1] as $id) {
                $ids[] = (int) $id;
            }
        }

        // Match menu item links (Itemid)
        if (preg_match_all('/Itemid=(\d+)/', $html, $matches)) {
            foreach ($matches[1] as $itemId) {
                $articleId = $this->getArticleIdFromMenuItem((int) $itemId);
                if ($articleId) {
                    $ids[] = $articleId;
                }
            }
        }
    }

    /**
     * Extract article IDs from YOOtheme layout link props.
     *
     * @param  array<string, mixed> $node
     * @param  list<int>            &$ids
     */
    private function extractArticleIdsFromLayout(array $node, array &$ids): void
    {
        $props = $node['props'] ?? [];

        // Check link-related props
        foreach (['link', 'button_link', 'image_link', 'title_link', 'href'] as $prop) {
            $val = $props[$prop] ?? null;
            if (is_string($val) && $val !== '') {
                $this->extractArticleIdsFromHtml($val, $ids);
            }
        }

        // Recurse into children
        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $this->extractArticleIdsFromLayout($child, $ids);
            }
        }
    }

    private function getArticleIdFromMenuItem(int $menuItemId): ?int
    {
        $query = $this->db->getQuery(true)
            ->select('link')
            ->from($this->db->quoteName('#__menu'))
            ->where('id = :id')
            ->bind(':id', $menuItemId, ParameterType::INTEGER);

        $link = $this->db->setQuery($query)->loadResult();

        if ($link && preg_match('/[?&]id=(\d+)/', $link, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Creates a menu item for the translated article if the source article has one.
     *
     * @return array{action: string, menu_item_id?: int, source_menu_item_id?: int, note?: string}
     */
    private function createMenuItemForTranslation(
        int $sourceArticleId,
        int $newArticleId,
        string $targetLang,
        string $title,
        string $alias,
    ): array {
        // Find menu item(s) pointing to the source article (include unpublished/hidden)
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__menu'))
            ->where('link LIKE ' . $this->db->quote('%option=com_content&view=article&id=' . $sourceArticleId))
            ->where('client_id = 0')
            ->where('published >= 0');  // include unpublished (0) but not trashed (-2)

        $sourceMenuItem = $this->db->setQuery($query)->loadAssoc();

        if (!$sourceMenuItem) {
            return [
                'action' => 'skipped',
                'note' => 'Source article has no menu item. No menu item created for translation.',
            ];
        }

        // Find the target menu type for this language
        $targetMenuType = $this->findMenuTypeForLanguage($targetLang);

        if (!$targetMenuType) {
            return [
                'action' => 'skipped',
                'note' => "No menu found for language {$targetLang}. Create a menu for this language first.",
            ];
        }

        // Check if a menu item already exists for this translated article (by article link).
        $query = $this->db->getQuery(true)
            ->select('id')
            ->from($this->db->quoteName('#__menu'))
            ->where('link LIKE ' . $this->db->quote('%option=com_content&view=article&id=' . $newArticleId))
            ->where('client_id = 0');

        $existingMenuItemId = $this->db->setQuery($query)->loadResult();

        if ($existingMenuItemId) {
            return [
                'action' => 'exists',
                'menu_item_id' => (int) $existingMenuItemId,
                'note' => 'Menu item already exists for this translated article.',
            ];
        }

        // Check if a menu item with the same alias already exists in the target language.
        // This can happen when a previously existing article was deleted from #__content
        // but its menu item was left behind (orphaned). Reuse it by updating its link.
        $query = $this->db->getQuery(true)
            ->select('id')
            ->from($this->db->quoteName('#__menu'))
            ->where('alias = ' . $this->db->quote($alias))
            ->where('language = ' . $this->db->quote($targetLang))
            ->where('client_id = 0')
            ->where('menutype = ' . $this->db->quote($targetMenuType));

        $orphanedMenuItemId = (int) ($this->db->setQuery($query)->loadResult() ?: 0);

        if ($orphanedMenuItemId) {
            // Reuse the orphaned item: point it to the new article.
            $link = 'index.php?option=com_content&view=article&id=' . $newArticleId;
            $updateQuery = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__menu'))
                ->set('link = ' . $this->db->quote($link))
                ->set('title = ' . $this->db->quote($title))
                ->where('id = ' . $orphanedMenuItemId);
            $this->db->setQuery($updateQuery)->execute();

            // Remove any stale association before creating the new one.
            $delAssocQuery = $this->db->getQuery(true)
                ->delete($this->db->quoteName('#__associations'))
                ->where('context = ' . $this->db->quote('com_menus.item'))
                ->where('id = ' . $orphanedMenuItemId);
            $this->db->setQuery($delAssocQuery)->execute();

            $this->createMenuAssociation((int) $sourceMenuItem['id'], $orphanedMenuItemId);

            return [
                'action' => 'reused_orphan',
                'menu_item_id' => $orphanedMenuItemId,
                'source_menu_item_id' => (int) $sourceMenuItem['id'],
                'note' => 'Reused existing orphaned menu item (same alias, updated link to new article).',
            ];
        }

        // Create a new menu item using Joomla\CMS\Table\Menu to preserve nested set integrity.
        $link = 'index.php?option=com_content&view=article&id=' . $newArticleId;

        /** @var \Joomla\CMS\Table\Menu $menuTable */
        $menuTable = \Joomla\CMS\Table\Table::getInstance('Menu', 'JTable', ['dbo' => $this->db]);

        $menuTable->menutype          = $targetMenuType;
        $menuTable->title             = $title;
        $menuTable->alias             = $alias;
        $menuTable->path              = $alias;
        $menuTable->link              = $link;
        $menuTable->type              = $sourceMenuItem['type'] ?: 'component';
        $menuTable->published         = (int) $sourceMenuItem['published'];
        $menuTable->component_id      = (int) $sourceMenuItem['component_id'];
        $menuTable->access            = (int) $sourceMenuItem['access'];
        $menuTable->params            = $sourceMenuItem['params'] ?: '{}';
        $menuTable->home              = (int) $sourceMenuItem['home'];
        $menuTable->language          = $targetLang;
        $menuTable->client_id         = 0;
        $menuTable->note              = $sourceMenuItem['note'] ?? '';
        $menuTable->img               = $sourceMenuItem['img'] ?? '';
        $menuTable->template_style_id = (int) ($sourceMenuItem['template_style_id'] ?? 0);
        $menuTable->browserNav        = (int) ($sourceMenuItem['browserNav'] ?? 0);

        // Find the root item for the target menu to use as parent.
        $rootQuery = $this->db->getQuery(true)
            ->select('id')
            ->from($this->db->quoteName('#__menu'))
            ->where('menutype = ' . $this->db->quote($targetMenuType))
            ->where('parent_id = 1')
            ->where('client_id = 0')
            ->setLimit(1);

        $parentMenuId = (int) ($this->db->setQuery($rootQuery)->loadResult() ?: 1);
        $menuTable->setLocation($parentMenuId, 'last-child');

        $menuTable->store();

        $newMenuItemId = (int) $menuTable->id;

        // Create menu item association
        $this->createMenuAssociation((int) $sourceMenuItem['id'], $newMenuItemId);

        return [
            'action' => 'created',
            'menu_item_id' => $newMenuItemId,
            'source_menu_item_id' => (int) $sourceMenuItem['id'],
        ];
    }

    /**
     * Find the menu type (menutype string) that contains home items for a given language.
     */
    private function findMenuTypeForLanguage(string $targetLang): ?string
    {
        // Look for a menu that has a default (home) item for this language
        $query = $this->db->getQuery(true)
            ->select('menutype')
            ->from($this->db->quoteName('#__menu'))
            ->where('home = 1')
            ->where('language = :lang')
            ->where('client_id = 0')
            ->where('published = 1')
            ->bind(':lang', $targetLang);

        return $this->db->setQuery($query)->loadResult() ?: null;
    }

    private function updateMenuItemPageTitle(int $menuItemId, string $pageTitle): void
    {
        $query = $this->db->getQuery(true)
            ->select('params')
            ->from($this->db->quoteName('#__menu'))
            ->where('id = :id')
            ->bind(':id', $menuItemId, ParameterType::INTEGER);

        $paramsJson = $this->db->setQuery($query)->loadResult();
        $params = $paramsJson ? json_decode($paramsJson, true) : [];

        if (!is_array($params)) {
            $params = [];
        }

        $params['page_title'] = $pageTitle;

        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__menu'))
            ->set($this->db->quoteName('params') . ' = ' . $this->db->quote(json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))
            ->where('id = :id')
            ->bind(':id', $menuItemId, ParameterType::INTEGER);

        $this->db->setQuery($query)->execute();
    }

    private function createMenuAssociation(int $sourceMenuItemId, int $newMenuItemId): void
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('key'))
            ->from($this->db->quoteName('#__associations'))
            ->where('context = ' . $this->db->quote('com_menus.item'))
            ->where('id = :id')
            ->bind(':id', $sourceMenuItemId, ParameterType::INTEGER);

        $existingKey = $this->db->setQuery($query)->loadResult();

        if (!$existingKey) {
            $existingKey = 'mirasai_menu_' . $sourceMenuItemId . '_' . time();

            $query = $this->db->getQuery(true)
                ->insert($this->db->quoteName('#__associations'))
                ->columns(['context', 'id', $this->db->quoteName('key')])
                ->values(
                    $this->db->quote('com_menus.item') . ','
                    . $sourceMenuItemId . ','
                    . $this->db->quote($existingKey)
                );

            $this->db->setQuery($query)->execute();
        }

        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__associations'))
            ->columns(['context', 'id', $this->db->quoteName('key')])
            ->values(
                $this->db->quote('com_menus.item') . ','
                . $newMenuItemId . ','
                . $this->db->quote($existingKey)
            );

        $this->db->setQuery($query)->execute();
    }

    // generateAlias → now in AbstractTool
}
