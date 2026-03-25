<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Joomla\CMS\Factory;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\Table\Asset as AssetTable;
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
     * Return a sanitized summary of the tool arguments for audit logging.
     *
     * Default: JSON of argument keys only (no values). Destructive tools
     * override this with tool-specific sanitization per the Arguments
     * Sanitization Policy.
     *
     * @param array<string, mixed> $arguments
     */
    public function getAuditSummary(array $arguments): string
    {
        return json_encode(['keys' => array_keys($arguments)], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Convert this tool to MCP tool format.
     *
     * @return array<string, mixed>
     */
    public function toMcpTool(): array
    {
        $tool = [
            'name'        => $this->getName(),
            'description' => $this->getDescription(),
            'inputSchema' => $this->getInputSchema(),
        ];

        // Expose permission hints as MCP metadata so agents can ask for
        // user confirmation before calling destructive tools, rather than
        // discovering the restriction only after the call fails.
        $metadata = $this->buildMcpMetadata();

        if (!empty($metadata)) {
            $tool['metadata'] = $metadata;
        }

        return $tool;
    }

    /**
     * Build the optional MCP metadata object for this tool.
     *
     * Returns an associative array that will be included as the `metadata`
     * key in the tools/list response. Override in subclasses to add extra
     * hints. Return [] (default) to omit the metadata key entirely.
     *
     * Standard keys (MCP extension — not part of the core spec):
     *   destructive       bool  Tool can irreversibly modify or delete data.
     *   requires_elevation bool  Tool is gated behind Smart Sudo elevation.
     *
     * @return array<string, mixed>
     */
    protected function buildMcpMetadata(): array
    {
        $permissions = $this->getPermissions();
        $metadata    = [];

        if (!empty($permissions['destructive'])) {
            $metadata['destructive']        = true;
            $metadata['requires_elevation'] = true;
        }

        return $metadata;
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
     * Create a Joomla ACL asset for a content item, placed as the last child
     * of the given parent asset node.
     *
     * Delegates to insertAssetNode which uses Joomla\CMS\Table\Asset to
     * maintain nested set integrity automatically.
     *
     * @param string $assetPrefix  e.g. 'com_content.article', 'com_categories.category'
     * @param string $parentAsset  e.g. 'com_content', 'com_categories'
     */
    protected function createAsset(int $itemId, string $title, string $assetPrefix = 'com_content.article', string $parentAsset = 'com_content'): int
    {
        $parentId = $this->getAssetIdByName($parentAsset);

        if (!$parentId) {
            return 0;
        }

        $assetName = $assetPrefix . '.' . $itemId;

        return $this->insertAssetNode($parentId, $assetName, $title) ?? 0;
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
        $this->ensureContentCategoryAsset($categoryId, $title);
    }

    /**
     * Ensure a com_content category has a valid asset linked under the right parent.
     */
    protected function ensureContentCategoryAsset(int $categoryId, string $title): void
    {
        $query = $this->db->getQuery(true)
            ->select(['id', 'title', 'parent_id', 'asset_id'])
            ->from($this->db->quoteName('#__categories'))
            ->where('id = :id')
            ->where('extension = ' . $this->db->quote('com_content'))
            ->bind(':id', $categoryId, ParameterType::INTEGER);

        $category = $this->db->setQuery($query)->loadAssoc();

        if (!$category) {
            return;
        }

        $assetName = 'com_content.category.' . $categoryId;
        $assetId = $this->resolveOrCreateCategoryAsset(
            (int) $category['id'],
            (string) $category['title'],
            (int) $category['parent_id'],
            $assetName,
            !empty($category['asset_id']) ? (int) $category['asset_id'] : null
        );

        if (!$assetId) {
            return;
        }

        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__categories'))
            ->set($this->db->quoteName('asset_id') . ' = ' . $assetId)
            ->where('id = :id')
            ->bind(':id', $categoryId, ParameterType::INTEGER);

        $this->db->setQuery($query)->execute();
    }

    protected function resolveOrCreateCategoryAsset(
        int $categoryId,
        string $title,
        int $parentCategoryId,
        string $assetName,
        ?int $linkedAssetId = null
    ): ?int {
        $asset = $linkedAssetId ? $this->getAssetById($linkedAssetId) : null;

        if ($asset && (string) $asset['name'] === $assetName) {
            $this->updateAssetTitle((int) $asset['id'], $title);

            return (int) $asset['id'];
        }

        $asset = $this->getAssetByName($assetName);

        if ($asset) {
            $this->updateAssetTitle((int) $asset['id'], $title);

            return (int) $asset['id'];
        }

        $parentAssetId = $this->resolveCategoryAssetParentId($parentCategoryId);

        if (!$parentAssetId) {
            return null;
        }

        return $this->insertAssetNode($parentAssetId, $assetName, $title);
    }

    protected function resolveCategoryAssetParentId(int $parentCategoryId): ?int
    {
        if ($parentCategoryId <= 1) {
            return $this->getAssetIdByName('com_content');
        }

        $query = $this->db->getQuery(true)
            ->select(['id', 'title', 'parent_id', 'asset_id'])
            ->from($this->db->quoteName('#__categories'))
            ->where('id = :id')
            ->where('extension = ' . $this->db->quote('com_content'))
            ->bind(':id', $parentCategoryId, ParameterType::INTEGER);

        $parentCategory = $this->db->setQuery($query)->loadAssoc();

        if (!$parentCategory) {
            return null;
        }

        $parentAssetName = 'com_content.category.' . $parentCategoryId;
        $parentAssetId = $this->resolveOrCreateCategoryAsset(
            (int) $parentCategory['id'],
            (string) $parentCategory['title'],
            (int) $parentCategory['parent_id'],
            $parentAssetName,
            !empty($parentCategory['asset_id']) ? (int) $parentCategory['asset_id'] : null
        );

        if ($parentAssetId && (int) ($parentCategory['asset_id'] ?? 0) !== (int) $parentAssetId) {
            $query = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__categories'))
                ->set($this->db->quoteName('asset_id') . ' = ' . (int) $parentAssetId)
                ->where('id = :id')
                ->bind(':id', (int) $parentCategory['id'], ParameterType::INTEGER);

            $this->db->setQuery($query)->execute();
        }

        return $parentAssetId ?: null;
    }

    /**
     * Insert an asset as the last child of the given parent, preserving nested
     * set integrity via Joomla\CMS\Table\Asset (Joomla-native implementation).
     *
     * Using setLocation() + store() delegates all lft/rgt bookkeeping and table
     * locking to the Joomla framework, eliminating the risk of tree corruption
     * from manual nested set arithmetic.
     */
    protected function insertAssetNode(int $parentAssetId, string $assetName, string $title): ?int
    {
        // Re-use existing node (idempotent) to avoid duplicates.
        $existing = $this->getAssetByName($assetName);

        if ($existing) {
            $this->updateAssetTitle((int) $existing['id'], $title);

            return (int) $existing['id'];
        }

        /** @var AssetTable $assetTable */
        $assetTable        = AssetTable::getInstance('Asset', 'JTable', ['dbo' => $this->db]);
        $assetTable->name  = $assetName;
        $assetTable->title = $title;
        $assetTable->rules = '{}';
        $assetTable->setLocation($parentAssetId, 'last-child');

        if (!$assetTable->store()) {
            return null;
        }

        return (int) $assetTable->id;
    }

    protected function getAssetIdByName(string $name): ?int
    {
        $query = $this->db->getQuery(true)
            ->select('id')
            ->from($this->db->quoteName('#__assets'))
            ->where($this->db->quoteName('name') . ' = :name')
            ->bind(':name', $name);

        $result = $this->db->setQuery($query)->loadResult();

        return $result ? (int) $result : null;
    }

    /**
     * @return array{id: string, parent_id: string, lft: string, rgt: string, level: string, name: string, title: string}|null
     */
    protected function getAssetById(int $assetId): ?array
    {
        $query = $this->db->getQuery(true)
            ->select(['id', 'parent_id', 'lft', 'rgt', 'level', 'name', 'title'])
            ->from($this->db->quoteName('#__assets'))
            ->where('id = :id')
            ->bind(':id', $assetId, ParameterType::INTEGER);

        return $this->db->setQuery($query)->loadAssoc() ?: null;
    }

    /**
     * @return array{id: string, parent_id: string, lft: string, rgt: string, level: string, name: string, title: string}|null
     */
    protected function getAssetByName(string $name): ?array
    {
        $query = $this->db->getQuery(true)
            ->select(['id', 'parent_id', 'lft', 'rgt', 'level', 'name', 'title'])
            ->from($this->db->quoteName('#__assets'))
            ->where($this->db->quoteName('name') . ' = :name')
            ->bind(':name', $name);

        return $this->db->setQuery($query)->loadAssoc() ?: null;
    }

    protected function updateAssetTitle(int $assetId, string $title): void
    {
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__assets'))
            ->set($this->db->quoteName('title') . ' = ' . $this->db->quote($title))
            ->where('id = :id')
            ->bind(':id', $assetId, ParameterType::INTEGER);

        $this->db->setQuery($query)->execute();
    }

    /**
     * Ensure a workflow association exists for an item.
     *
     * If a source item is provided and already belongs to a workflow stage,
     * reuse that stage. Otherwise fall back to the default workflow stage for
     * the extension.
     */
    protected function ensureWorkflowAssociation(int $itemId, string $extension, ?int $sourceItemId = null): void
    {
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__workflow_associations'))
            ->where('item_id = :itemId')
            ->where('extension = :extension')
            ->bind(':itemId', $itemId, ParameterType::INTEGER)
            ->bind(':extension', $extension);

        if ((int) $this->db->setQuery($query)->loadResult() > 0) {
            return;
        }

        $stageId = $sourceItemId ? $this->getWorkflowStageForItem($sourceItemId, $extension) : null;
        $stageId = $stageId ?? $this->getDefaultWorkflowStageId($extension);

        if (!$stageId) {
            return;
        }

        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__workflow_associations'))
            ->columns([
                $this->db->quoteName('item_id'),
                $this->db->quoteName('stage_id'),
                $this->db->quoteName('extension'),
            ])
            ->values(
                (int) $itemId . ','
                . (int) $stageId . ','
                . $this->db->quote($extension)
            );

        $this->db->setQuery($query)->execute();
    }

    protected function getWorkflowStageForItem(int $itemId, string $extension): ?int
    {
        $query = $this->db->getQuery(true)
            ->select('stage_id')
            ->from($this->db->quoteName('#__workflow_associations'))
            ->where('item_id = :itemId')
            ->where('extension = :extension')
            ->bind(':itemId', $itemId, ParameterType::INTEGER)
            ->bind(':extension', $extension);

        $result = $this->db->setQuery($query)->loadResult();

        return $result ? (int) $result : null;
    }

    protected function getDefaultWorkflowStageId(string $extension): ?int
    {
        $query = $this->db->getQuery(true)
            ->select('s.id')
            ->from($this->db->quoteName('#__workflow_stages', 's'))
            ->join('INNER', $this->db->quoteName('#__workflows', 'w') . ' ON w.id = s.workflow_id')
            ->where('w.extension = :extension')
            ->where('w.default = 1')
            ->where('s.default = 1')
            ->where('w.published = 1')
            ->where('s.published = 1')
            ->bind(':extension', $extension);

        $result = $this->db->setQuery($query)->loadResult();

        return $result ? (int) $result : null;
    }

    /**
     * Generate a URL-safe alias from a title.
     */
    protected function generateAlias(string $title): string
    {
        return OutputFilter::stringURLSafe($title);
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

    /**
     * Infer the primary/source language as the one with the most articles.
     */
    protected function detectLikelySourceLanguage(): string
    {
        $query = $this->db->getQuery(true)
            ->select(['language', 'COUNT(*) AS cnt', 'MIN(id) AS first_id'])
            ->from($this->db->quoteName('#__content'))
            ->where('state >= 0')
            ->where('language != ' . $this->db->quote('*'))
            ->group('language')
            ->order('cnt DESC, first_id ASC');

        $row = $this->db->setQuery($query, 0, 1)->loadAssoc();

        return $row ? (string) $row['language'] : 'ca-ES';
    }

}
