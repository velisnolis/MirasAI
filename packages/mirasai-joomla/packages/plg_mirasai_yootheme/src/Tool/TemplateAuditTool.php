<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\AbstractTool;
use Mirasai\Library\Tool\YooThemeHelper;

/**
 * Mirrors mirasai-wp's TemplateAuditTool; targets and storage are Joomla's.
 */
class TemplateAuditTool extends AbstractTool
{
    private YooThemeHelper $yooHelper;

    public function __construct()
    {
        parent::__construct();
        $this->yooHelper = new YooThemeHelper($this->db);
    }

    public function getName(): string
    {
        return 'template/audit';
    }

    public function getDescription(): string
    {
        return 'Audits one stored YOOtheme layout and lists findings by severity, each with its element path and a fix: '
            . 'image files that do not exist and list elements (grid, slideshow…) with no items (error); no h1, a second h1 '
            . 'and skipped heading levels (warning); images without alternative text and headings over 70 characters (notice). '
            . 'Also returns the heading outline. It reads the saved layout: values from Dynamic Sources are not judged, '
            . 'use template/render-check for what renders. Meta titles and descriptions are not checked. Read-only.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'key' => ['type' => 'string', 'description' => 'YOOtheme template key from template/list. Use one of key or article_id.'],
                'article_id' => ['type' => 'integer', 'description' => 'Article with its own YOOtheme layout. Use one of key or article_id.'],
            ],
        ];
    }

    public function handle(array $arguments): array
    {
        $key = trim((string) ($arguments['key'] ?? ''));
        $articleId = (int) ($arguments['article_id'] ?? 0);

        if (($key !== '') === ($articleId > 0)) {
            return ['error' => 'Provide one of key or article_id.', 'code' => $key === '' ? 'missing_target' : 'ambiguous_target'];
        }

        if ($key !== '') {
            $template = $this->yooHelper->loadTemplates()[$key] ?? null;

            if (!is_array($template)) {
                return ['error' => "Template {$key} not found.", 'code' => 'template_not_found'];
            }

            $layout = $this->yooHelper->getTemplateLayout($template);

            if ($layout === null) {
                return ['error' => "Template {$key} has no layout.", 'code' => 'template_layout_missing'];
            }

            $target = ['storage' => 'template', 'key' => $key, 'label' => $this->yooHelper->getTemplateName($template), 'etag' => $this->yooHelper->buildTemplateEtag($template)];
        } else {
            $article = $this->yooHelper->loadArticle($articleId);

            if ($article === null) {
                return ['error' => "Article {$articleId} not found.", 'code' => 'article_not_found'];
            }

            $layout = $this->yooHelper->getArticleLayout($article);

            if ($layout === null) {
                return ['error' => "Article {$articleId} has no YOOtheme layout in fulltext.", 'code' => 'article_layout_missing'];
            }

            $target = ['storage' => 'article', 'article_id' => $articleId, 'label' => (string) ($article['title'] ?? ''), 'etag' => $this->yooHelper->buildArticleLayoutEtag($article)];
        }

        $root = defined('JPATH_ROOT') ? rtrim((string) JPATH_ROOT, '/') : '';
        $auditor = new YoothemeLayoutAuditor(
            static fn (string $type): array => YoothemeElementDefinitionLoader::load($type),
            static fn (string $image): ?bool => self::localFileExists($root, $image),
        );

        return $target + $auditor->audit($layout);
    }

    /** null when the value is not a local path this check can see (a URL, a data URI). */
    public static function localFileExists(string $root, string $image): ?bool
    {
        if ($root === '' || preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $image) === 1) {
            return null;
        }

        // Joomla 4+ media fields append #joomlaImage://… after the path.
        // Decode first, then judge: a check before decoding lets %2e%2e through.
        $path = ltrim(rawurldecode((string) preg_replace('/[?#].*$/', '', $image)), '/');

        if ($path === '' || preg_match('#(^|/)\.\.(/|$)|[\x00\\\\]#', $path) === 1) {
            return null;
        }

        $base = realpath($root);
        $file = realpath($root . '/' . $path);

        if ($file === false) {
            return false;
        }

        // A file that resolves outside the site root (a symlinked uploads folder) is not judged.
        if ($base === false || !str_starts_with($file, $base . '/')) {
            return null;
        }

        return is_file($file);
    }
}
