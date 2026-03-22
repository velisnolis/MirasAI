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
                'language' => [
                    'type' => 'string',
                    'description' => 'Scan articles in this language (e.g. es-ES). Required.',
                ],
                'fix' => [
                    'type' => 'boolean',
                    'description' => 'If true, rewrite links in YOOtheme layouts to point to the translated article where one exists. Default: false (report only).',
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
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ];
    }

    public function handle(array $arguments): array
    {
        $language = $arguments['language'] ?? '';
        $fix = !empty($arguments['fix']);
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
            $articleReport = $this->scanArticle($article, $language, $fix);

            if (!empty($articleReport['issues'])) {
                $report[] = $articleReport;
                $totalBroken += $articleReport['broken_count'];
                $totalFixed += $articleReport['fixed_count'];
            }
        }

        return [
            'language' => $language,
            'articles_scanned' => count($articles),
            'articles_with_issues' => count($report),
            'total_broken_links' => $totalBroken,
            'total_fixed' => $totalFixed,
            'mode' => $fix ? 'fix' : 'report',
            'details' => $report,
        ];
    }

    /**
     * @param  array<string, mixed> $article
     * @return array<string, mixed>
     */
    private function scanArticle(array $article, string $language, bool $fix): array
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
            $this->scanNode($layout, $language, $issues, $fix, $layoutModified, 'root');
        }

        // If we fixed links, write back
        if ($fix && $layoutModified && $layout) {
            $newJson = json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $newFulltext = '<!-- ' . $newJson . ' -->';

            $query = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__content'))
                ->set($this->db->quoteName('fulltext') . ' = :ft')
                ->set($this->db->quoteName('modified') . ' = :mod')
                ->where('id = :id')
                ->bind(':ft', $newFulltext)
                ->bind(':mod', date('Y-m-d H:i:s'))
                ->bind(':id', $articleId, ParameterType::INTEGER);

            $this->db->setQuery($query)->execute();

            // Regenerate introtext
            $this->regenerateIntrotext($articleId);

            $fixedCount = count(array_filter($issues, fn($i) => $i['status'] === 'fixed'));
        }

        return [
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
        $linkProps = ['link', 'button_link', 'image_link', 'title_link', 'href'];

        foreach ($linkProps as $prop) {
            if (!isset($node['props'][$prop]) || !is_string($node['props'][$prop])) {
                continue;
            }

            $link = $node['props'][$prop];

            // Extract article ID from Joomla internal link
            if (preg_match('/[?&]id=(\d+)/', $link, $m)) {
                $targetId = (int) $m[1];
                $result = $this->checkAndFixLink($targetId, $language, $link, $fix);

                if ($result) {
                    $issues[] = [
                        'path' => $path,
                        'prop' => $prop,
                        'link' => $link,
                        'target_id' => $targetId,
                        'status' => $result['status'],
                        'detail' => $result['detail'],
                    ];

                    if ($result['status'] === 'fixed' && isset($result['new_link'])) {
                        $node['props'][$prop] = $result['new_link'];
                        $modified = true;
                    }
                }
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
    private function checkAndFixLink(int $targetArticleId, string $language, string $currentLink, bool $fix): ?array
    {
        // Check if target article is already in the correct language
        $query = $this->db->getQuery(true)
            ->select(['id', 'title', 'language'])
            ->from($this->db->quoteName('#__content'))
            ->where('id = :id')
            ->bind(':id', $targetArticleId, ParameterType::INTEGER);

        $target = $this->db->setQuery($query)->loadAssoc();

        if (!$target) {
            return [
                'status' => 'broken',
                'detail' => "Target article ID:{$targetArticleId} does not exist.",
            ];
        }

        // If target is already in our language, it's fine
        if ($target['language'] === $language || $target['language'] === '*') {
            return null;
        }

        // Target is in another language — look for a translation in our language
        $translatedId = $this->findTranslation($targetArticleId, $language);

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

        // Rewrite the link
        $newLink = preg_replace('/([?&])id=\d+/', '${1}id=' . $translatedId, $currentLink);

        return [
            'status' => 'fixed',
            'detail' => "Rewritten from ID:{$targetArticleId} to ID:{$translatedId}.",
            'new_link' => $newLink,
        ];
    }

    // findTranslation → now in AbstractTool

    private function regenerateIntrotext(int $articleId): void
    {
        $script = JPATH_ROOT . '/regenerate-introtext.php';

        if (file_exists($script)) {
            shell_exec(sprintf('cd %s && php %s %d 2>&1', escapeshellarg(JPATH_ROOT), escapeshellarg($script), $articleId));
        }
    }
}
