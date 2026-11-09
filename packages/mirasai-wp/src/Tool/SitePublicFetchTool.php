<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class SitePublicFetchTool extends AbstractTool
{
    /** @var (callable(string): array<string, mixed>)|null */
    private $fetcher;

    /**
     * @param (callable(string): array<string, mixed>)|null $fetcher test seam; defaults to wp_safe_remote_get
     */
    public function __construct(private ?string $siteUrl = null, ?callable $fetcher = null)
    {
        $this->fetcher = $fetcher;
    }

    public function getName(): string
    {
        return 'site/public-fetch';
    }

    public function getDescription(): string
    {
        return 'Fetches one URL of this site as an anonymous visitor (no cookies, no login) and reports status, redirects, title, '
            . 'noindex, cache headers and a short excerpt. With expect, checks up to 10 texts and says where each one appears: '
            . 'header, nav, main, footer or other. Use it after a write to confirm what visitors get: a saved change can still be '
            . 'invisible because the post is not published or a page cache serves an old copy (cache_bust=true asks past most page caches). '
            . 'It reads the served HTML only: text hidden with CSS still counts, and content added by JavaScript is not seen. '
            . 'YOOtheme prints the header twice (desktop and mobile), so header and nav texts usually count twice. '
            . 'yootheme.layouts names the layouts that rendered the page: kind=template with the key to pass to template/* tools, '
            . 'or own_layout for the page\'s own layout; null when no Builder layout rendered the page, or an HTML minifier stripped the markers. Read-only.';
    }

    /**
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'Absolute URL on this site, or a path starting with / resolved against the site host.',
                ],
                'expect' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'maxItems' => PublicPageInspector::MAX_EXPECT,
                    'description' => 'Texts that should appear on the page. Matching ignores case, tags and repeated whitespace.',
                ],
                'cache_bust' => [
                    'type' => 'boolean',
                    'description' => 'Add a random mirasai_nocache query parameter so most page caches miss. Defaults to false: you see what visitors see.',
                ],
            ],
            'required' => ['url'],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        $expect = PublicPageInspector::normalizeExpect($arguments['expect'] ?? null);

        if (isset($expect['error'])) {
            return $expect;
        }

        $siteUrl = $this->siteUrl ?? home_url('/');
        $url = (string) ($arguments['url'] ?? '');
        $cacheBust = ($arguments['cache_bust'] ?? false) === true;

        if ($cacheBust) {
            $resolved = PublicPageInspector::resolveUrl($url, $siteUrl);

            if (isset($resolved['error'])) {
                return $resolved;
            }

            $url = $resolved['url'] . (str_contains($resolved['url'], '?') ? '&' : '?') . 'mirasai_nocache=' . bin2hex(random_bytes(4));
        }

        $fetched = PublicPageInspector::fetch($url, $siteUrl, $this->fetcher ?? [$this, 'request']);

        if (isset($fetched['error'])) {
            return $fetched;
        }

        $headers = $fetched['headers'];
        $analysis = PublicPageInspector::analyze($fetched['body'], $headers, $expect);
        unset($fetched['body'], $fetched['headers']);

        return $fetched + ['cache_bust' => $cacheBust] + $analysis;
    }

    /**
     * @return array<string, mixed>
     */
    public function request(string $url): array
    {
        $response = wp_safe_remote_get($url, [
            'timeout' => 15,
            'redirection' => 0,
            'limit_response_size' => PublicPageInspector::MAX_BODY_BYTES + 1,
            'cookies' => [],
            'headers' => ['Accept' => 'text/html,application/xhtml+xml'],
            'user-agent' => 'MirasAI-public-fetch/' . (defined('MIRASAI_WP_VERSION') ? MIRASAI_WP_VERSION : 'dev'),
        ]);

        if (is_wp_error($response)) {
            return [
                'error' => 'The site could not be reached from its own server: ' . $response->get_error_message(),
                'code' => 'loopback_failed',
            ];
        }

        $headers = [];

        foreach (wp_remote_retrieve_headers($response) as $name => $value) {
            $headers[strtolower((string) $name)] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        return [
            'status' => (int) wp_remote_retrieve_response_code($response),
            'headers' => $headers,
            'body' => (string) wp_remote_retrieve_body($response),
        ];
    }
}
