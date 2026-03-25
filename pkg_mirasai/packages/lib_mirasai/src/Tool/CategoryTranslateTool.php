<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Joomla\Database\ParameterType;

class CategoryTranslateTool extends AbstractTool
{
    public function getName(): string
    {
        return 'category/translate';
    }

    public function getDescription(): string
    {
        return 'Creates a translated version of a Joomla category. YOU must provide translated_title (and optionally translated_description) — '
            . 'this tool does NOT auto-translate. It duplicates the category, sets the target language, and creates the language association and asset. '
            . 'Translate categories before articles so that content/translate can auto-map articles to the correct target category.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'source_id' => [
                    'type' => 'integer',
                    'description' => 'Source category ID.',
                ],
                'target_language' => [
                    'type' => 'string',
                    'description' => 'Target language code (e.g. es-ES).',
                ],
                'translated_title' => [
                    'type' => 'string',
                    'description' => 'Translated category title.',
                ],
                'translated_alias' => [
                    'type' => 'string',
                    'description' => 'URL alias. Auto-generated from title if omitted.',
                ],
                'translated_description' => [
                    'type' => 'string',
                    'description' => 'Translated category description.',
                ],
                'overwrite' => [
                    'type' => 'boolean',
                    'description' => 'If true, overwrites existing translation. Default: false.',
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

        if (!$this->languageExists($targetLang)) {
            return ['error' => "Language {$targetLang} is not installed or not published."];
        }

        // Load source category
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__categories'))
            ->where('id = :id')
            ->bind(':id', $sourceId, ParameterType::INTEGER);

        $source = $this->db->setQuery($query)->loadAssoc();

        if (!$source) {
            return ['error' => "Category {$sourceId} not found."];
        }

        if (($source['extension'] ?? '') !== 'com_content') {
            return ['error' => 'Only com_content categories are currently supported.'];
        }

        // Check existing translation
        $existing = $this->findTranslation($sourceId, $targetLang, 'com_categories.item');

        if ($existing && !$overwrite) {
            return [
                'error' => "Category translation already exists for {$targetLang} (ID: {$existing}).",
                'existing_id' => $existing,
            ];
        }

        $title = $arguments['translated_title'];
        $alias = $arguments['translated_alias'] ?? $this->generateAlias($title);
        $description = $arguments['translated_description'] ?? ($source['description'] ?? '');
        $targetParentId = $this->resolveTargetParentCategoryId($source, $targetLang);

        if ($targetParentId === null) {
            return ['error' => 'Translate the parent category first so the target category can be placed under the right language tree.'];
        }

        if ($existing && $overwrite) {
            $query = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__categories'))
                ->set($this->db->quoteName('title') . ' = ' . $this->db->quote($title))
                ->set($this->db->quoteName('alias') . ' = ' . $this->db->quote($alias))
                ->set($this->db->quoteName('description') . ' = ' . $this->db->quote($description))
                ->set($this->db->quoteName('modified_time') . ' = ' . $this->db->quote(date('Y-m-d H:i:s')))
                ->where('id = ' . $existing);

            $this->db->setQuery($query)->execute();
            $this->createAssetForCategory($existing, $title);

            return [
                'action' => 'updated',
                'category_id' => $existing,
                'source_id' => $sourceId,
                'target_language' => $targetLang,
                'title' => $title,
            ];
        }

        $parentNode = $this->getCategoryNode($targetParentId);

        if (!$parentNode) {
            return ['error' => "Target parent category {$targetParentId} not found."];
        }

        $insertAt = (int) $parentNode['rgt'];
        $level = (int) $parentNode['level'] + 1;
        $path = $this->buildCategoryPath((string) $parentNode['path'], $alias);

        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__categories'))
            ->set($this->db->quoteName('rgt') . ' = ' . $this->db->quoteName('rgt') . ' + 2')
            ->where($this->db->quoteName('rgt') . ' >= :insertAt')
            ->bind(':insertAt', $insertAt, ParameterType::INTEGER);
        $this->db->setQuery($query)->execute();

        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__categories'))
            ->set($this->db->quoteName('lft') . ' = ' . $this->db->quoteName('lft') . ' + 2')
            ->where($this->db->quoteName('lft') . ' > :insertAt')
            ->bind(':insertAt', $insertAt, ParameterType::INTEGER);
        $this->db->setQuery($query)->execute();

        // Insert new category
        $columns = [
            'parent_id', 'lft', 'rgt', 'level', 'path', 'extension',
            'title', 'alias', 'note', 'description', 'published',
            'access', 'params', 'metadesc', 'metakey', 'metadata',
            'language', 'created_user_id', 'created_time',
            'modified_user_id', 'modified_time', 'hits', 'version',
        ];

        $values = [
            $targetParentId,
            $insertAt,
            $insertAt + 1,
            $level,
            $this->db->quote($path),
            $this->db->quote($source['extension'] ?? 'com_content'),
            $this->db->quote($title),
            $this->db->quote($alias),
            $this->db->quote($source['note'] ?? ''),
            $this->db->quote($description),
            (int) ($source['published'] ?? 1),
            (int) ($source['access'] ?? 1),
            $this->db->quote($source['params'] ?? '{}'),
            $this->db->quote($source['metadesc'] ?? ''),
            $this->db->quote($source['metakey'] ?? ''),
            $this->db->quote($source['metadata'] ?? '{}'),
            $this->db->quote($targetLang),
            (int) ($source['created_user_id'] ?? 0),
            $this->db->quote(date('Y-m-d H:i:s')),
            0,
            $this->db->quote(date('Y-m-d H:i:s')),
            0,
            1,
        ];

        $query = 'INSERT INTO ' . $this->db->quoteName('#__categories')
            . ' (' . implode(',', array_map([$this->db, 'quoteName'], $columns)) . ')'
            . ' VALUES (' . implode(',', $values) . ')';

        $this->db->setQuery($query)->execute();

        $newId = (int) $this->db->insertid();

        // Create asset
        $this->createAssetForCategory($newId, $title);

        // Create association
        $this->createAssociation($sourceId, $newId, 'com_categories.item');

        return [
            'action' => 'created',
            'category_id' => $newId,
            'source_id' => $sourceId,
            'target_language' => $targetLang,
            'title' => $title,
            'parent_id' => $targetParentId,
        ];
    }

    protected function resolveTargetParentCategoryId(array $source, string $targetLang): ?int
    {
        $parentId = (int) ($source['parent_id'] ?? 1);

        if ($parentId <= 1) {
            return 1;
        }

        $translatedParent = $this->findTranslation($parentId, $targetLang, 'com_categories.item');

        return $translatedParent ?: null;
    }

    /**
     * @return array{id: string, parent_id: string, level: string, path: string, lft: string, rgt: string}|null
     */
    protected function getCategoryNode(int $categoryId): ?array
    {
        $query = $this->db->getQuery(true)
            ->select(['id', 'parent_id', 'level', 'path', 'lft', 'rgt'])
            ->from($this->db->quoteName('#__categories'))
            ->where('id = :id')
            ->bind(':id', $categoryId, ParameterType::INTEGER);

        return $this->db->setQuery($query)->loadAssoc() ?: null;
    }

    protected function buildCategoryPath(string $parentPath, string $alias): string
    {
        if ($parentPath === '' || $parentPath === 'root') {
            return $alias;
        }

        return $parentPath . '/' . $alias;
    }
}
