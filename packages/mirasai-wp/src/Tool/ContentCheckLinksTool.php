<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class ContentCheckLinksTool extends AbstractTool
{
    private WordPressTranslationHelper $translations;

    public function __construct(?WordPressTranslationHelper $translations = null)
    {
        $this->translations = $translations ?? new WordPressTranslationHelper();
    }

    public function getName(): string
    {
        return 'content/check-links';
    }

    public function getDescription(): string
    {
        return 'Scans WordPress content for internal links that point to missing, unpublished, unresolved, or wrong-language posts. Read-only report mode.';
    }

    public function getSurface(): string
    {
        return 'essential';
    }

    /**
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'language' => [
                    'type' => 'string',
                    'description' => 'Scan content in this language when Polylang or WPML is active. If omitted, scans default-language content or all content when no provider is active.',
                ],
                'post_id' => [
                    'type' => 'integer',
                    'description' => 'Scan a single post/page by ID.',
                ],
                'post_type' => [
                    'type' => 'string',
                    'description' => 'Post type to scan. Defaults to all public REST-enabled post types.',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Post status to scan. Defaults to publish.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 500,
                    'description' => 'Maximum posts to scan. Default 100.',
                ],
                'include_unresolved_internal' => [
                    'type' => 'boolean',
                    'description' => 'Report internal links that WordPress cannot resolve to a post. Defaults to true.',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        $language = isset($arguments['language']) && is_string($arguments['language']) && trim($arguments['language']) !== ''
            ? trim($arguments['language'])
            : $this->translations->defaultLanguage();
        $includeUnresolved = !array_key_exists('include_unresolved_internal', $arguments)
            || !empty($arguments['include_unresolved_internal']);
        $posts = $this->postsToScan($arguments, $language);
        $details = [];
        $totalIssues = 0;

        foreach ($posts as $post) {
            $report = $this->scanPost($post, $language, $includeUnresolved);
            if ($report['issue_count'] === 0) {
                continue;
            }

            $details[] = $report;
            $totalIssues += $report['issue_count'];
        }

        return [
            'ok' => true,
            'mode' => 'report',
            'read_only' => true,
            'language' => $language,
            'translation_provider' => $this->translations->provider(),
            'posts_scanned' => count($posts),
            'posts_with_issues' => count($details),
            'total_issues' => $totalIssues,
            'issues_by_type' => $this->issuesByType($details),
            'details' => $details,
        ];
    }

    /**
     * @return list<\WP_Post>
     */
    private function postsToScan(array $arguments, ?string $language): array
    {
        if (isset($arguments['post_id'])) {
            $post = get_post((int) $arguments['post_id']);

            return $post instanceof \WP_Post ? [$post] : [];
        }

        $limit = isset($arguments['limit']) ? max(1, min(500, (int) $arguments['limit'])) : 100;
        $status = isset($arguments['status']) && is_string($arguments['status']) ? $arguments['status'] : 'publish';
        $postType = isset($arguments['post_type']) && is_string($arguments['post_type']) && $arguments['post_type'] !== ''
            ? $arguments['post_type']
            : $this->defaultPostTypes();

        $queryArgs = [
            'post_type' => $postType,
            'post_status' => $status,
            'posts_per_page' => $limit,
            'orderby' => 'modified',
            'order' => 'DESC',
            'no_found_rows' => true,
        ];

        if ($language !== null && $this->translations->provider()['name'] === 'polylang') {
            $queryArgs['lang'] = $language;
        }

        $query = new \WP_Query($queryArgs);
        $posts = [];

        foreach ($query->posts as $post) {
            if (!$post instanceof \WP_Post) {
                continue;
            }

            if ($language !== null && $this->translations->provider()['name'] === 'wpml') {
                $postLanguage = $this->translations->postLanguage((int) $post->ID, (string) $post->post_type);
                if ($postLanguage !== $language) {
                    continue;
                }
            }

            $posts[] = $post;
        }

        return $posts;
    }

    /**
     * @return array<string, mixed>
     */
    private function scanPost(\WP_Post $post, ?string $scanLanguage, bool $includeUnresolved): array
    {
        $links = $this->extractLinks((string) $post->post_content);
        $issues = [];

        foreach ($links as $link) {
            $issue = $this->checkLink($post, $link, $scanLanguage, $includeUnresolved);
            if ($issue !== null) {
                $issues[] = $issue;
            }
        }

        return [
            'post_id' => (int) $post->ID,
            'title' => get_the_title($post),
            'post_type' => (string) $post->post_type,
            'language' => $this->translations->postLanguage((int) $post->ID, (string) $post->post_type),
            'links_scanned' => count($links),
            'issue_count' => count($issues),
            'issues' => $issues,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function checkLink(\WP_Post $sourcePost, string $link, ?string $scanLanguage, bool $includeUnresolved): ?array
    {
        if (!$this->isInternalUrl($link)) {
            return null;
        }

        $absoluteUrl = $this->absoluteUrl($link);
        $targetId = $this->resolvePostId($absoluteUrl);

        if ($targetId <= 0) {
            return $includeUnresolved ? [
                'type' => 'internal_unresolved',
                'severity' => 'medium',
                'link' => $link,
                'detail' => 'Internal URL could not be resolved to a WordPress post, page, or public custom post.',
            ] : null;
        }

        $target = get_post($targetId);
        if (!$target instanceof \WP_Post) {
            return [
                'type' => 'target_missing',
                'severity' => 'high',
                'link' => $link,
                'target_id' => $targetId,
                'detail' => "Target post ID {$targetId} does not exist.",
            ];
        }

        if (!in_array((string) $target->post_status, ['publish', 'private'], true)) {
            return [
                'type' => 'target_unpublished',
                'severity' => 'medium',
                'link' => $link,
                'target_id' => $targetId,
                'target_status' => (string) $target->post_status,
                'detail' => "Target post ID {$targetId} exists but is not published.",
            ];
        }

        $sourceLanguage = $scanLanguage
            ?? $this->translations->postLanguage((int) $sourcePost->ID, (string) $sourcePost->post_type);

        if ($sourceLanguage === null || !$this->translations->provider()['active']) {
            return null;
        }

        $targetLanguage = $this->translations->postLanguage($targetId, (string) $target->post_type);
        if ($targetLanguage === null || $targetLanguage === $sourceLanguage) {
            return null;
        }

        $targetTranslations = $this->translations->postTranslations($targetId, (string) $target->post_type);
        $translatedId = $targetTranslations[$sourceLanguage] ?? null;

        if (is_int($translatedId) && $translatedId > 0) {
            return [
                'type' => 'wrong_language_fixable',
                'severity' => 'medium',
                'link' => $link,
                'target_id' => $targetId,
                'target_language' => $targetLanguage,
                'expected_language' => $sourceLanguage,
                'translated_id' => $translatedId,
                'translated_link' => get_permalink($translatedId),
                'detail' => "Link points to {$targetLanguage} content, but a {$sourceLanguage} translation exists.",
            ];
        }

        return [
            'type' => 'wrong_language_no_translation',
            'severity' => 'high',
            'link' => $link,
            'target_id' => $targetId,
            'target_language' => $targetLanguage,
            'expected_language' => $sourceLanguage,
            'detail' => "Link points to {$targetLanguage} content and no {$sourceLanguage} translation was found.",
        ];
    }

    /**
     * @return list<string>
     */
    private function extractLinks(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $links = [];

        if (class_exists('\DOMDocument')) {
            $dom = new \DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            foreach ($dom->getElementsByTagName('a') as $node) {
                $href = $node->getAttribute('href');
                if ($href !== '') {
                    $links[] = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }

        if ($links === [] && preg_match_all("/<a\\s+[^>]*href=[\"']([^\"']+)[\"']/i", $html, $matches)) {
            $links = array_map(
                static fn(string $href): string => html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                $matches[1]
            );
        }

        return array_values(array_unique(array_filter($links, static fn(string $link): bool => trim($link) !== '')));
    }

    private function isInternalUrl(string $url): bool
    {
        $url = trim($url);

        if ($url === '' || str_starts_with($url, '#') || preg_match('/^(mailto|tel|sms|javascript):/i', $url)) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            return true;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return true;
        }

        $homeHost = parse_url(home_url('/'), PHP_URL_HOST);

        return is_string($homeHost) && strtolower($host) === strtolower($homeHost);
    }

    private function absoluteUrl(string $url): string
    {
        $url = trim($url);

        if (str_starts_with($url, '//')) {
            $scheme = is_ssl() ? 'https:' : 'http:';

            return $scheme . $url;
        }

        if (preg_match('/^https?:\\/\\//i', $url)) {
            return $url;
        }

        if (str_starts_with($url, '/')) {
            return home_url($url);
        }

        return home_url('/' . ltrim($url, '/'));
    }

    private function resolvePostId(string $url): int
    {
        $postId = function_exists('url_to_postid') ? (int) url_to_postid($url) : 0;
        if ($postId > 0) {
            return $postId;
        }

        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return 0;
        }

        parse_str($query, $params);
        foreach (['p', 'page_id', 'attachment_id'] as $key) {
            if (isset($params[$key]) && (int) $params[$key] > 0) {
                return (int) $params[$key];
            }
        }

        return 0;
    }

    /**
     * @param list<array<string, mixed>> $details
     * @return array<string, int>
     */
    private function issuesByType(array $details): array
    {
        $counts = [];

        foreach ($details as $postReport) {
            if (!is_array($postReport['issues'] ?? null)) {
                continue;
            }

            foreach ($postReport['issues'] as $issue) {
                $type = is_string($issue['type'] ?? null) ? $issue['type'] : 'unknown';
                $counts[$type] = ($counts[$type] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    private function defaultPostTypes(): array
    {
        return array_values(get_post_types(['show_in_rest' => true, 'public' => true], 'names'));
    }
}
