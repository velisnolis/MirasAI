<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Joomla\Database\ParameterType;

class ContentTranslateTool extends AbstractTool
{
    private array $translationEffects = [];
    public function getName(): string
    {
        return 'content/translate';
    }

    public function getDescription(): string
    {
        return 'Creates or updates a translated version of an article. YOU must provide the translated content — '
            . 'this tool does NOT auto-translate. For standard articles: provide translated_title + translated_introtext '
            . '(and translated_fulltext if the source has fulltext). For YOOtheme Builder articles: provide translated_title + '
            . 'yootheme_text_replacements (a map of replacement_key → translated text, obtained from content/read). '
            . 'Defaults to preview. Apply requires the preview if_match and confirm_guarded_write. New articles are unpublished; menus require explicit selection.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'dry_run' => ['type' => 'boolean', 'default' => true, 'description' => 'Preview destinations and return an ETag without writing.'],
                'if_match' => ['type' => 'string', 'description' => 'ETag of the identical content/translate preview, including source, target and context. Required on apply.'],
                'confirm_guarded_write' => ['type' => 'boolean', 'description' => 'Required true with dry_run=false.'],
                'target_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Explicit existing target article in target_language; requires overwrite. Refuses conflicting associations.'],
                'state' => ['type' => 'integer', 'enum' => [-2, 0, 1, 2], 'description' => 'New default 0; updates preserve current state. Publication requires verified fallback and parity.'],
                'create_menu' => ['type' => 'boolean', 'default' => false, 'description' => 'Create a menu explicitly; mutually exclusive with menu_id.'],
                'menu_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Explicit target-language menu to repoint, preserving alias, access and structure.'],
                'source_menu_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Choose the source menu when more than one points to the source article.'],
                'target_menutype' => ['type' => 'string', 'description' => 'Existing site menu type for create_menu.'],
                'target_parent_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Parent for a new menu; defaults to Joomla menu root (1).'],
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
                    'description' => 'New articles derive alias from title; existing targets preserve alias when omitted.',
                ],
                'translated_introtext' => [
                    'type' => 'string',
                    'description' => 'Translated introtext HTML for standard articles. Builder fallback is rendered in-process; an unverified fallback permits only a partial unpublished save.',
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
                    'description' => 'Translated meta description for SEO. If omitted, new articles use empty text and existing targets preserve their value.',
                ],
                'translated_metakey' => [
                    'type' => 'string',
                    'description' => 'Translated meta keywords. If omitted, new articles use empty text and existing targets preserve their value.',
                ],
                'translated_page_title' => [
                    'type' => 'string',
                    'description' => 'Explicit browser page title for the selected menu. Requires create_menu=true or menu_id; existing menu parameters are otherwise preserved.',
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
            'risk_level' => self::RISK_GUARDED_WRITE,
            'idempotent' => false,
        ];
    }

    public function handle(array $arguments): array
    {
        $dryRun = ($arguments['dry_run'] ?? true) === true;
        $inTransaction = false;
        $articleId = null;
        $lockName = null;
        $this->translationEffects = [];
        $effects = &$this->translationEffects;
        try {
            if (!$dryRun) {
                if (($arguments['confirm_guarded_write'] ?? false) !== true || empty($arguments['if_match'])) {
                    return ['error' => 'Preview first; apply requires confirm_guarded_write=true and the preview if_match.', 'code' => 'confirmation_required', 'write_performed' => false];
                }
                $lockName = $this->acquireTranslationLock((int) ($arguments['source_id'] ?? 0));
                $this->beginTranslation();
                $inTransaction = true;
            }
            $plan = $this->planTranslation($arguments, !$dryRun);
            if ($dryRun) {
                return ['dry_run' => true, 'write_performed' => false, 'etag' => $plan['etag'], 'preview' => $plan['preview']];
            }
            if (!hash_equals($plan['etag'], (string) $arguments['if_match'])) {
                throw new \InvalidArgumentException('stale_etag: source, target, associations, menu, category or request changed. Preview again.');
            }
            $fields = $plan['fields'];
            $fallback = ['status' => 'not_required'];
            if ($plan['builder']) {
                $rendered = $this->renderTranslation($fields['fulltext'], $fields['language']);
                $fallback = $rendered['result'];
                if (($fallback['status'] ?? '') === 'success') {
                    $fields['introtext'] = $rendered['introtext'];
                    $fields['fulltext'] = $rendered['fulltext'];
                }
            }
            $partial = $plan['parity_warnings'] !== [] || !in_array($fallback['status'], ['success', 'not_required'], true);
            if ((int) $fields['state'] === 1 && $partial) {
                throw new \InvalidArgumentException('publication_unverified: fallback or editorial parity requires review. Save explicitly with state=0, then reconcile before publishing.');
            }
            $articleId = $plan['target']['id'] ?? null;
            $effects['article'] = ['id' => $articleId, 'action' => $plan['target'] ? 'update_attempted' : 'insert_attempted'];
            if ($articleId) {
                $articleId = (int) $articleId;
                $this->updateArticle($articleId, $fields);
            } else {
                $articleId = $this->duplicateArticle($plan['source'], $fields);
            }
            $effects['article'] = ['id' => $articleId, 'action' => $plan['target'] ? 'updated' : 'created'];
            $effects['fallback'] = $fallback;
            $effects['associations'] = ['status' => 'attempted', 'source_id' => (int) $plan['source']['id'], 'target_id' => $articleId];
            if (!$plan['associated']) {
                $this->createAssociation((int) $plan['source']['id'], $articleId);
            }
            $effects['associations'] = ['source_id' => (int) $plan['source']['id'], 'target_id' => $articleId];
            $this->ensureWorkflowAssociation($articleId, 'com_content.article');
            $effects['workflow'] = ['status' => 'checked'];
            // Joomla nested-set Table locks can commit implicitly. Persist the association
            // before asset/menu Table writes so a partial create remains discoverable on retry.
            if (!$plan['target'] || (int) ($plan['target']['asset_id'] ?? 0) <= 0) {
                $this->createAssetForContent($articleId, $fields['title']);
                if ((int) ($this->loadArticle($articleId)['asset_id'] ?? 0) <= 0) {
                    throw new \RuntimeException('Article asset creation could not be verified.');
                }
                $effects['asset'] = ['status' => 'verified', 'id' => (int) $this->loadArticle($articleId)['asset_id']];
            }

            $effects['menu'] = $this->applyTranslationMenu($plan['menu'], $articleId, $fields, $arguments);
            $this->commitTranslation();
            $inTransaction = false;
            $stored = $this->loadArticle($articleId);
            foreach ($fields as $field => $expected) {
                if (!$stored || (string) ($stored[$field] ?? '') !== (string) $expected) {
                    throw new \RuntimeException('Post-write verification failed for article field: ' . $field);
                }
            }
            $effects['verification'] = ['article_fields' => 'verified_after_commit'];
            return [
                'action' => $plan['target'] ? 'updated' : 'created', 'article_id' => $articleId,
                'source_id' => (int) $plan['source']['id'], 'target_language' => $fields['language'],
                'title' => $fields['title'], 'state' => (int) $fields['state'],
                'status' => $partial ? 'partial' : 'complete', 'write_performed' => true,
                'effects' => $effects, 'menu_item' => $effects['menu'], 'introtext_regenerated' => $fallback,
                'parity_warnings' => $plan['parity_warnings'],
                'recovery' => $partial ? 'Keep article_id. Review missing parity/fallback; update this target with a fresh preview. Never repeat creation blindly.' : null,
            ];
        } catch (\Throwable $e) {
            $rollback = 'not_needed';
            if ($inTransaction) {
                try { $this->rollbackTranslation(); $rollback = 'requested'; }
                catch (\Throwable $ignored) { $rollback = 'failed'; }
            }
            // An attempted Table write or failed COMMIT is not proof of rollback.
            $persisted = null;
            if ($articleId !== null) {
                try { $persisted = $this->loadArticle($articleId) !== null; }
                catch (\Throwable $ignored) { /* Unknown outcome remains explicit. */ }
            }
            return [
                'error' => $e->getMessage(), 'code' => str_starts_with($e->getMessage(), 'stale_etag:') ? 'stale_etag' : 'translation_failed',
                'status' => $effects === [] ? 'rejected' : 'needs_reconciliation',
                'article_id' => $articleId, 'article_exists_after_error' => $persisted,
                'attempted_effects' => $effects, 'rollback' => $rollback,
                'write_performed' => $effects === [] ? false : null,
                'recovery' => $effects === [] ? null : 'Read the returned IDs, associations and menu before retrying. A rollback request does not prove all side effects were undone.',
            ];
        } finally {
            if ($lockName !== null) {
                try { $this->releaseTranslationLock($lockName); }
                catch (\Throwable $ignored) { /* MySQL releases it when the connection closes. */ }
            }
        }
    }

    /** Serialize this tool's writers independently of Table's implicit commits. */
    protected function acquireTranslationLock(int $sourceId): string
    {
        $database = (string) $this->db->setQuery('SELECT DATABASE()')->loadResult();
        $name = 'mirasai-translate-' . substr(hash('sha256', $database . ':' . $this->db->replacePrefix('#__content') . ':' . $sourceId), 0, 44);
        if ((int) $this->db->setQuery('SELECT GET_LOCK(' . $this->db->quote($name) . ', 0)')->loadResult() !== 1) {
            throw new \RuntimeException('Translation for this source is busy. Wait, then obtain a fresh preview.');
        }
        return $name;
    }

    protected function releaseTranslationLock(string $name): void
    {
        $this->db->setQuery('SELECT RELEASE_LOCK(' . $this->db->quote($name) . ')')->loadResult();
    }

    protected function beginTranslation(): void
    {
        $tables = array_map(fn($name) => $this->db->quote($this->db->replacePrefix('#__' . $name)),
            ['content', 'assets', 'associations', 'menu', 'workflow_associations']);
        $engines = $this->db->setQuery('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
            . ' AND TABLE_NAME IN (' . implode(',', $tables) . ')')->loadColumn();
        if (count($engines) !== count($tables) || array_filter($engines, static fn($engine) => strcasecmp((string) $engine, 'InnoDB') !== 0)) {
            throw new \RuntimeException('Translation apply requires InnoDB for all affected Joomla tables.');
        }
        $this->db->transactionStart();
    }
    protected function commitTranslation(): void { $this->db->transactionCommit(); }
    protected function rollbackTranslation(): void { $this->db->transactionRollback(); }

    /** Resolve every write destination before mutation. Apply resolves it under row locks. */
    protected function planTranslation(array $arguments, bool $lock): array
    {
        $sourceId = (int) ($arguments['source_id'] ?? 0);
        $lang = (string) ($arguments['target_language'] ?? '');
        $title = trim((string) ($arguments['translated_title'] ?? ''));
        $source = $this->loadArticle($sourceId, $lock);
        if (!$source || $title === '' || !$this->languageExists($lang)) {
            throw new \InvalidArgumentException('Valid source_id, translated_title and published target_language are required.');
        }
        if (in_array($source['language'], ['*', $lang], true)) {
            throw new \InvalidArgumentException('Source must have a specific language different from target_language.');
        }
        $group = $this->translationAssociationGroup($sourceId, 'com_content.item', $lock);
        $matches = array_values(array_filter($group, static fn($row) => ($row['language'] ?? null) === $lang));
        if (count($matches) > 1) { throw new \InvalidArgumentException('Ambiguous target association. Repair the group first.'); }
        $associatedId = (int) ($matches[0]['id'] ?? 0);
        $targetId = (int) ($arguments['target_id'] ?? $associatedId);
        if ($associatedId && $targetId !== $associatedId) {
            throw new \InvalidArgumentException('target_id conflicts with the existing language association.');
        }
        $target = $targetId ? $this->loadArticle($targetId, $lock) : null;
        if ($targetId && (!$target || $target['language'] !== $lang || $targetId === $sourceId)) {
            throw new \InvalidArgumentException('target_id must be an existing article in target_language.');
        }
        if ($target && empty($arguments['overwrite'])) {
            throw new \InvalidArgumentException('An existing target requires overwrite=true.');
        }
        $targetGroup = $target ? $this->translationAssociationGroup($targetId, 'com_content.item', $lock) : [];
        if ($targetGroup !== [] && !$associatedId) {
            throw new \InvalidArgumentException('target_id belongs to another association group. Groups are never merged implicitly.');
        }
        $state = $arguments['state'] ?? (int) ($target['state'] ?? 0);
        if (!is_int($state) || !in_array($state, [-2, 0, 1, 2], true)) {
            throw new \InvalidArgumentException('state must be one of -2, 0, 1, 2.');
        }
        $categoryId = isset($arguments['target_category_id']) ? (int) $arguments['target_category_id']
            : ($target ? (int) $target['catid'] : $this->resolveTargetCategory((int) $source['catid'], $lang, null));
        $category = $this->translationRow('#__categories', $categoryId, $lock);
        if (!$category || $category['extension'] !== 'com_content' || !in_array($category['language'], ['*', $lang], true)) {
            throw new \InvalidArgumentException('Choose a content category in target_language or shared language *.');
        }
        $metaValidation = $this->validateTranslatedMetaRequirements($source, $arguments);
        if ($metaValidation !== null) { throw new \InvalidArgumentException($metaValidation['error']); }
        $processor = new YooThemeLayoutProcessor();
        $builder = $processor->detectLayout((string) ($source['fulltext'] ?? ''));
        if ($builder && empty($arguments['translated_fulltext']) && empty($arguments['yootheme_text_replacements'])) {
            throw new \InvalidArgumentException('Builder articles require translated_fulltext or yootheme_text_replacements.');
        }
        if (!$builder) {
            foreach (['introtext', 'fulltext'] as $field) {
                if (trim((string) ($source[$field] ?? '')) !== '' && !array_key_exists('translated_' . $field, $arguments)) {
                    throw new \InvalidArgumentException('Missing translated_' . $field . '. Refusing to copy source text.');
                }
            }
        }
        $fields = [
            'title' => $title, 'alias' => $arguments['translated_alias'] ?? ($target['alias'] ?? $this->generateAlias($title)),
            'language' => $lang, 'state' => $state, 'catid' => $categoryId,
            'introtext' => (string) ($arguments['translated_introtext'] ?? ''),
            'fulltext' => $this->buildTranslatedFulltext($source, $arguments),
            'metadesc' => $arguments['translated_metadesc'] ?? ($target['metadesc'] ?? ''),
            'metakey' => $arguments['translated_metakey'] ?? ($target['metakey'] ?? ''),
        ];
        $menu = $this->planTranslationMenu($sourceId, $targetId, $lang, $fields, $arguments, $lock);
        $sourceFields = $this->translationCustomFields($sourceId, $lock);
        $targetFields = $target ? $this->translationCustomFields($targetId, $lock) : [];
        $warnings = [];
        foreach (['metadesc', 'metakey'] as $seo) {
            if (trim((string) ($source[$seo] ?? '')) !== '' && trim((string) $fields[$seo]) === '') {
                $warnings[] = ['code' => 'missing_translated_metadata', 'field' => $seo];
            }
        }
        if ($sourceFields !== []) {
            $warnings[] = ['code' => 'custom_fields_require_review', 'source_fields' => $sourceFields, 'target_fields' => $targetFields,
                'hint' => 'Classify text, shared values and references explicitly. This tool does not copy custom fields.'];
        }
        if ($builder) {
            $layout = json_decode($processor->extractJson($source['fulltext']) ?? '', true);
            $warnings = array_merge($warnings, $processor->getTranslationCoverageWarnings($layout ?? []));
        }
        // Hash values too, but do not expose custom-field values in the preview.
        $request = $arguments;
        unset($request['dry_run'], $request['if_match'], $request['confirm_guarded_write']);
        $snapshot = [$source, $target, $group, $targetGroup, $category, $menu, $sourceFields, $targetFields, $request];
        $canonical = static function ($value) use (&$canonical) {
            if (is_array($value)) {
                if (!array_is_list($value)) { ksort($value); }
                return array_map($canonical, $value);
            }
            return $value;
        };
        $etag = hash('sha256', json_encode($canonical($snapshot), JSON_THROW_ON_ERROR));
        $before = $target ? array_intersect_key($target, $fields) : null;
        $after = $fields;
        unset($after['introtext'], $after['fulltext']);
        if ($before) { unset($before['introtext'], $before['fulltext']); }
        $preview = ['action' => $target ? 'update_existing' : 'create_new', 'source_id' => $sourceId, 'target_id' => $targetId ?: null,
            'before' => $before, 'after' => $after, 'menu' => $menu,
            'preserved_editorial' => array_intersect_key($target ?? $source, array_flip(['created', 'created_by', 'created_by_alias', 'access', 'publish_up', 'publish_down'])),
            'associations' => ['source_group' => $group, 'target_group' => $targetGroup, 'will_associate' => !$associatedId],
            'parity_warnings' => $warnings, 'fallback' => $builder ? 'render_and_verify_before_write' : 'not_required'];
        return ['source' => $source, 'target' => $target, 'associated' => $associatedId > 0, 'fields' => $fields,
            'menu' => $menu, 'builder' => $builder, 'parity_warnings' => $warnings, 'etag' => $etag, 'preview' => $preview];
    }

    protected function translationRow(string $table, int $id, bool $lock = false): ?array
    {
        return $this->db->setQuery('SELECT * FROM ' . $this->db->quoteName($table) . ' WHERE id = ' . $id . ($lock ? ' FOR UPDATE' : ''))->loadAssoc();
    }

    protected function translationAssociationGroup(int $id, string $context, bool $lock = false): array
    {
        $table = $context === 'com_menus.item' ? '#__menu' : '#__content';
        $q = 'SELECT a.id, a.' . $this->db->quoteName('key') . ', c.language FROM ' . $this->db->quoteName('#__associations') . ' a'
            . ' LEFT JOIN ' . $this->db->quoteName($table) . ' c ON c.id = a.id'
            . ' WHERE a.context = ' . $this->db->quote($context) . ' AND a.' . $this->db->quoteName('key')
            . ' IN (SELECT ' . $this->db->quoteName('key') . ' FROM ' . $this->db->quoteName('#__associations')
            . ' WHERE id = ' . $id . ' AND context = ' . $this->db->quote($context) . ') ORDER BY a.id';
        return $this->db->setQuery($q . ($lock ? ' FOR UPDATE' : ''))->loadAssocList();
    }

    protected function translationCustomFields(int $id, bool $lock): array
    {
        $rows = $this->db->setQuery('SELECT v.field_id, v.value, f.name, f.type FROM ' . $this->db->quoteName('#__fields_values') . ' v'
            . ' INNER JOIN ' . $this->db->quoteName('#__fields') . ' f ON f.id = v.field_id'
            . ' WHERE f.context = ' . $this->db->quote('com_content.article') . ' AND v.item_id = ' . $this->db->quote((string) $id)
            . ' ORDER BY v.field_id, v.value' . ($lock ? ' FOR UPDATE' : ''))->loadAssocList();
        return array_map(static function ($row) {
            $classification = match ($row['type']) {
                'text', 'textarea', 'editor' => 'text',
                'calendar', 'integer', 'color' => 'shared',
                'media', 'user', 'usergrouplist', 'articles', 'subform' => 'reference',
                default => 'review',
            };
            return ['field_id' => (int) $row['field_id'], 'name' => $row['name'], 'type' => $row['type'],
                'classification' => $classification, 'value_hash' => hash('sha256', (string) $row['value'])];
        }, $rows);
    }

    protected function renderTranslation(string $fulltext, ?string $language = null): array
    {
        return YooThemeTranslationRenderer::render($fulltext, $language);
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
    protected function loadArticle(int $id, bool $lock = false): ?array
    {
        return $this->translationRow('#__content', $id, $lock);
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
        $processor = new YooThemeLayoutProcessor();
        $builder = $processor->detectLayout((string) ($source['fulltext'] ?? ''));
        // Empty text and "0" are explicit plain-content values, not missing translations.
        if (!$builder && array_key_exists('translated_fulltext', $arguments)) {
            return (string) $arguments['translated_fulltext'];
        }
        // Full-layout submissions have the same editorial write boundary as maps.
        if (!empty($arguments['translated_fulltext'])) {
            $ft = (string) $arguments['translated_fulltext'];
            if (str_starts_with(trim($ft), '{')) { $ft = '<!-- ' . $ft . ' -->'; }
            if ($builder) {
                $original = json_decode($processor->extractJson($source['fulltext']) ?? '', true);
                $translated = json_decode($processor->extractJson($ft) ?? '', true);
                if (!is_array($original) || !is_array($translated)) { throw new \InvalidArgumentException('Malformed Builder layout.'); }
                $processor->validateTranslatedLayout($original, $translated);
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
    protected function duplicateArticle(array $source, array $overrides): int
    {
        $fields = array_merge($source, $overrides);
        unset($fields['id'], $fields['asset_id'], $fields['checked_out'], $fields['checked_out_time']);

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

        return $newId;
    }

    // createAsset → now createAssetForContent in AbstractTool

    protected function updateArticle(int $id, array $fields): void
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

    protected function planTranslationMenu(int $sourceId, int $targetId, string $lang, array $fields, array $arguments, bool $lock): array
    {
        $create = ($arguments['create_menu'] ?? false) === true;
        $menuId = (int) ($arguments['menu_id'] ?? 0);
        if ($create && $menuId) { throw new \InvalidArgumentException('Choose create_menu or menu_id, never both.'); }
        if (!$create && !$menuId) {
            if (isset($arguments['translated_page_title']) || isset($arguments['source_menu_id']) || isset($arguments['target_menutype']) || isset($arguments['target_parent_id'])) {
                throw new \InvalidArgumentException('Menu options require create_menu=true or menu_id.');
            }
            return ['action' => 'none'];
        }
        $sourceMenus = $this->translationSourceMenus($sourceId, $lock);
        if (isset($arguments['source_menu_id'])) {
            $sourceMenus = array_values(array_filter($sourceMenus, static fn($m) => (int) $m['id'] === (int) $arguments['source_menu_id']));
        }
        if (count($sourceMenus) !== 1) { throw new \InvalidArgumentException('Choose a unique source_menu_id pointing to the source article.'); }
        $sourceMenu = $sourceMenus[0];
        $sourceGroup = $this->translationAssociationGroup((int) $sourceMenu['id'], 'com_menus.item', $lock);
        $targetAssociations = array_values(array_filter($sourceGroup, static fn($m) => ($m['language'] ?? null) === $lang));
        if (count($targetAssociations) > 1 || ($targetAssociations && (int) $targetAssociations[0]['id'] !== $menuId)) {
            throw new \InvalidArgumentException('Source menu already has a different target-language association.');
        }
        if ($menuId) {
            if (isset($arguments['target_menutype']) || isset($arguments['target_parent_id'])) {
                throw new \InvalidArgumentException('Existing menus preserve menutype and parent.');
            }
            $menu = $this->translationRow('#__menu', $menuId, $lock);
            if (!$menu || $menu['language'] !== $lang || (int) $menu['client_id'] !== 0 || $menu['type'] !== 'component' || (int) $menu['published'] < 0) {
                throw new \InvalidArgumentException('menu_id must be a non-trashed site component menu in target_language.');
            }
            $linked = $this->translationArticleIdFromLink((string) $menu['link']);
            // Explicit selection permits repairing ES -> CA and a genuinely missing article.
            if ($linked === null || (!in_array($linked, [$sourceId, $targetId], true) && $this->loadArticle($linked))) {
                throw new \InvalidArgumentException('Selected menu points to another live article or a different component.');
            }
            $targetGroup = $this->translationAssociationGroup($menuId, 'com_menus.item', $lock);
            if ($targetGroup !== [] && !$targetAssociations) { throw new \InvalidArgumentException('Selected menu belongs to another association group.'); }
            return ['action' => 'repoint', 'source' => $sourceMenu, 'target' => $menu, 'source_group' => $sourceGroup,
                'target_group' => $targetGroup, 'associated' => $targetAssociations !== [], 'target_article_id' => $targetId ?: 'new'];
        }
        $menuType = (string) ($arguments['target_menutype'] ?? $this->findMenuTypeForLanguage($lang) ?? '');
        if ($menuType === '') { throw new \InvalidArgumentException('Specify target_menutype: no unique target language menu found.'); }
        $parentId = (int) ($arguments['target_parent_id'] ?? 1);
        $parent = $this->translationRow('#__menu', $parentId, $lock);
        if (!$parent || ($parentId !== 1 && ($parent['menutype'] !== $menuType || !in_array($parent['language'], [$lang, '*'], true)))) {
            throw new \InvalidArgumentException('target_parent_id does not belong to the target menu/language.');
        }
        $type = $this->db->setQuery('SELECT * FROM ' . $this->db->quoteName('#__menu_types') . ' WHERE menutype = ' . $this->db->quote($menuType) . ($lock ? ' FOR UPDATE' : ''))->loadAssoc();
        if (!$type || (int) ($type['client_id'] ?? 0) !== 0) { throw new \InvalidArgumentException('target_menutype must be an existing site menu.'); }
        $collision = $this->db->setQuery('SELECT id FROM ' . $this->db->quoteName('#__menu') . ' WHERE parent_id = ' . $parentId
            . ' AND alias = ' . $this->db->quote($fields['alias']) . ' AND client_id = 0' . ($lock ? ' FOR UPDATE' : ''))->loadResult();
        if ($collision) { throw new \InvalidArgumentException('Menu alias collision. Select an eligible menu_id explicitly; aliases never prove orphan status.'); }
        return ['action' => 'create', 'source' => $sourceMenu, 'source_group' => $sourceGroup, 'menutype' => $menuType,
            'menu_type' => $type, 'parent' => $parent, 'parent_id' => $parentId, 'associated' => false];
    }

    protected function translationSourceMenus(int $articleId, bool $lock): array
    {
        $rows = $this->db->setQuery('SELECT * FROM ' . $this->db->quoteName('#__menu')
            . ' WHERE client_id = 0 AND published >= 0 AND type = ' . $this->db->quote('component')
            . ' AND link LIKE ' . $this->db->quote('%option=com_content%') . ' ORDER BY id' . ($lock ? ' FOR UPDATE' : ''))->loadAssocList();
        return array_values(array_filter($rows, fn($row) => $this->translationArticleIdFromLink($row['link']) === $articleId));
    }

    protected function translationArticleIdFromLink(string $link): ?int
    {
        $link = html_entity_decode($link, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parts = parse_url($link);
        if (!is_array($parts) || isset($parts['host']) || isset($parts['scheme'])
            || !in_array($parts['path'] ?? '', ['', 'index.php', '/index.php'], true)) { return null; }
        $raw = $parts['query'] ?? '';
        if (preg_match_all('/(?:^|&)id=/', $raw) !== 1 || !preg_match('/(?:^|&)id=\d+(?::[^&]*)?(?:&|$)/', $raw)) { return null; }
        parse_str($raw, $query);
        if (($query['option'] ?? null) !== 'com_content' || ($query['view'] ?? null) !== 'article'
            || !is_string($query['id'] ?? null) || !preg_match('/^(\d+)(?::[^&]*)?$/', $query['id'], $match)) { return null; }
        return (int) $match[1];
    }

    protected function applyTranslationMenu(array $plan, int $articleId, array $fields, array $arguments): array
    {
        if ($plan['action'] === 'none') { return ['action' => 'unchanged']; }
        if ($plan['action'] === 'repoint') {
            $id = (int) $plan['target']['id'];
            if ($this->translationRow('#__menu', $id) !== $plan['target']) {
                throw new \RuntimeException('Selected menu changed after preview validation; reconcile the article before retrying.');
            }
            $link = $plan['target']['link'];
            // Preserve query order, numeric menu IDs, extra parameters and fragment.
            $link = preg_replace('/([?&](?:amp;)?id=)\d+(?::[^&#]*)?/', '${1}' . $articleId, $link, 1);
            if ($link !== $plan['target']['link']) {
                $this->db->setQuery('UPDATE ' . $this->db->quoteName('#__menu') . ' SET link = ' . $this->db->quote($link) . ' WHERE id = ' . $id
                    . ' AND BINARY link = BINARY ' . $this->db->quote($plan['target']['link']))->execute();
                if ($this->db->getAffectedRows() !== 1) { throw new \RuntimeException('Concurrent menu link change.'); }
            }
        } else {
            $table = \Joomla\CMS\Table\Table::getInstance('Menu', 'JTable', ['dbo' => $this->db]);
            foreach (['type', 'component_id', 'access', 'params', 'note', 'img', 'template_style_id', 'browserNav'] as $key) {
                $table->$key = $plan['source'][$key];
            }
            $table->menutype = $plan['menutype'];
            $table->title = $fields['title'];
            $table->alias = $fields['alias'];
            $table->link = 'index.php?option=com_content&view=article&id=' . $articleId;
            $table->published = $fields['state'] === 1 ? 1 : 0;
            $table->home = 0;
            $table->language = $fields['language'];
            $table->client_id = 0;
            $table->parent_id = $plan['parent_id'];
            $table->setLocation($plan['parent_id'], 'last-child');
            try {
                if (!$table->check() || !$table->store()) { throw new \RuntimeException('Menu Table write failed: ' . $table->getError()); }
            } finally {
                if ((int) $table->id > 0) {
                    $this->translationEffects['menu'] = ['action' => 'create_attempted', 'menu_item_id' => (int) $table->id];
                }
            }
            $id = (int) $table->id;
        }
        $this->translationEffects['menu'] = ['action' => $plan['action'], 'menu_item_id' => $id, 'association_status' => 'pending'];
        if (!$plan['associated']) { $this->createMenuAssociation((int) $plan['source']['id'], $id); }
        if (array_key_exists('translated_page_title', $arguments)) {
            $this->updateMenuItemPageTitle($id, $arguments['translated_page_title']);
        }
        return ['action' => $plan['action'], 'menu_item_id' => $id, 'source_menu_item_id' => (int) $plan['source']['id']];
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

        $types = array_values(array_unique($this->db->setQuery($query)->loadColumn()));
        return count($types) === 1 ? (string) $types[0] : null;
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
