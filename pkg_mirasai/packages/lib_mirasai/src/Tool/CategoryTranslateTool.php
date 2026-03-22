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
        return 'Translates a Joomla category to a target language. Duplicates the category, sets the target language, creates the association and asset.';
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

        if ($existing && $overwrite) {
            $query = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__categories'))
                ->set($this->db->quoteName('title') . ' = ' . $this->db->quote($title))
                ->set($this->db->quoteName('alias') . ' = ' . $this->db->quote($alias))
                ->set($this->db->quoteName('description') . ' = ' . $this->db->quote($description))
                ->set($this->db->quoteName('modified_time') . ' = ' . $this->db->quote(date('Y-m-d H:i:s')))
                ->where('id = ' . $existing);

            $this->db->setQuery($query)->execute();

            return [
                'action' => 'updated',
                'category_id' => $existing,
                'source_id' => $sourceId,
                'target_language' => $targetLang,
                'title' => $title,
            ];
        }

        // Find parent category in target language (or keep same parent)
        $parentId = (int) ($source['parent_id'] ?? 1);

        // Get max lft/rgt
        $query = $this->db->getQuery(true)
            ->select('MAX(rgt)')
            ->from($this->db->quoteName('#__categories'))
            ->where('extension = ' . $this->db->quote('com_content'));

        $maxRgt = (int) $this->db->setQuery($query)->loadResult();

        // Insert new category
        $columns = [
            'parent_id', 'lft', 'rgt', 'level', 'path', 'extension',
            'title', 'alias', 'note', 'description', 'published',
            'access', 'params', 'metadesc', 'metakey', 'metadata',
            'language', 'created_user_id', 'created_time',
            'modified_user_id', 'modified_time', 'hits', 'version',
        ];

        $values = [
            $parentId,
            $maxRgt + 1,
            $maxRgt + 2,
            (int) ($source['level'] ?? 1),
            $this->db->quote($alias),
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
        ];
    }
}
