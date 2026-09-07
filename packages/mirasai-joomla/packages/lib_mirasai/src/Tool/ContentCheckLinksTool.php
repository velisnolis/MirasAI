<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Joomla\Database\ParameterType;

class ContentCheckLinksTool extends AbstractTool
{
    public function getName(): string
    {
        return 'content/check-links';
    }

    public function getDescription(): string
    {
        return 'Scans translated articles for internal links pointing to articles that lack a translation in the same language. Reports broken links and optionally rewrites them to point to the translated version when available.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'dry_run' => ['type' => 'boolean', 'default' => true],
                'if_match' => ['type' => 'string', 'description' => 'ETag from the article-scoped report/preview.'],
                'confirm_guarded_write' => ['type' => 'boolean'],
                'language' => [
                    'type' => 'string',
                    'description' => 'Scan articles in this language (e.g. es-ES). Required.',
                ],
                'fix' => [
                    'type' => 'boolean',
                    'description' => 'If true, rewrite links in YOOtheme layouts to point to the translated article where one exists. Apply requires article_id, dry_run=false, preview if_match and confirm_guarded_write=true. Default: false (report only).',
                ],
                'article_id' => [
                    'type' => 'integer',
                    'description' => 'Scan a single article by ID. If omitted, scans all articles in the given language.',
                ],
            ],
            'required' => ['language'],
        ];
    }

    public function getPermissions(): array
    {
        return [
            'risk_level' => self::RISK_GUARDED_WRITE,
            'idempotent' => true,
        ];
    }

    public function handle(array $arguments): array
    {
        $language = $arguments['language'] ?? '';
        $fix = ($arguments['fix'] ?? false) === true && ($arguments['dry_run'] ?? true) === false;
        if ($fix && (empty($arguments['article_id']) || empty($arguments['if_match']) || ($arguments['confirm_guarded_write'] ?? false) !== true)) {
            return ['error' => 'Link apply requires one article_id, preview if_match and confirm_guarded_write=true.'];
        }
        $singleId = isset($arguments['article_id']) ? (int) $arguments['article_id'] : null;

        if ($language === '') {
            return ['error' => 'language is required.'];
        }

        // Load articles to scan
        $query = $this->db->getQuery(true)
            ->select(['id', 'title', 'introtext', $this->db->quoteName('fulltext', 'fulltext_raw')])
            ->from($this->db->quoteName('#__content'))
            ->where('language = :lang')
            ->where('state >= 0')
            ->bind(':lang', $language);

        if ($singleId) {
            $query->where('id = :aid')
                ->bind(':aid', $singleId, ParameterType::INTEGER);
        }

        $articles = $this->db->setQuery($query)->loadAssocList();

        $report = [];
        $totalBroken = 0;
        $totalFixed = 0;

        foreach ($articles as $article) {
            $articleReport = $this->scanArticle($article, $language, $fix, (string) ($arguments['if_match'] ?? ''));

            if (!empty($articleReport['issues']) || $singleId) {
                $report[] = $articleReport;
                $totalBroken += $articleReport['broken_count'];
                $totalFixed += $articleReport['fixed_count'];
            }
        }

        $errors = array_filter($report, static fn($row) => isset($row['outcome']['error']));
        return ($errors ? ['error' => 'One or more link changes were rejected; inspect details.'] : []) + [
            'language' => $language,
            'articles_scanned' => count($articles),
            'articles_with_issues' => count(array_filter($report, static fn($row) => $row['issues'] !== [])),
            'total_broken_links' => $totalBroken,
            'total_fixed' => $totalFixed,
            'mode' => $fix ? 'fix' : 'report',
            'coverage' => 'Builder navigation props and HTML href attributes. Unresolved SEF routes need a separately verified URL map; no language-prefix rewriting.',
            'details' => $report,
        ];
    }

    /**
     * @param  array<string, mixed> $article
     * @return array<string, mixed>
     */
    private function scanArticle(array $article, string $language, bool $fix, string $ifMatch = ''): array
    {
        $articleId = (int) $article['id'];
        $fulltext = trim($article['fulltext_raw'] ?? '');
        $issues = [];
        $fixedCount = 0;
        $layoutModified = false;
        $layout = null;
        $jsonStart = null;
        $jsonEnd = null;

        // Parse YOOtheme layout if present
        if (str_starts_with($fulltext, '<!-- {')) {
            $jsonEnd = strrpos($fulltext, ' -->');

            if ($jsonEnd !== false) {
                $jsonStart = 5;
                $json = substr($fulltext, $jsonStart, $jsonEnd - $jsonStart);
                $layout = json_decode($json, true);
            }
        }

        // Scan YOOtheme layout for internal links
        if ($layout) {
            $this->scanNode($layout, $language, $issues, true, $layoutModified, 'root');
        }

        $etag = hash('sha256', json_encode([$article, $language, $issues], JSON_THROW_ON_ERROR));
        $outcome = ['status' => 'preview', 'write_performed' => false];

        // If we fixed links, write back
        if ($fix && $layoutModified && $layout) {
            $newJson = json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $newFulltext = '<!-- ' . $newJson . ' -->';

            if (!hash_equals($etag, $ifMatch)) {
                $outcome = ['status' => 'rejected', 'error' => 'stale_etag', 'write_performed' => false];
            } else {
                $rendered = YooThemeTranslationRenderer::render($newFulltext, $language);
                if (($rendered['result']['status'] ?? null) !== 'success') {
                    $outcome = ['status' => 'rejected', 'error' => 'fallback_unverified', 'fallback' => $rendered['result'], 'write_performed' => false];
                } else {
                    $query = $this->db->getQuery(true)
                        ->update($this->db->quoteName('#__content'))
                        ->set($this->db->quoteName('fulltext') . ' = ' . $this->db->quote($rendered['fulltext']))
                        ->set($this->db->quoteName('introtext') . ' = ' . $this->db->quote($rendered['introtext']))
                        ->set($this->db->quoteName('modified') . ' = ' . $this->db->quote(date('Y-m-d H:i:s')))
                        ->where('id = ' . $articleId)
                        ->where('BINARY ' . $this->db->quoteName('fulltext') . ' = BINARY ' . $this->db->quote($article['fulltext_raw']))
                        ->where('BINARY introtext = BINARY ' . $this->db->quote($article['introtext']));
                    $this->db->setQuery($query)->execute();
                    $written = $this->db->getAffectedRows() === 1;
                    $outcome = $written ? ['status' => 'complete', 'write_performed' => true]
                        : ['status' => 'rejected', 'error' => 'stale_content', 'write_performed' => false];
                    if ($written) { $fixedCount = count(array_filter($issues, fn($i) => $i['status'] === 'fixed')); }
                }
            }
        }
        foreach ($issues as &$issue) {
            if ($issue['status'] === 'fixed' && !$outcome['write_performed']) { $issue['status'] = 'fixable'; }
        }
        unset($issue);

        return [
            'etag' => $etag,
            'outcome' => $outcome,
            'article_id' => $articleId,
            'title' => $article['title'],
            'broken_count' => count(array_filter($issues, fn($i) => $i['status'] === 'broken')),
            'fixed_count' => $fixedCount,
            'issues' => $issues,
        ];
    }

    /**
     * @param  array<string, mixed>                             $node
     * @param  list<array{path: string, prop: string, link: string, target_id: int, status: string, detail: string}> &$issues
     */
    private function scanNode(
        array &$node,
        string $language,
        array &$issues,
        bool $fix,
        bool &$modified,
        string $path,
    ): void {
        $rewrite = function (string $link, string $prop) use ($language, &$issues, &$modified, $path, $fix): string {
            $targetId = $this->articleIdFromLink($link);
            if ($targetId === null) {
                if ($link !== '' && !str_starts_with($link, '#') && !preg_match('/^(?:mailto|tel|javascript):/i', $link)) {
                    $issues[] = ['path' => $path, 'prop' => $prop, 'link' => $link, 'status' => 'review', 'detail' => 'URL is outside verified numeric article routes; review a literal URL map.'];
                }
                return $link;
            }
            $result = $this->checkAndFixLink($targetId, $language, $link, $fix);
            if (!$result) { return $link; }
            $issues[] = ['path' => $path, 'prop' => $prop, 'link' => $link, 'target_id' => $targetId] + $result;
            if ($result['status'] === 'fixed') { $modified = true; return $result['new_link']; }
            return $link;
        };
        $processor = new YooThemeLayoutProcessor();
        foreach ($node['props'] ?? [] as $prop => $value) {
            if (!is_string($value) || array_key_exists($prop, $node['source']['props'] ?? [])) { continue; }
            if (in_array($prop, ['link', 'button_link', 'image_link', 'title_link', 'href', 'url'], true)) {
                $node['props'][$prop] = $rewrite($value, $prop);
            } elseif (in_array($prop, $processor->getTextProperties(), true)) {
                $node['props'][$prop] = preg_replace_callback('/<!--.*?-->|<(script|style)\b[^>]*>.*?<\/\1\s*>|<[^>]+>/is',
                    static function ($tag) use ($rewrite, $prop) {
                        if (!preg_match('/^<(?:a|area)\s/i', $tag[0])) { return $tag[0]; }
                        return preg_replace_callback('/(\shref\s*=\s*)(["\x27])(.*?)\2/is',
                            static fn($m) => $m[1] . $m[2] . $rewrite($m[3], $prop) . $m[2], $tag[0]);
                    }, $value);
            }
        }

        // Recurse into children
        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $i => &$child) {
                if (is_array($child)) {
                    $childType = $child['type'] ?? 'unknown';
                    $this->scanNode($child, $language, $issues, $fix, $modified, "{$path}>{$childType}[{$i}]");
                }
            }
        }
    }

    /**
     * @return array{status: string, detail: string, new_link?: string}|null
     */
    protected function checkAndFixLink(int $targetArticleId, string $language, string $currentLink, bool $fix): ?array
    {
        $target = $this->linkTargetArticle($targetArticleId);

        if (!$target) {
            return [
                'status' => 'broken',
                'detail' => "Target article ID:{$targetArticleId} does not exist.",
            ];
        }

        $alreadyTranslated = in_array($target['language'], [$language, '*'], true);
        $translatedId = $alreadyTranslated ? $targetArticleId : $this->findTranslation($targetArticleId, $language);

        if (!$translatedId) {
            return [
                'status' => 'broken',
                'detail' => "Links to \"{$target['title']}\" (ID:{$targetArticleId}, {$target['language']}) which has no {$language} translation.",
            ];
        }

        if (!$fix) {
            return [
                'status' => 'fixable',
                'detail' => "Links to \"{$target['title']}\" (ID:{$targetArticleId}, {$target['language']}). Translation exists: ID:{$translatedId}.",
            ];
        }

        // Only rewrite the query; the fragment is opaque even when it contains &id=.
        [$queryLink, $fragment] = array_pad(explode('#', $currentLink, 2), 2, null);
        $newLink = $alreadyTranslated ? $queryLink
            : preg_replace('/([?&](?:amp;)?)id=\d+(?::[^&#]*)?/', '${1}id=' . $translatedId, $queryLink, 1);
        if (preg_match('/([?&](?:amp;)?)Itemid=(\d+)/', $queryLink, $menuMatch)) {
            $currentMenu = $this->linkMenuItem((int) $menuMatch[2]);
            if (!$currentMenu) { return ['status' => 'review', 'detail' => 'Itemid does not resolve to a menu.']; }
            $translatedMenu = in_array($currentMenu['language'], [$language, '*'], true) ? (int) $currentMenu['id']
                : $this->findTranslation((int) $menuMatch[2], $language, 'com_menus.item');
            if (!$translatedMenu) {
                return ['status' => 'review', 'detail' => 'Article translation exists but Itemid has no verified target menu association.'];
            }
            $newLink = preg_replace('/([?&](?:amp;)?)Itemid=\d+/', '${1}Itemid=' . $translatedMenu, $newLink, 1);
        }
        if ($fragment !== null) { $newLink .= '#' . $fragment; }
        if ($newLink === $currentLink) { return null; }

        return [
            'status' => 'fixed',
            'detail' => "Rewritten from ID:{$targetArticleId} to ID:{$translatedId}.",
            'new_link' => $newLink,
        ];
    }

    protected function linkMenuItem(int $id): ?array
    {
        return $this->db->setQuery('SELECT id, language FROM ' . $this->db->quoteName('#__menu') . ' WHERE id = ' . $id)->loadAssoc();
    }

    protected function linkTargetArticle(int $targetArticleId): ?array
    {
        $query = $this->db->getQuery(true)
            ->select(['id', 'title', 'language'])
            ->from($this->db->quoteName('#__content'))
            ->where('id = :id')
            ->bind(':id', $targetArticleId, ParameterType::INTEGER);

        return $this->db->setQuery($query)->loadAssoc();

    }

    /** Only local com_content article URLs are eligible; arbitrary ?id= is not. */
    protected function articleIdFromLink(string $link): ?int
    {
        $parts = parse_url(html_entity_decode($link, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (!is_array($parts) || isset($parts['host']) || isset($parts['scheme'])) { return null; }
        if (!in_array($parts['path'] ?? '', ['', 'index.php', '/index.php'], true)) { return null; }
        $raw = $parts['query'] ?? '';
        if (preg_match_all('/(?:^|&)id=/', $raw) !== 1 || !preg_match('/(?:^|&)id=\d+(?::[^&]*)?(?:&|$)/', $raw)) { return null; }
        parse_str($raw, $query);
        if (($query['option'] ?? '') !== 'com_content' || ($query['view'] ?? '') !== 'article'
            || !is_string($query['id'] ?? null) || !preg_match('/^(\d+)(?::[^&]*)?$/', $query['id'], $match)) { return null; }
        return (int) $match[1];
    }
}
