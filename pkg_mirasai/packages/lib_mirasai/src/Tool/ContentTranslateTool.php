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
        $existing = $this->findExistingTranslation($sourceId, $targetLang);

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

        $translatedIntrotext = $arguments['translated_introtext'] ?? '';
        $translatedFulltext = $this->buildTranslatedFulltext(
            $source,
            $arguments,
        );

        if ($existing && $overwrite) {
            // Update existing
            $this->updateArticle($existing, [
                'title' => $translatedTitle,
                'alias' => $translatedAlias,
                'introtext' => $translatedIntrotext,
                'fulltext' => $translatedFulltext,
                'catid' => $targetCatId,
            ]);

            return [
                'action' => 'updated',
                'article_id' => $existing,
                'source_id' => $sourceId,
                'target_language' => $targetLang,
                'title' => $translatedTitle,
            ];
        }

        // Create new article
        $newId = $this->duplicateArticle($source, [
            'title' => $translatedTitle,
            'alias' => $translatedAlias,
            'language' => $targetLang,
            'introtext' => $translatedIntrotext,
            'fulltext' => $translatedFulltext,
            'catid' => $targetCatId,
        ]);

        // Create association
        $this->createAssociation($sourceId, $newId);

        return [
            'action' => 'created',
            'article_id' => $newId,
            'source_id' => $sourceId,
            'target_language' => $targetLang,
            'title' => $translatedTitle,
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

    private function languageExists(string $langCode): bool
    {
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__languages'))
            ->where('lang_code = :lang')
            ->where('published = 1')
            ->bind(':lang', $langCode);

        return (int) $this->db->setQuery($query)->loadResult() > 0;
    }

    private function findExistingTranslation(int $sourceId, string $targetLang): ?int
    {
        // Find association key for source
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('key'))
            ->from($this->db->quoteName('#__associations'))
            ->where('context = ' . $this->db->quote('com_content.item'))
            ->where('id = :id')
            ->bind(':id', $sourceId, ParameterType::INTEGER);

        $key = $this->db->setQuery($query)->loadResult();

        if (!$key) {
            return null;
        }

        // Find article in target language with same association key
        $query = $this->db->getQuery(true)
            ->select('a.id')
            ->from($this->db->quoteName('#__associations', 'a'))
            ->join('INNER', $this->db->quoteName('#__content', 'c') . ' ON c.id = a.id')
            ->where('a.context = ' . $this->db->quote('com_content.item'))
            ->where('a.' . $this->db->quoteName('key') . ' = :akey')
            ->where('c.language = :lang')
            ->where('a.id != :sid')
            ->bind(':akey', $key)
            ->bind(':lang', $targetLang)
            ->bind(':sid', $sourceId, ParameterType::INTEGER);

        $result = $this->db->setQuery($query)->loadResult();

        return $result ? (int) $result : null;
    }

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
            return $this->patchYoothemeLayout(
                $source['fulltext'] ?? '',
                $arguments['yootheme_text_replacements'],
            );
        }

        // Fallback: copy source fulltext
        return $source['fulltext'] ?? '';
    }

    /**
     * @param  array<string, string> $replacements
     */
    private function patchYoothemeLayout(string $fulltext, array $replacements): string
    {
        $fulltext = trim($fulltext);

        if (!str_starts_with($fulltext, '<!-- ')) {
            return $fulltext;
        }

        $end = strrpos($fulltext, ' -->');

        if ($end === false) {
            return $fulltext;
        }

        $json = substr($fulltext, 5, $end - 5);
        $layout = json_decode($json, true);

        if ($layout === null) {
            return $fulltext;
        }

        $this->applyReplacements($layout, $replacements, 'root');

        $newJson = json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return '<!-- ' . $newJson . ' -->';
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
                $childPath = "{$path}>{$childType}[{$i}]";
                $this->applyReplacements($child, $replacements, $childPath);
            }
        }
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

        return (int) $this->db->insertid();
    }

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

    private function createAssociation(int $sourceId, int $newId): void
    {
        // Check if source already has an association key
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('key'))
            ->from($this->db->quoteName('#__associations'))
            ->where('context = ' . $this->db->quote('com_content.item'))
            ->where('id = :id')
            ->bind(':id', $sourceId, ParameterType::INTEGER);

        $existingKey = $this->db->setQuery($query)->loadResult();

        if (!$existingKey) {
            // Create new association group
            $existingKey = 'mirasai_' . $sourceId . '_' . time();

            $query = $this->db->getQuery(true)
                ->insert($this->db->quoteName('#__associations'))
                ->columns(['context', 'id', $this->db->quoteName('key')])
                ->values(
                    $this->db->quote('com_content.item') . ','
                    . $sourceId . ','
                    . $this->db->quote($existingKey)
                );

            $this->db->setQuery($query)->execute();
        }

        // Add new article to association
        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__associations'))
            ->columns(['context', 'id', $this->db->quoteName('key')])
            ->values(
                $this->db->quote('com_content.item') . ','
                . $newId . ','
                . $this->db->quote($existingKey)
            );

        $this->db->setQuery($query)->execute();
    }

    private function generateAlias(string $title): string
    {
        $alias = mb_strtolower($title);
        $alias = preg_replace('/[^a-z0-9\-]/', '-', $alias) ?? $alias;
        $alias = preg_replace('/-+/', '-', $alias) ?? $alias;

        return trim($alias, '-');
    }
}
