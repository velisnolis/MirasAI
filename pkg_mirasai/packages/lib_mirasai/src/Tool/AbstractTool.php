<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Joomla\CMS\Factory;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

abstract class AbstractTool implements ToolInterface
{
    /** @var list<string> */
    protected const YOOTHEME_TEXT_PROPS = [
        'content', 'title', 'meta', 'subtitle', 'text', 'video_title',
        'link_text', 'label', 'description', 'caption', 'alt',
        'button_text', 'heading', 'footer', 'header', 'placeholder',
    ];

    /** @var list<string> */
    protected const YOOTHEME_CONFIG_PROPS = [
        'title_position', 'title_style', 'title_element', 'title_decoration',
        'image_position', 'image_effect', 'meta_align', 'id', 'class',
        'title_rotation', 'title_breakpoint', 'heading_style', 'height',
        'width', 'style', 'animation', 'name', 'status', 'source',
    ];

    /** @var list<string> */
    protected const YOOTHEME_SOURCE_TEXT_KEYS = [
        'before', 'after', 'prefix', 'suffix', 'content', 'title', 'text',
        'label', 'placeholder', 'description', 'caption', 'alt',
    ];

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
     * Insert an asset as the last child of the given parent, preserving nested set integrity.
     */
    protected function insertAssetNode(int $parentAssetId, string $assetName, string $title): ?int
    {
        $this->lockTables([$this->db->replacePrefix('#__assets') . ' WRITE']);

        try {
            $existing = $this->getAssetByName($assetName);

            if ($existing) {
                $this->updateAssetTitle((int) $existing['id'], $title);

                return (int) $existing['id'];
            }

            $parentAsset = $this->getAssetById($parentAssetId);

            if (!$parentAsset) {
                return null;
            }

            $insertAt = (int) $parentAsset['rgt'];
            $level = (int) $parentAsset['level'] + 1;

            $query = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__assets'))
                ->set($this->db->quoteName('rgt') . ' = ' . $this->db->quoteName('rgt') . ' + 2')
                ->where($this->db->quoteName('rgt') . ' >= :insertAt')
                ->bind(':insertAt', $insertAt, ParameterType::INTEGER);
            $this->db->setQuery($query)->execute();

            $query = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__assets'))
                ->set($this->db->quoteName('lft') . ' = ' . $this->db->quoteName('lft') . ' + 2')
                ->where($this->db->quoteName('lft') . ' > :insertAt')
                ->bind(':insertAt', $insertAt, ParameterType::INTEGER);
            $this->db->setQuery($query)->execute();

            $query = $this->db->getQuery(true)
                ->insert($this->db->quoteName('#__assets'))
                ->columns([
                    $this->db->quoteName('parent_id'),
                    $this->db->quoteName('lft'),
                    $this->db->quoteName('rgt'),
                    $this->db->quoteName('level'),
                    $this->db->quoteName('name'),
                    $this->db->quoteName('title'),
                    $this->db->quoteName('rules'),
                ])
                ->values(implode(',', [
                    $parentAssetId,
                    $insertAt,
                    $insertAt + 1,
                    $level,
                    $this->db->quote($assetName),
                    $this->db->quote($title),
                    $this->db->quote('{}'),
                ]));

            $this->db->setQuery($query)->execute();

            return (int) $this->db->insertid();
        } finally {
            $this->unlockTables();
        }
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
     * @param list<string> $tables
     */
    protected function lockTables(array $tables): void
    {
        $this->db->setQuery('LOCK TABLES ' . implode(', ', $tables))->execute();
    }

    protected function unlockTables(): void
    {
        $this->db->setQuery('UNLOCK TABLES')->execute();
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

    /**
     * Resolve the active site-side YOOtheme template style.
     */
    protected function resolveActiveYoothemeStyleId(): ?int
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
     * Check that a style id points at a site-side YOOtheme style.
     */
    protected function isYoothemeSiteStyle(int $styleId): bool
    {
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__template_styles'))
            ->where('id = :id')
            ->where('template = ' . $this->db->quote('yootheme'))
            ->where('client_id = 0')
            ->bind(':id', $styleId, ParameterType::INTEGER);

        return (int) $this->db->setQuery($query)->loadResult() > 0;
    }

    /**
     * Load the raw params array for a template style.
     *
     * @return array<string, mixed>|null
     */
    protected function loadYoothemeStyleParams(int $styleId): ?array
    {
        $query = $this->db->getQuery(true)
            ->select('params')
            ->from($this->db->quoteName('#__template_styles'))
            ->where('id = :id')
            ->bind(':id', $styleId, ParameterType::INTEGER);

        $paramsJson = $this->db->setQuery($query)->loadResult();

        if (!is_string($paramsJson) || $paramsJson === '') {
            return null;
        }

        $params = json_decode($paramsJson, true);

        return is_array($params) ? $params : null;
    }

    /**
     * Persist the raw params array for a template style.
     *
     * @param array<string, mixed> $params
     */
    protected function writeYoothemeStyleParams(int $styleId, array $params): void
    {
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__template_styles'))
            ->set(
                $this->db->quoteName('params') . ' = ' . $this->db->quote(
                    json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                )
            )
            ->where('id = :id')
            ->bind(':id', $styleId, ParameterType::INTEGER);

        $this->db->setQuery($query)->execute();
    }

    /**
     * Load the decoded YOOtheme config payload stored inside template style params.
     *
     * @return array<string, mixed>|null
     */
    protected function loadYoothemeStyleConfig(int $styleId): ?array
    {
        $params = $this->loadYoothemeStyleParams($styleId);

        if ($params === null) {
            return null;
        }

        $configJson = $params['config'] ?? null;

        if (!is_string($configJson) || $configJson === '') {
            return [];
        }

        $config = json_decode($configJson, true);

        return is_array($config) ? $config : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function loadYoothemeSystemCustomData(): ?array
    {
        $query = $this->db->getQuery(true)
            ->select('custom_data')
            ->from($this->db->quoteName('#__extensions'))
            ->where('element = ' . $this->db->quote('yootheme'))
            ->where('folder = ' . $this->db->quote('system'));

        $customDataJson = $this->db->setQuery($query)->loadResult();

        if (!is_string($customDataJson) || $customDataJson === '') {
            return null;
        }

        $data = json_decode($customDataJson, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function writeYoothemeSystemCustomData(array $data): void
    {
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__extensions'))
            ->set(
                $this->db->quoteName('custom_data') . ' = ' . $this->db->quote(
                    json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                )
            )
            ->where('element = ' . $this->db->quote('yootheme'))
            ->where('folder = ' . $this->db->quote('system'));

        $this->db->setQuery($query)->execute();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function loadYoothemeTemplates(): array
    {
        $data = $this->loadYoothemeSystemCustomData();
        $templates = $data['templates'] ?? [];

        return is_array($templates) ? $templates : [];
    }

    /**
     * @param array<string, array<string, mixed>> $templates
     */
    protected function writeYoothemeTemplates(array $templates): void
    {
        $data = $this->loadYoothemeSystemCustomData() ?? [];
        $data['templates'] = $templates;
        $this->writeYoothemeSystemCustomData($data);
    }

    protected function extractYoothemeJson(string $fulltext): ?string
    {
        $fulltext = trim($fulltext);

        if (!str_starts_with($fulltext, '<!-- ')) {
            return null;
        }

        $end = strrpos($fulltext, ' -->');

        if ($end === false) {
            return null;
        }

        return substr($fulltext, 5, $end - 5);
    }

    /**
     * @param array<string, mixed> $layout
     * @return list<array{path: string, node_type: string, field: string, text: string, format: string}>
     */
    protected function findYoothemeTranslatableNodes(array $layout, string $path = 'root'): array
    {
        $results = [];
        $nodeType = is_string($layout['type'] ?? null) ? $layout['type'] : 'unknown';
        $props = is_array($layout['props'] ?? null) ? $layout['props'] : [];
        $sourceProps = is_array($layout['source']['props'] ?? null) ? $layout['source']['props'] : [];
        $dynamicPropKeys = array_keys($sourceProps);

        foreach ($props as $key => $value) {
            if (!is_string($value) || !$this->isTranslatableYoothemeString((string) $key, $value)) {
                continue;
            }

            if (in_array((string) $key, $dynamicPropKeys, true)) {
                continue;
            }

            $results[] = [
                'path' => $path,
                'node_type' => $nodeType,
                'field' => (string) $key,
                'text' => $value,
                'format' => $this->detectYoothemeTextFormat($value),
            ];
        }

        if (is_array($layout['source'] ?? null)) {
            $results = array_merge($results, $this->findYoothemeSourceTextNodes($layout['source'], $path . '.source', $nodeType));
        }

        foreach ($layout['children'] ?? [] as $index => $child) {
            if (!is_array($child)) {
                continue;
            }

            $childType = is_string($child['type'] ?? null) ? $child['type'] : 'unknown';
            $results = array_merge(
                $results,
                $this->findYoothemeTranslatableNodes($child, "{$path}>{$childType}[{$index}]"),
            );
        }

        return $results;
    }

    /**
     * @param array<string, string> $replacements
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    protected function patchYoothemeLayoutArray(array $layout, array $replacements): array
    {
        $this->applyYoothemeReplacements($layout, $replacements);

        return $layout;
    }

    /**
     * @param array<string, mixed> $template
     */
    protected function getYoothemeTemplateName(array $template): string
    {
        $name = $template['name'] ?? '';

        return is_string($name) ? $name : '';
    }

    /**
     * @param array<string, mixed> $template
     */
    protected function getYoothemeTemplateLanguage(array $template): string
    {
        $query = $template['query'] ?? null;

        if (!is_array($query)) {
            return '';
        }

        $lang = $query['lang'] ?? '';

        return is_string($lang) ? trim($lang) : '';
    }

    /**
     * @param array<string, mixed> $template
     */
    protected function setYoothemeTemplateLanguage(array &$template, string $language): void
    {
        if (!isset($template['query']) || !is_array($template['query'])) {
            $template['query'] = [];
        }

        $template['query']['lang'] = $language;
    }

    /**
     * @param array<string, mixed> $template
     * @return array<string, mixed>|null
     */
    protected function getYoothemeTemplateLayout(array $template): ?array
    {
        $layout = $template['layout'] ?? null;

        return is_array($layout) ? $layout : null;
    }

    /**
     * @param array<string, mixed> $template
     * @param array<string, mixed> $layout
     */
    protected function setYoothemeTemplateLayout(array &$template, array $layout): void
    {
        $template['layout'] = $layout;
    }

    /**
     * @param array<string, mixed> $template
     * @return list<array{path: string, node_type: string, field: string, text: string, format: string}>
     */
    protected function findYoothemeTemplateTranslatableNodes(array $template): array
    {
        $layout = $this->getYoothemeTemplateLayout($template);

        if ($layout === null) {
            return [];
        }

        return $this->findYoothemeTranslatableNodes($layout);
    }

    /**
     * @param array<string, mixed> $template
     */
    protected function yoothemeTemplateHasStaticText(array $template): bool
    {
        return $this->findYoothemeTemplateTranslatableNodes($template) !== [];
    }

    /**
     * @param array<string, mixed> $template
     */
    protected function buildYoothemeTemplateAssignmentFingerprint(array $template): string
    {
        $copy = $template;
        unset($copy['name'], $copy['layout'], $copy['status']);

        if (isset($copy['query']) && is_array($copy['query'])) {
            unset($copy['query']['lang']);
        }

        $copy = $this->sortRecursive($copy);

        return hash('sha256', json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    protected function generateYoothemeStorageKey(int $length = 8): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $max = strlen($alphabet) - 1;
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $alphabet[random_int(0, $max)];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function sortRecursive(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }

        ksort($value);

        return $value;
    }

    private function isTranslatableYoothemeString(string $field, string $value): bool
    {
        $trimmed = trim($value);

        if ($trimmed === '' || mb_strlen($trimmed) < 2) {
            return false;
        }

        if (in_array($field, self::YOOTHEME_CONFIG_PROPS, true)) {
            return false;
        }

        if (preg_match('/^(http|\/|#|images\/|uk-|el-)/', $trimmed)) {
            return false;
        }

        if (preg_match('/^\{.+\}$/', $trimmed) || str_contains($trimmed, '{{')) {
            return false;
        }

        if (in_array($field, self::YOOTHEME_TEXT_PROPS, true)) {
            return true;
        }

        return mb_strlen($trimmed) > 15
            && str_contains($trimmed, ' ')
            && !preg_match('/(px|vh|vw|%)/', $trimmed);
    }

    private function detectYoothemeTextFormat(string $value): string
    {
        return preg_match('/<[a-z][\s>]/i', $value) ? 'html' : 'plain';
    }

    private function isPotentialSourceText(string $field, string $value): bool
    {
        $trimmed = trim($value);

        if ($trimmed === '' || mb_strlen($trimmed) < 2) {
            return false;
        }

        if (preg_match('/^(http|\/|#|images\/|uk-|el-)/', $trimmed)) {
            return false;
        }

        if (preg_match('/^\{.+\}$/', $trimmed) || str_contains($trimmed, '{{')) {
            return false;
        }

        if (in_array($field, self::YOOTHEME_SOURCE_TEXT_KEYS, true)) {
            return true;
        }

        return $this->isTranslatableYoothemeString($field, $value);
    }

    /**
     * @param array<string, mixed> $source
     * @return list<array{path: string, node_type: string, field: string, text: string, format: string}>
     */
    private function findYoothemeSourceTextNodes(array $source, string $path, string $nodeType): array
    {
        $results = [];

        foreach ($source as $key => $value) {
            $field = (string) $key;

            if (is_string($value)) {
                if ($this->isPotentialSourceText($field, $value)) {
                    $results[] = [
                        'path' => $path,
                        'node_type' => $nodeType,
                        'field' => $field,
                        'text' => $value,
                        'format' => $this->detectYoothemeTextFormat($value),
                    ];
                }

                continue;
            }

            if (is_array($value)) {
                $results = array_merge($results, $this->findYoothemeSourceTextNodes($value, $path . '.' . $field, $nodeType));
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, string> $replacements
     */
    private function applyYoothemeReplacements(array &$node, array $replacements, string $path = 'root'): void
    {
        if (isset($node['props']) && is_array($node['props'])) {
            foreach ($node['props'] as $key => &$value) {
                $fullPath = "{$path}.{$key}";

                if (is_string($value) && array_key_exists($fullPath, $replacements)) {
                    $value = $replacements[$fullPath];
                }
            }
            unset($value);
        }

        if (isset($node['source']) && is_array($node['source'])) {
            $this->applyYoothemeNestedReplacements($node['source'], $replacements, $path . '.source');
        }

        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $index => &$child) {
                if (!is_array($child)) {
                    continue;
                }

                $childType = is_string($child['type'] ?? null) ? $child['type'] : 'unknown';
                $this->applyYoothemeReplacements($child, $replacements, "{$path}>{$childType}[{$index}]");
            }
            unset($child);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, string> $replacements
     */
    private function applyYoothemeNestedReplacements(array &$node, array $replacements, string $path): void
    {
        foreach ($node as $key => &$value) {
            $fullPath = $path . '.' . $key;

            if (is_string($value) && array_key_exists($fullPath, $replacements)) {
                $value = $replacements[$fullPath];
                continue;
            }

            if (is_array($value)) {
                $this->applyYoothemeNestedReplacements($value, $replacements, $fullPath);
            }
        }
        unset($value);
    }
}
