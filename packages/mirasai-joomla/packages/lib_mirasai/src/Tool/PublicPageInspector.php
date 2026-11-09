<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

/**
 * What an anonymous visitor gets for one URL of this site, without the CMS.
 *
 * Mirrored in mirasai-wp. Both copies must stay free of WordPress and Joomla
 * calls so docker/test-public-fetch.php can run them as plain PHP.
 *
 * The request is made by the server, so the URL rule is strict: the site's own
 * host and port, http or https, no credentials. Every redirect hop goes through
 * the same rule, because a redirect is the usual way out of a host check.
 */
final class PublicPageInspector
{
    public const MAX_EXPECT = 10;
    public const MAX_REDIRECTS = 5;
    public const MAX_BODY_BYTES = 2_097_152;

    private const CACHE_HEADERS = [
        'age', 'cache-control', 'expires', 'x-cache', 'x-cache-status', 'x-proxy-cache',
        'cf-cache-status', 'x-litespeed-cache', 'x-varnish', 'x-nginx-cache', 'x-cache-enabled',
    ];

    private const SKIPPED_TAGS = ['script', 'style', 'noscript', 'template', 'svg', 'head'];

    /** Phrasing elements join their text without a space, so «Hel<b>lo</b>» still reads «Hello». */
    private const INLINE_TAGS = [
        'a', 'abbr', 'b', 'bdi', 'bdo', 'cite', 'code', 'data', 'dfn', 'em', 'i', 'kbd', 'mark',
        'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr', 'font',
    ];

    private const REGIONS = ['header', 'nav', 'main', 'footer', 'other'];

    /**
     * Resolve `url` against the site and check it may be fetched.
     *
     * A path that starts with «/» is resolved against the site's scheme, host and
     * port, the way a browser would; it is not prefixed with a subdirectory.
     *
     * @return array{url: string}|array{error: string, code: string}
     */
    public static function resolveUrl(string $url, string $siteUrl, ?string $base = null): array
    {
        $url = trim($url);
        $site = parse_url($siteUrl);

        if (!is_array($site) || !isset($site['scheme'], $site['host'])) {
            return ['error' => 'The site URL could not be determined.', 'code' => 'site_url_unknown'];
        }

        if ($url === '') {
            return ['error' => 'Provide url: an absolute URL of this site or a path that starts with /.', 'code' => 'missing_url'];
        }

        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return ['error' => 'The URL contains spaces or control characters.', 'code' => 'invalid_url'];
        }

        $reference = $base ?? $siteUrl;

        if (str_starts_with($url, '//')) {
            $url = (string) (parse_url($reference, PHP_URL_SCHEME) ?? $site['scheme']) . ':' . $url;
        } elseif (str_starts_with($url, '/')) {
            $url = self::origin($reference) . $url;
        } elseif (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            if ($base === null) {
                return ['error' => 'Provide an absolute URL of this site or a path that starts with /.', 'code' => 'invalid_url'];
            }

            $url = self::resolveRelative($url, $base);
        }

        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return ['error' => 'The URL could not be parsed.', 'code' => 'invalid_url'];
        }

        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return ['error' => 'Only http and https URLs can be fetched.', 'code' => 'unsupported_scheme'];
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['error' => 'URLs with credentials are refused.', 'code' => 'url_credentials_refused'];
        }

        if (strtolower($parts['host']) !== strtolower($site['host'])
            || self::port($parts) !== self::port($site)
        ) {
            return [
                'error' => sprintf('Only URLs on this site (%s) can be fetched.', self::hostLabel($site)),
                'code' => 'foreign_host',
            ];
        }

        unset($parts['fragment']);

        return ['url' => self::build($parts)];
    }

    /**
     * Follow redirects one hop at a time, each hop checked by resolveUrl().
     *
     * $fetch gets one URL and returns
     * {status: int, headers: array<string, string>, body: string} or {error: string, code: string}.
     * Header names must be lowercase.
     *
     * @param callable(string): array<string, mixed> $fetch
     * @return array<string, mixed>
     */
    public static function fetch(string $url, string $siteUrl, callable $fetch): array
    {
        $resolved = self::resolveUrl($url, $siteUrl);

        if (isset($resolved['error'])) {
            return $resolved;
        }

        $current = $resolved['url'];
        $hops = [];

        for ($i = 0; $i <= self::MAX_REDIRECTS; $i++) {
            $response = $fetch($current);

            if (isset($response['error'])) {
                return $response + ['url' => $current, 'redirects' => $hops];
            }

            $status = (int) ($response['status'] ?? 0);
            $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
            $location = (string) ($headers['location'] ?? '');

            if ($status < 300 || $status >= 400 || $location === '') {
                return [
                    'url' => $resolved['url'],
                    'final_url' => $current,
                    'status' => $status,
                    'headers' => $headers,
                    'body' => (string) ($response['body'] ?? ''),
                    'redirects' => $hops,
                ];
            }

            $next = self::resolveUrl($location, $siteUrl, $current);
            $hops[] = ['status' => $status, 'location' => $location];

            if (isset($next['error'])) {
                return [
                    'error' => 'The page redirects outside what this tool may fetch: ' . $next['error'],
                    'code' => 'redirect_refused',
                    'url' => $current,
                    'redirects' => $hops,
                ];
            }

            $current = $next['url'];
        }

        return [
            'error' => sprintf('More than %d redirects.', self::MAX_REDIRECTS),
            'code' => 'too_many_redirects',
            'url' => $current,
            'redirects' => $hops,
        ];
    }

    /**
     * @param array<string, string> $headers lowercase names
     * @param list<string> $expect
     * @return array<string, mixed>
     */
    public static function analyze(string $body, array $headers, array $expect): array
    {
        $truncated = strlen($body) > self::MAX_BODY_BYTES;

        if ($truncated) {
            $body = substr($body, 0, self::MAX_BODY_BYTES);
        }

        $page = self::parse($body);
        $text = $page['regions'];
        $excerptSource = $text['main'] !== '' ? $text['main'] : trim(implode(' ', $text));

        $result = [
            'content_type' => $headers['content-type'] ?? null,
            'title' => $page['title'],
            'lang' => $page['lang'],
            'noindex' => $page['noindex'] || str_contains(strtolower($headers['x-robots-tag'] ?? ''), 'noindex'),
            'has_main_region' => $page['has_main'],
            'excerpt' => self::excerpt($excerptSource, 300),
            'cache' => self::cacheSignals($headers, $body),
            'yootheme' => self::yoothemeLayouts($body),
            'body_truncated' => $truncated,
        ];

        if (!$page['parsed']) {
            $result['regions_available'] = false;
        }

        if ($expect !== []) {
            $result['expect'] = self::matchExpected($text, $expect);
        }

        return $result;
    }

    /**
     * @param mixed $expect
     * @return list<string>|array{error: string, code: string}
     */
    public static function normalizeExpect(mixed $expect): array
    {
        if ($expect === null) {
            return [];
        }

        if (!is_array($expect) || !array_is_list($expect)) {
            return ['error' => 'expect must be a list of strings.', 'code' => 'invalid_expect'];
        }

        if (count($expect) > self::MAX_EXPECT) {
            return ['error' => sprintf('expect takes at most %d texts.', self::MAX_EXPECT), 'code' => 'too_many_expect'];
        }

        $texts = [];

        foreach ($expect as $item) {
            $normalized = is_string($item) ? self::normalizeText($item) : '';

            if ($normalized === '') {
                return ['error' => 'Every expect entry must be a non-empty string.', 'code' => 'invalid_expect'];
            }

            $texts[] = $normalized;
        }

        return $texts;
    }

    public static function normalizeText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @return array{parsed: bool, title: ?string, lang: ?string, noindex: bool, has_main: bool, regions: array<string, string>}
     */
    private static function parse(string $html): array
    {
        $regions = array_fill_keys(self::REGIONS, '');

        if (!class_exists(\DOMDocument::class) || trim($html) === '') {
            $regions['other'] = self::normalizeText(strip_tags($html));

            return ['parsed' => false, 'title' => self::regexTitle($html), 'lang' => null, 'noindex' => false, 'has_main' => false, 'regions' => $regions];
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $title = null;
        $noindex = false;

        foreach ($document->getElementsByTagName('title') as $node) {
            $title = self::normalizeText($node->textContent);
            break;
        }

        foreach ($document->getElementsByTagName('meta') as $node) {
            if (strtolower($node->getAttribute('name')) === 'robots'
                && str_contains(strtolower($node->getAttribute('content')), 'noindex')
            ) {
                $noindex = true;
            }
        }

        $htmlNode = $document->getElementsByTagName('html')->item(0);
        $lang = $htmlNode instanceof \DOMElement && $htmlNode->getAttribute('lang') !== ''
            ? $htmlNode->getAttribute('lang')
            : null;

        $buffers = array_fill_keys(self::REGIONS, '');
        $hasMain = false;
        $root = $document->getElementsByTagName('body')->item(0) ?? $document->documentElement;

        if ($root !== null) {
            self::walk($root, 'other', $buffers, $hasMain);
        }

        foreach ($buffers as $region => $buffer) {
            $regions[$region] = self::normalizeText($buffer);
        }

        return ['parsed' => true, 'title' => $title !== '' ? $title : null, 'lang' => $lang, 'noindex' => $noindex, 'has_main' => $hasMain, 'regions' => $regions];
    }

    /** @param array<string, string> $buffers */
    private static function walk(\DOMNode $node, string $region, array &$buffers, bool &$hasMain): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $buffers[$region] .= $child->data;
                continue;
            }

            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::SKIPPED_TAGS, true) || $child->hasAttribute('hidden')) {
                continue;
            }

            if ($tag === 'br') {
                $buffers[$region] .= ' ';
                continue;
            }

            $childRegion = self::regionOf($child) ?? $region;

            if ($childRegion === 'main') {
                $hasMain = true;
            }

            $block = !in_array($tag, self::INLINE_TAGS, true);

            if ($block) {
                $buffers[$childRegion] .= ' ';
            }

            if ($tag === 'img' && $child->getAttribute('alt') !== '') {
                // Alt text is what a screen reader announces; count it as visible text.
                $buffers[$childRegion] .= ' ' . $child->getAttribute('alt') . ' ';
            }

            self::walk($child, $childRegion, $buffers, $hasMain);

            if ($block) {
                $buffers[$childRegion] .= ' ';
            }
        }
    }

    private static function regionOf(\DOMElement $element): ?string
    {
        $tag = strtolower($element->tagName);
        $role = strtolower($element->getAttribute('role'));
        $id = strtolower($element->getAttribute('id'));

        if ($tag === 'nav' || $role === 'navigation') {
            return 'nav';
        }

        if ($tag === 'main' || $role === 'main' || $id === 'tm-main') {
            return 'main';
        }

        // An article or section can carry its own <header>/<footer>; only the
        // page-level ones are regions, so ignore them once inside main.
        if (($tag === 'header' || $role === 'banner') && !self::insideMain($element)) {
            return 'header';
        }

        if (($tag === 'footer' || $role === 'contentinfo') && !self::insideMain($element)) {
            return 'footer';
        }

        return null;
    }

    private static function insideMain(\DOMElement $element): bool
    {
        for ($node = $element->parentNode; $node instanceof \DOMElement; $node = $node->parentNode) {
            if (strtolower($node->tagName) === 'main' || strtolower($node->getAttribute('role')) === 'main' || strtolower($node->getAttribute('id')) === 'tm-main') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $regions
     * @param list<string> $expect
     * @return list<array<string, mixed>>
     */
    private static function matchExpected(array $regions, array $expect): array
    {
        $matches = [];

        foreach ($expect as $needle) {
            $lower = mb_strtolower($needle, 'UTF-8');
            $found = [];
            $total = 0;

            foreach ($regions as $region => $text) {
                $count = $text === '' ? 0 : mb_substr_count(mb_strtolower($text, 'UTF-8'), $lower, 'UTF-8');

                if ($count > 0) {
                    $found[$region] = $count;
                    $total += $count;
                }
            }

            $matches[] = ['text' => $needle, 'found' => $total > 0, 'count' => $total, 'regions' => (object) $found];
        }

        return $matches;
    }

    /**
     * Which YOOtheme layouts rendered the page. The layout element prints «<!-- Builder #prefix -->»:
     * template-<key> for a template, page for the page's own layout, header and footer for those areas.
     * null when no Builder layout rendered the page, or an HTML minifier dropped the comments.
     *
     * @return array{layouts: list<array<string, string>>, wordpress_post_id: ?int}|null
     */
    public static function yoothemeLayouts(string $html): ?array
    {
        if (preg_match_all('/<!--\s*Builder #([A-Za-z0-9_-]+)\s*-->/', $html, $matches) === 0) {
            return null;
        }

        $layouts = [];

        foreach (array_values(array_unique($matches[1])) as $prefix) {
            $layout = ['prefix' => $prefix];

            if (str_starts_with($prefix, 'template-')) {
                $layout += ['kind' => 'template', 'key' => substr($prefix, 9)];
            } elseif ($prefix === 'page') {
                $layout['kind'] = 'own_layout';
            } else {
                $layout['kind'] = 'area';
            }

            $layouts[] = $layout;
        }

        // WordPress body classes name the post: postid-12 for posts, page-id-12 for pages.
        $postId = preg_match('/<body\b[^>]*\bclass="[^"]*\b(?:postid|page-id)-(\d+)\b/i', $html, $m) === 1 ? (int) $m[1] : null;

        return ['layouts' => $layouts, 'wordpress_post_id' => $postId];
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private static function cacheSignals(array $headers, string $body): array
    {
        $signals = [];

        foreach (self::CACHE_HEADERS as $name) {
            if (isset($headers[$name]) && $headers[$name] !== '') {
                $signals[$name] = $headers[$name];
            }
        }

        $markers = [];

        if (str_contains($body, 'This website is like a Rocket')) {
            $markers[] = 'wp_rocket';
        }

        return ['headers' => (object) $signals, 'html_markers' => $markers];
    }

    private static function excerpt(string $text, int $length): string
    {
        return mb_strlen($text, 'UTF-8') > $length ? rtrim(mb_substr($text, 0, $length, 'UTF-8')) . '…' : $text;
    }

    private static function regexTitle(string $html): ?string
    {
        return preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) === 1 ? self::normalizeText(strip_tags($m[1])) : null;
    }

    /**
     * The explicit port, or null for the scheme's default. http and https of one
     * host count as the same site, so a site saved as http that redirects to
     * https can still be checked.
     *
     * @param array<string, mixed> $parts
     */
    private static function port(array $parts): ?int
    {
        if (!isset($parts['port'])) {
            return null;
        }

        $port = (int) $parts['port'];
        $default = strtolower((string) ($parts['scheme'] ?? '')) === 'http' ? 80 : 443;

        return $port === $default ? null : $port;
    }

    /** @param array<string, mixed> $parts */
    private static function hostLabel(array $parts): string
    {
        return (string) $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return '';
        }

        return strtolower((string) ($parts['scheme'] ?? 'https')) . '://' . ($parts['host'] ?? '')
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private static function resolveRelative(string $relative, string $base): string
    {
        $parts = parse_url($base);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '/') : '/';
        $path = $path !== '' ? $path : '/';

        // RFC 3986 §5.2.2: a reference that is only a query or a fragment keeps the whole current path.
        if (str_starts_with($relative, '?')) {
            return self::origin($base) . $path . $relative;
        }

        if (str_starts_with($relative, '#')) {
            return self::origin($base) . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
        }

        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);

        return self::origin($base) . ($directory !== '' ? $directory : '/') . $relative;
    }

    /** @param array<string, mixed> $parts */
    private static function build(array $parts): string
    {
        $url = strtolower((string) $parts['scheme']) . '://' . $parts['host'];

        if (isset($parts['port'])) {
            $url .= ':' . $parts['port'];
        }

        $url .= (string) ($parts['path'] ?? '');

        if (($parts['path'] ?? '') === '') {
            $url .= '/';
        }

        if (isset($parts['query']) && $parts['query'] !== '') {
            $url .= '?' . $parts['query'];
        }

        return $url;
    }
}
