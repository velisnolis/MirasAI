<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Mirasai\Library\Mirasai;

class SitePublicFetchTool extends AbstractTool
{
    /** @var (callable(string): array<string, mixed>)|null */
    private $fetcher;

    /** Address every request connects to, whatever the URL host resolves to. */
    private ?string $pinnedAddress = null;

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

        if ($this->siteUrl !== null) {
            $siteUrl = $this->siteUrl;
        } else {
            $target = self::siteTarget(
                (string) Factory::getApplication()->get('live_site', ''),
                Uri::root(),
                isset($_SERVER['SERVER_ADDR']) ? (string) $_SERVER['SERVER_ADDR'] : null,
            );

            if (isset($target['error'])) {
                return $target;
            }

            $siteUrl = $target['site_url'];
            $this->pinnedAddress = $target['pin'];
        }

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
     * Where the site is and which address to connect to.
     *
     * Without live_site, Uri::root() comes from the request's Host header. On a
     * server where this site is the default virtual host, any Host reaches
     * Joomla, so the host alone must not decide where the server connects.
     * The connection is pinned to the address that received this request. A
     * port in the Host header is refused: Apache with UseCanonicalName Off
     * copies it into SERVER_PORT too, so there is nothing to check it against.
     *
     * @return array{site_url: string, pin: ?string}|array{error: string, code: string}
     */
    public static function siteTarget(string $liveSite, string $requestRoot, ?string $serverAddr): array
    {
        if (trim($liveSite) !== '') {
            return ['site_url' => rtrim(trim($liveSite), '/') . '/', 'pin' => null];
        }

        $parts = parse_url($requestRoot);

        if (!is_array($parts) || !isset($parts['host'])) {
            return ['error' => 'The site URL could not be determined.', 'code' => 'site_url_unknown'];
        }

        $defaultPort = strtolower((string) ($parts['scheme'] ?? '')) === 'http' ? 80 : 443;

        if (isset($parts['port']) && (int) $parts['port'] !== $defaultPort) {
            return [
                'error' => 'The site answers on a non-default port that only the Host header names. Set live_site in Global Configuration to fetch from this site.',
                'code' => 'site_url_untrusted',
            ];
        }

        if ($serverAddr === null || filter_var($serverAddr, FILTER_VALIDATE_IP) === false) {
            return [
                'error' => 'The server address is unknown, so the site host cannot be trusted. Set live_site in Global Configuration.',
                'code' => 'site_url_untrusted',
            ];
        }

        $host = trim((string) $parts['host'], '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false ? $host !== $serverAddr : preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/i', $host) !== 1) {
            return [
                'error' => 'The Host header is not a plain host name of this server. Set live_site in Global Configuration to fetch from this site.',
                'code' => 'site_url_untrusted',
            ];
        }

        return ['site_url' => $requestRoot, 'pin' => $serverAddr];
    }

    /**
     * Plain cURL rather than Joomla's client: it can pin the address and stop at the size limit.
     *
     * @return array<string, mixed>
     */
    public function request(string $url): array
    {
        if (!function_exists('curl_init')) {
            return ['error' => 'The PHP curl extension is not available.', 'code' => 'curl_unavailable'];
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port = (int) ($parts['port'] ?? ($scheme === 'http' ? 80 : 443));
        $headers = [];
        $body = '';
        $limit = PublicPageInspector::MAX_BODY_BYTES + 1;

        $handle = curl_init($url);
        $options = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'MirasAI-public-fetch/' . Mirasai::VERSION,
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                } elseif (preg_match('#^HTTP/#', $line) === 1) {
                    $headers = [];
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $limit): int {
                $body .= $chunk;

                // Returning a short count aborts the transfer once enough is in.
                return strlen($body) >= $limit ? 0 : strlen($chunk);
            },
        ];

        if ($this->pinnedAddress !== null) {
            // Empty source host and port: every connection goes to the pinned
            // address, whatever host cURL reads from the URL (an IP literal too).
            $address = str_contains($this->pinnedAddress, ':') ? '[' . $this->pinnedAddress . ']' : $this->pinnedAddress;
            $options[CURLOPT_CONNECT_TO] = ['::' . $address . ':' . $port];
        }

        curl_setopt_array($handle, $options);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);

        if ($ok === false && !(strlen($body) >= $limit && $status > 0)) {
            return [
                'error' => 'The site could not be reached from its own server: ' . $error,
                'code' => 'loopback_failed',
            ];
        }

        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
}
