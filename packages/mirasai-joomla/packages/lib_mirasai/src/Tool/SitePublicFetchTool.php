<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Uri\Uri;
use Mirasai\Library\Mirasai;

class SitePublicFetchTool extends AbstractTool
{
    /** @var (callable(string): array<string, mixed>)|null */
    private $fetcher;

    /**
     * No database: the parent constructor is skipped on purpose.
     *
     * @param (callable(string): array<string, mixed>)|null $fetcher test seam; defaults to Joomla's HTTP client
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
            . 'invisible because the article is not published or a page cache serves an old copy (cache_bust=true asks past most page caches). '
            . 'It reads the served HTML only: text hidden with CSS still counts, and content added by JavaScript is not seen. '
            . 'YOOtheme prints the header twice (desktop and mobile), so header and nav texts usually count twice. Read-only.';
    }

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

    public function handle(array $arguments): array
    {
        $expect = PublicPageInspector::normalizeExpect($arguments['expect'] ?? null);

        if (isset($expect['error'])) {
            return $expect;
        }

        $siteUrl = $this->siteUrl ?? Uri::root();
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
     * Joomla's client has no response size limit; PublicPageInspector truncates the body it parses.
     *
     * @return array<string, mixed>
     */
    public function request(string $url): array
    {
        try {
            $http = HttpFactory::getHttp([
                'follow_location' => false,
                'userAgent' => 'MirasAI-public-fetch/' . Mirasai::VERSION,
            ]);
            $response = $http->get($url, ['Accept' => 'text/html,application/xhtml+xml'], 15);
        } catch (\Throwable $exception) {
            return [
                'error' => 'The site could not be reached from its own server: ' . $exception->getMessage(),
                'code' => 'loopback_failed',
            ];
        }

        $headers = [];

        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = is_array($values) ? implode(', ', $values) : (string) $values;
        }

        return [
            'status' => $response->getStatusCode(),
            'headers' => $headers,
            'body' => (string) $response->getBody(),
        ];
    }
}
