<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Joomla\Database\ParameterType;
use Mirasai\Library\Tool\AbstractTool;
use Mirasai\Library\Tool\ContentReadTool;
use Mirasai\Library\Tool\YooThemeHelper;

/**
 * Mirrors mirasai-wp's TemplateLayoutInitTool, where the target is a page.
 */
class TemplateLayoutInitTool extends AbstractTool
{
    private YooThemeHelper $yooHelper;

    public function __construct()
    {
        parent::__construct();
        $this->yooHelper = new YooThemeHelper($this->db);
    }

    public function getName(): string
    {
        return 'template/layout-init';
    }

    public function getDescription(): string
    {
        return 'Gives an existing, empty article its first YOOtheme Builder layout, so template/element-add can build on it. '
            . 'An article created outside the Builder has none and answers article_layout_missing. It takes no content: it '
            . 'writes the empty layout and nothing else. Empty means no text in introtext or fulltext; any other article is '
            . 'refused, and so is one that is trashed, archived or open for editing. if_match is the etag of content/read. '
            . 'Returns etag, the if_match of template/element-add with article_id and parent_path root.';
    }

    public function getPermissions(): array
    {
        return [
            'risk_level' => self::RISK_GUARDED_WRITE,
            'idempotent' => false,
        ];
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'article_id' => ['type' => 'integer', 'description' => 'ID of an existing, empty article.'],
                'if_match' => ['type' => 'string', 'description' => 'etag from content/read.'],
                'dry_run' => ['type' => 'boolean', 'description' => 'Preview without writing.'],
                'confirm_guarded_write' => ['type' => 'boolean', 'description' => 'Required with dry_run=false.'],
            ],
            'required' => ['article_id', 'if_match'],
        ];
    }

    public function handle(array $arguments): array
    {
        $articleId = (int) ($arguments['article_id'] ?? 0);
        $ifMatch = trim((string) ($arguments['if_match'] ?? ''));
        $dryRun = ($arguments['dry_run'] ?? null) === true;

        if ($articleId <= 0 || $ifMatch === '') {
            return ['error' => 'article_id and if_match are required.', 'code' => 'missing_arguments'];
        }

        if (!$dryRun && ($arguments['confirm_guarded_write'] ?? null) !== true) {
            return [
                'error' => 'This is a guarded write. Retry with dry_run=true first, then confirm_guarded_write=true if the preview is correct.',
                'code' => 'guarded_write_confirmation_required',
            ];
        }

        $article = ContentReadTool::loadForEtag($this->db, $articleId);

        if ($article === null) {
            return ['error' => "Article {$articleId} not found.", 'code' => 'article_not_found'];
        }

        if (!in_array((int) $article['state'], [0, 1], true)) {
            return [
                'error' => 'A trashed or archived article gets no layout.',
                'code' => 'unsupported_current_state',
                'article_state' => (int) $article['state'],
            ];
        }

        $version = (new YoothemeStyleHelper($this->db))->themeVersion();

        if ($version === null) {
            return ['error' => 'YOOtheme is not installed on this site.', 'code' => 'yootheme_not_installed'];
        }

        $contentEtag = ContentReadTool::contentEtag($article);

        if (!hash_equals($contentEtag, $ifMatch)) {
            return [
                'error' => 'The article changed. Call content/read again and retry with its etag.',
                'code' => 'stale_etag',
                'expected_etag' => $contentEtag,
                'provided_etag' => $ifMatch,
            ];
        }

        $reason = self::notBlankReason($article);

        if ($reason !== null) {
            return [
                'error' => 'The article is not empty, so it gets no new layout. ' . $reason
                    . ' If it already has a layout, read it with template/read.',
                'code' => 'article_not_empty',
            ];
        }

        if ((int) ($article['checked_out'] ?? 0) > 0) {
            return [
                'error' => 'The article is open for editing in Joomla: a save there would overwrite the layout. Close or check it in first.',
                'code' => 'checked_out',
                'checked_out_by' => (int) $article['checked_out'],
            ];
        }

        $layout = self::emptyLayout($version);
        $response = [
            'dry_run' => $dryRun,
            'storage' => 'article',
            'article_id' => $articleId,
            'article_title' => (string) $article['title'],
            'article_state' => (int) $article['state'],
            'layout' => $layout,
        ];

        if ($dryRun) {
            return $response + [
                'action' => 'preview',
                'content_etag' => $contentEtag,
                'note' => 'No changes were written. Retry with confirm_guarded_write=true and the same if_match.',
            ];
        }

        $blank = $article;
        $this->yooHelper->setArticleLayout($article, $layout);

        if (!$this->writeIfStillBlank($blank, (string) $article['fulltext'])) {
            return [
                'error' => 'The article changed while the layout was being written, and was left alone. Call content/read again.',
                'code' => 'stale_etag',
            ];
        }

        $cache = $this->yooHelper->invalidateBuilderCache();
        $written = ContentReadTool::loadForEtag($this->db, $articleId);

        if ($written === null || $this->yooHelper->getArticleLayout($written) !== $layout) {
            return [
                'error' => 'The layout was not stored as written. Read the article with content/read before doing anything else.',
                'code' => 'layout_write_unverified',
            ];
        }

        return $response + [
            'action' => 'created',
            'etag' => $this->yooHelper->buildArticleLayoutEtag($written),
            'content_etag' => ContentReadTool::contentEtag($written),
            'cache' => $cache,
            // An empty layout has no plain HTML, and neither has introtext: they already match.
            'fallback_content' => ['status' => 'regenerated', 'length' => 0],
            'next' => 'template/element-add with article_id, parent_path root and etag as if_match.',
        ];
    }

    /**
     * The write repeats what was checked: the same empty text and nobody editing. A save that
     * lands between the read and this write keeps its text, and the call is refused.
     *
     * @param array<string, mixed> $blank the article as it was read
     */
    private function writeIfStillBlank(array $blank, string $fulltext): bool
    {
        $id = (int) $blank['id'];
        $introtextBefore = (string) $blank['introtext'];
        $fulltextBefore = (string) $blank['fulltext'];
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__content'))
            ->set($this->db->quoteName('fulltext') . ' = :layout')
            ->where($this->db->quoteName('id') . ' = :id')
            ->where($this->db->quoteName('introtext') . ' = :introtext')
            ->where($this->db->quoteName('fulltext') . ' = :before')
            ->where('(' . $this->db->quoteName('checked_out') . ' IS NULL OR ' . $this->db->quoteName('checked_out') . ' = 0)')
            ->bind(':layout', $fulltext)
            ->bind(':id', $id, ParameterType::INTEGER)
            ->bind(':introtext', $introtextBefore)
            ->bind(':before', $fulltextBefore);

        $this->db->setQuery($query)->execute();

        return $this->db->getAffectedRows() === 1;
    }

    /**
     * Why an article cannot take a first layout, or null when it is blank.
     *
     * @param array<string, mixed> $article
     */
    public static function notBlankReason(array $article): ?string
    {
        foreach (['introtext', 'fulltext'] as $field) {
            if (trim((string) ($article[$field] ?? '')) !== '') {
                return "{$field} is not empty.";
            }
        }

        return null;
    }

    /**
     * What the Builder holds for an article with nothing on it. The version keeps YOOtheme from
     * running the migrations of older releases over elements added later.
     *
     * @return array<string, mixed>
     */
    public static function emptyLayout(string $version): array
    {
        return ['type' => 'layout', 'children' => [], 'version' => $version];
    }
}
