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
    public const SITE_MAX_PER_LAYOUT = 5;

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
            . 'Also returns the heading outline. scope=site audits every template and article layout instead and returns, '
            . 'per layout with findings, its counts and at most max_per_layout findings, worst first; clean layouts are only counted. '
            . 'It reads the saved layout: values from Dynamic Sources are not judged, '
            . 'use template/render-check for what renders. Meta titles and descriptions are not checked. Read-only.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'key' => ['type' => 'string', 'description' => 'YOOtheme template key from template/list. Use one of key or article_id.'],
                'article_id' => ['type' => 'integer', 'description' => 'Article with its own YOOtheme layout. Use one of key or article_id.'],
                'scope' => [
                    'type' => 'string',
                    'enum' => ['layout', 'site'],
                    'description' => 'layout (default) audits the one target given; site audits every layout and takes no target.',
                ],
                'max_per_layout' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                    'description' => 'scope=site only: findings listed per layout. Defaults to ' . self::SITE_MAX_PER_LAYOUT . '.',
                ],
            ],
        ];
    }

    public function handle(array $arguments): array
    {
        if (($arguments['scope'] ?? 'layout') === 'site') {
            if (isset($arguments['key']) || isset($arguments['article_id'])) {
                return ['error' => 'scope=site audits every layout; drop key and article_id.', 'code' => 'site_scope_takes_no_target'];
            }

            return self::siteReport($this->auditor(), $this->siteLayouts(), (int) ($arguments['max_per_layout'] ?? self::SITE_MAX_PER_LAYOUT));
        }

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

        return $target + $this->auditor()->audit($layout);
    }

    private function auditor(): YoothemeLayoutAuditor
    {
        $root = defined('JPATH_ROOT') ? rtrim((string) JPATH_ROOT, '/') : '';

        return new YoothemeLayoutAuditor(
            static fn (string $type): array => YoothemeElementDefinitionLoader::load($type),
            static fn (string $image): ?bool => self::localFileExists($root, $image),
        );
    }

    /**
     * Every template, then every article not in the trash whose fulltext holds a YOOtheme layout.
     *
     * @return list<array{selector: array<string, mixed>, label: string, layout: array<string, mixed>}>
     */
    private function siteLayouts(): array
    {
        $layouts = [];

        foreach ($this->yooHelper->loadTemplates() as $key => $template) {
            $layout = is_array($template) ? $this->yooHelper->getTemplateLayout($template) : null;

            if ($layout !== null) {
                $layouts[] = ['selector' => ['key' => (string) $key], 'label' => $this->yooHelper->getTemplateName($template), 'layout' => $layout];
            }
        }

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__content'))
            ->where($this->db->quoteName('fulltext') . ' LIKE ' . $this->db->quote('<!-- {%'))
            ->where($this->db->quoteName('state') . ' <> -2')
            ->order($this->db->quoteName('id'));

        foreach ($this->db->setQuery($query)->loadColumn() ?: [] as $id) {
            $article = $this->yooHelper->loadArticle((int) $id);
            $layout = $article !== null ? $this->yooHelper->getArticleLayout($article) : null;

            if ($layout !== null) {
                $layouts[] = ['selector' => ['article_id' => (int) $id], 'label' => (string) ($article['title'] ?? ''), 'layout' => $layout];
            }
        }

        return $layouts;
    }

    /**
     * One row per layout with findings, worst first; clean layouts only counted.
     *
     * @param list<array{selector: array<string, mixed>, label: string, layout: array<string, mixed>}> $layouts
     * @return array<string, mixed>
     */
    public static function siteReport(YoothemeLayoutAuditor $auditor, array $layouts, int $maxPerLayout): array
    {
        $maxPerLayout = max(1, min(50, $maxPerLayout));
        $rows = [];
        $totals = ['error' => 0, 'warning' => 0, 'notice' => 0];
        $clean = 0;

        foreach ($layouts as $item) {
            $result = $auditor->audit($item['layout']);

            foreach ($result['counts'] as $severity => $count) {
                $totals[$severity] += $count;
            }

            if ($result['findings'] === []) {
                $clean++;
                continue;
            }

            $rows[] = $item['selector'] + [
                'label' => $item['label'],
                'counts' => $result['counts'],
                'findings' => array_slice($result['findings'], 0, $maxPerLayout),
                'more_findings' => max(0, count($result['findings']) - $maxPerLayout),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$b['counts']['error'], $b['counts']['warning'], $b['counts']['notice']] <=> [$a['counts']['error'], $a['counts']['warning'], $a['counts']['notice']]);

        return [
            'scope' => 'site',
            'layouts_audited' => count($layouts),
            'layouts_clean' => $clean,
            'totals' => $totals,
            'layouts' => $rows,
            'note' => 'Audit one layout (scope=layout with its key, post_id or widget_id) for every finding and its heading outline.',
        ];
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
