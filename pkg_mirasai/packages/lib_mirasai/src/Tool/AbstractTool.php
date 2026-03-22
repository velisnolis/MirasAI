<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

abstract class AbstractTool implements ToolInterface
{
    protected DatabaseInterface $db;

    public function __construct()
    {
        $this->db = Factory::getContainer()->get(DatabaseInterface::class);
    }

    public function getPermissions(): array
    {
        return [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ];
    }

    /**
     * Convert this tool to MCP tool format.
     *
     * @return array<string, mixed>
     */
    public function toMcpTool(): array
    {
        return [
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'inputSchema' => $this->getInputSchema(),
        ];
    }

    // ── Shared helpers ────────────────────────────────────────────

    /**
     * Check if a language is installed and published.
     */
    protected function languageExists(string $langCode): bool
    {
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__languages'))
            ->where('lang_code = :lang')
            ->where('published = 1')
            ->bind(':lang', $langCode);

        return (int) $this->db->setQuery($query)->loadResult() > 0;
    }

    /**
     * Find a translation of an item via #__associations.
     *
     * @param string $context  e.g. 'com_content.item', 'com_categories.item'
     */
    protected function findTranslation(int $sourceId, string $targetLang, string $context = 'com_content.item'): ?int
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('key'))
            ->from($this->db->quoteName('#__associations'))
            ->where('context = ' . $this->db->quote($context))
            ->where('id = :id')
            ->bind(':id', $sourceId, ParameterType::INTEGER);

        $key = $this->db->setQuery($query)->loadResult();

        if (!$key) {
            return null;
        }

        // Determine the content table for the language check
        $table = match ($context) {
            'com_content.item' => '#__content',
            'com_categories.item' => '#__categories',
            'com_menus.item' => '#__menu',
            default => '#__content',
        };

        $query = $this->db->getQuery(true)
            ->select('a.id')
            ->from($this->db->quoteName('#__associations', 'a'))
            ->join('INNER', $this->db->quoteName($table, 't') . ' ON t.id = a.id')
            ->where('a.context = ' . $this->db->quote($context))
            ->where('a.' . $this->db->quoteName('key') . ' = :akey')
            ->where('t.language = :lang')
            ->where('a.id != :sid')
            ->bind(':akey', $key)
            ->bind(':lang', $targetLang)
            ->bind(':sid', $sourceId, ParameterType::INTEGER);

        $result = $this->db->setQuery($query)->loadResult();

        return $result ? (int) $result : null;
    }

    /**
     * Create a language association between two items.
     *
     * @param string $context  e.g. 'com_content.item', 'com_categories.item'
     */
    protected function createAssociation(int $sourceId, int $newId, string $context = 'com_content.item'): void
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('key'))
            ->from($this->db->quoteName('#__associations'))
            ->where('context = ' . $this->db->quote($context))
            ->where('id = :id')
            ->bind(':id', $sourceId, ParameterType::INTEGER);

        $existingKey = $this->db->setQuery($query)->loadResult();

        if (!$existingKey) {
            $existingKey = 'mirasai_' . $sourceId . '_' . time();

            $query = $this->db->getQuery(true)
                ->insert($this->db->quoteName('#__associations'))
                ->columns(['context', 'id', $this->db->quoteName('key')])
                ->values(
                    $this->db->quote($context) . ','
                    . $sourceId . ','
                    . $this->db->quote($existingKey)
                );

            $this->db->setQuery($query)->execute();
        }

        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__associations'))
            ->columns(['context', 'id', $this->db->quoteName('key')])
            ->values(
                $this->db->quote($context) . ','
                . $newId . ','
                . $this->db->quote($existingKey)
            );

        $this->db->setQuery($query)->execute();
    }

    /**
     * Create a Joomla ACL asset for a content item.
     *
     * @param string $assetPrefix  e.g. 'com_content.article', 'com_categories.category'
     * @param string $parentAsset  e.g. 'com_content', 'com_categories'
     */
    protected function createAsset(int $itemId, string $title, string $assetPrefix = 'com_content.article', string $parentAsset = 'com_content'): int
    {
        $query = $this->db->getQuery(true)
            ->select('id')
            ->from($this->db->quoteName('#__assets'))
            ->where($this->db->quoteName('name') . ' = ' . $this->db->quote($parentAsset));

        $parentId = (int) $this->db->setQuery($query)->loadResult();

        if (!$parentId) {
            return 0;
        }

        $query = $this->db->getQuery(true)
            ->select('MAX(rgt)')
            ->from($this->db->quoteName('#__assets'));

        $maxRgt = (int) $this->db->setQuery($query)->loadResult();

        $assetName = $assetPrefix . '.' . $itemId;

        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__assets'))
            ->columns(['parent_id', 'lft', 'rgt', 'level', 'name', 'title', 'rules'])
            ->values(implode(',', [
                $parentId,
                $maxRgt + 1,
                $maxRgt + 2,
                3,
                $this->db->quote($assetName),
                $this->db->quote($title),
                $this->db->quote('{}'),
            ]));

        $this->db->setQuery($query)->execute();

        return (int) $this->db->insertid();
    }

    /**
     * Create asset and link it to a content item.
     */
    protected function createAssetForContent(int $articleId, string $title): void
    {
        $assetId = $this->createAsset($articleId, $title, 'com_content.article', 'com_content');

        if ($assetId) {
            $query = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__content'))
                ->set($this->db->quoteName('asset_id') . ' = ' . $assetId)
                ->where('id = ' . $articleId);

            $this->db->setQuery($query)->execute();
        }
    }

    /**
     * Create asset and link it to a category.
     */
    protected function createAssetForCategory(int $categoryId, string $title): void
    {
        $assetId = $this->createAsset($categoryId, $title, 'com_categories.category', 'com_categories');

        if ($assetId) {
            $query = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__categories'))
                ->set($this->db->quoteName('asset_id') . ' = ' . $assetId)
                ->where('id = ' . $categoryId);

            $this->db->setQuery($query)->execute();
        }
    }

    /**
     * Generate a URL-safe alias from a title.
     */
    protected function generateAlias(string $title): string
    {
        $alias = mb_strtolower($title);
        $alias = preg_replace('/[^a-z0-9\-]/', '-', $alias) ?? $alias;
        $alias = preg_replace('/-+/', '-', $alias) ?? $alias;

        return trim($alias, '-');
    }

    /**
     * Get all published languages.
     *
     * @return list<array{lang_code: string, title: string, published: bool}>
     */
    protected function getPublishedLanguages(): array
    {
        $query = $this->db->getQuery(true)
            ->select(['lang_code', 'title', 'published'])
            ->from($this->db->quoteName('#__languages'))
            ->where('published = 1')
            ->order('ordering');

        $rows = $this->db->setQuery($query)->loadAssocList();
        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'lang_code' => $row['lang_code'],
                'title' => $row['title'],
                'published' => (bool) $row['published'],
            ];
        }

        return $result;
    }
}
