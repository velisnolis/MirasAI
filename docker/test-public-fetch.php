<?php
/**
 * Test: site/public-fetch on both hosts — the URL rule, redirects and the HTML
 * reading, with a fake fetcher instead of the network.
 *
 * Run from the repo root:
 *   php docker/test-public-fetch.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

require_once $root . '/packages/mirasai-wp/src/Tool/ToolInterface.php';
require_once $root . '/packages/mirasai-wp/src/Tool/AbstractTool.php';
require_once $root . '/packages/mirasai-wp/src/Tool/PublicPageInspector.php';
require_once $root . '/packages/mirasai-wp/src/Tool/SitePublicFetchTool.php';
require_once $root . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/ToolInterface.php';
require_once $root . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/AbstractTool.php';
require_once $root . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/PublicPageInspector.php';
require_once $root . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/SitePublicFetchTool.php';

$fails = 0;
$count = 0;
function check(bool $ok, string $name): void
{
    global $fails, $count;
    $count++;
    if (!$ok) {
        $fails++;
        echo "FAIL: $name\n";
    }
}

/** @return callable(string): array<string, mixed> */
function pages(array $routes, array &$seen = []): callable
{
    return static function (string $url) use ($routes, &$seen): array {
        $seen[] = $url;
        $key = preg_replace('/mirasai_nocache=[0-9a-f]+/', 'mirasai_nocache=X', $url);

        return $routes[$key] ?? ['status' => 404, 'headers' => [], 'body' => '<title>Not found</title>'];
    };
}

$html = <<<'HTML'
<!doctype html>
<html lang="ca-ES">
<head>
  <title> Qui   som &amp; equip </title>
  <meta name="robots" content="noindex, follow">
  <style>.x { content: "Amagat a l'estil"; }</style>
  <script>var t = "Amagat al script";</script>
</head>
<body>
  <header class="tm-header">
    <div class="logo">Miras</div>
    <nav><ul><li><a href="/qui-som">Qui som</a></li><li><a href="/serveis">Serveis</a></li></ul></nav>
  </header>
  <main id="tm-main">
    <article>
      <header><h1>Qui som</h1></header>
      <p>Som un estudi de <b>web</b>s a Vic.</p>
      <p>Hel<strong>lo</strong></p>
      <p>Primer</p><p>Segon</p>
      <p>Què fem&nbsp;i   com</p>
      <img src="/a.jpg" alt="Equip al taller">
      <div hidden>Text amagat amb hidden</div>
      <p>Serveis per a pimes. Serveis de manteniment.</p>
    </article>
  </main>
  <footer><p>© Miras · Serveis</p></footer>
  <!-- This website is like a Rocket, isn't it? -->
</body>
</html>
HTML;

foreach ([
    'wordpress' => ['inspector' => \Mirasai\WordPress\Tool\PublicPageInspector::class, 'tool' => \Mirasai\WordPress\Tool\SitePublicFetchTool::class],
    'joomla' => ['inspector' => \Mirasai\Library\Tool\PublicPageInspector::class, 'tool' => \Mirasai\Library\Tool\SitePublicFetchTool::class],
] as $host => $classes) {
    $I = $classes['inspector'];
    $site = 'https://example.cat/';

    // URL rule.
    check($I::resolveUrl('https://example.cat/qui-som#equip', $site) === ['url' => 'https://example.cat/qui-som'], "$host: same host, fragment dropped");
    check($I::resolveUrl('https://EXAMPLE.cat/a?b=1', $site) === ['url' => 'https://EXAMPLE.cat/a?b=1'], "$host: host compared without case");
    check($I::resolveUrl('/serveis', $site) === ['url' => 'https://example.cat/serveis'], "$host: path resolved against the site");
    check($I::resolveUrl('/x', 'https://example.cat/sub/') === ['url' => 'https://example.cat/x'], "$host: / is host-relative, like a browser");
    check($I::resolveUrl('https://example.cat', $site) === ['url' => 'https://example.cat/'], "$host: empty path becomes /");
    check($I::resolveUrl('http://example.cat/a', $site) === ['url' => 'http://example.cat/a'], "$host: http and https of the same host are the same site");
    check($I::resolveUrl('https://example.cat:443/a', $site) === ['url' => 'https://example.cat:443/a'], "$host: explicit default port is the same site");
    check(($I::resolveUrl('https://example.cat:8443/a', $site)['code'] ?? '') === 'foreign_host', "$host: other port refused");
    check(($I::resolveUrl('https://evil.example/', $site)['code'] ?? '') === 'foreign_host', "$host: other host refused");
    check(($I::resolveUrl('https://example.cat.evil.example/', $site)['code'] ?? '') === 'foreign_host', "$host: suffix trick refused");
    check(($I::resolveUrl('//evil.example/x', $site)['code'] ?? '') === 'foreign_host', "$host: protocol-relative other host refused");
    check(($I::resolveUrl('https://example.cat@evil.example/', $site)['code'] ?? '') === 'url_credentials_refused', "$host: userinfo trick refused");
    check(($I::resolveUrl('https://u:p@example.cat/', $site)['code'] ?? '') === 'url_credentials_refused', "$host: credentials refused");
    check(($I::resolveUrl('http://127.0.0.1/', $site)['code'] ?? '') === 'foreign_host', "$host: IP literal refused");
    check(($I::resolveUrl('ftp://example.cat/', $site)['code'] ?? '') === 'unsupported_scheme', "$host: ftp refused");
    check(isset($I::resolveUrl('file:///etc/passwd', $site)['error']), "$host: file refused");
    check(($I::resolveUrl('qui-som', $site)['code'] ?? '') === 'invalid_url', "$host: bare relative path refused without a base");
    check(($I::resolveUrl("https://example.cat/a b", $site)['code'] ?? '') === 'invalid_url', "$host: whitespace refused");
    check(($I::resolveUrl('', $site)['code'] ?? '') === 'missing_url', "$host: empty url refused");
    check($I::resolveUrl('http://10.0.2.135/x', 'http://10.0.2.135/') === ['url' => 'http://10.0.2.135/x'], "$host: an IP site may fetch itself");

    // Redirects.
    $seen = [];
    $r = $I::fetch('/old', $site, pages([
        'https://example.cat/old' => ['status' => 301, 'headers' => ['location' => '/new'], 'body' => ''],
        'https://example.cat/new' => ['status' => 302, 'headers' => ['location' => 'final'], 'body' => ''],
        'https://example.cat/final' => ['status' => 200, 'headers' => [], 'body' => 'ok'],
    ], $seen));
    check(($r['status'] ?? 0) === 200 && $r['final_url'] === 'https://example.cat/final', "$host: redirects followed on the same host");
    check(count($r['redirects']) === 2 && $r['redirects'][0] === ['status' => 301, 'location' => '/new'], "$host: hops reported");
    check($r['url'] === 'https://example.cat/old', "$host: requested url reported");

    $seen = [];
    $r = $I::fetch('/out', $site, pages([
        'https://example.cat/out' => ['status' => 302, 'headers' => ['location' => 'https://evil.example/'], 'body' => ''],
    ], $seen));
    check(($r['code'] ?? '') === 'redirect_refused' && $seen === ['https://example.cat/out'], "$host: redirect off the host refused before fetching it");

    $loop = ['https://example.cat/loop' => ['status' => 302, 'headers' => ['location' => '/loop'], 'body' => '']];
    $seen = [];
    $r = $I::fetch('/loop', $site, pages($loop, $seen));
    check(($r['code'] ?? '') === 'too_many_redirects' && count($seen) === $I::MAX_REDIRECTS + 1, "$host: redirect loop stops");

    $r = $I::fetch('/x', $site, static fn () => ['error' => 'refused', 'code' => 'loopback_failed']);
    check(($r['code'] ?? '') === 'loopback_failed' && $r['url'] === 'https://example.cat/x', "$host: fetcher error passed through");

    $r = $I::fetch('/x', $site, pages(['https://example.cat/x' => ['status' => 304, 'headers' => [], 'body' => '']]));
    check(($r['status'] ?? 0) === 304, "$host: 3xx without location is a final answer");

    // Expect normalisation.
    check($I::normalizeExpect(null) === [], "$host: no expect");
    check($I::normalizeExpect([' Qui  som ']) === ['Qui som'], "$host: expect normalised");
    check(($I::normalizeExpect(array_fill(0, 11, 'x'))['code'] ?? '') === 'too_many_expect', "$host: more than 10 refused");
    check(($I::normalizeExpect(['a' => 'x'])['code'] ?? '') === 'invalid_expect', "$host: map refused");
    check(($I::normalizeExpect(['x', ' '])['code'] ?? '') === 'invalid_expect', "$host: blank entry refused");
    check(($I::normalizeExpect('x')['code'] ?? '') === 'invalid_expect', "$host: string refused");

    // HTML reading.
    $a = $I::analyze($html, ['cf-cache-status' => 'HIT', 'set-cookie' => 'a=b', 'content-type' => 'text/html'], $I::normalizeExpect([
        'Qui som', 'Hello', 'Primer Segon', 'PrimerSegon', 'què fem i com', 'Amagat al script', "Amagat a l'estil",
        'Text amagat amb hidden', 'Equip al taller', 'Serveis',
    ]));
    $e = [];
    foreach ($a['expect'] as $m) {
        $e[$m['text']] = $m;
    }
    check($a['title'] === 'Qui som & equip', "$host: title read and normalised");
    check($a['lang'] === 'ca-ES', "$host: lang read");
    check($a['noindex'] === true, "$host: meta noindex read");
    check($a['has_main_region'] === true, "$host: main region seen");
    check((array) $e['Qui som']['regions'] === ['nav' => 1, 'main' => 1] && $e['Qui som']['count'] === 2, "$host: menu and page title told apart; article header stays in main");
    check($e['Hello']['found'] === true, "$host: inline tags do not split a word");
    check($e['Primer Segon']['found'] === true && $e['PrimerSegon']['found'] === false, "$host: block tags separate words");
    check($e['què fem i com']['found'] === true, "$host: accents, nbsp and spaces, ignoring case");
    check($e['Amagat al script']['found'] === false && $e["Amagat a l'estil"]['found'] === false, "$host: script and style ignored");
    check($e['Text amagat amb hidden']['found'] === false, "$host: hidden attribute ignored");
    check((array) $e['Equip al taller']['regions'] === ['main' => 1], "$host: image alt counts as text");
    check((array) $e['Serveis']['regions'] === ['nav' => 1, 'main' => 2, 'footer' => 1] && $e['Serveis']['count'] === 4, "$host: every hit counted per region");
    check(str_starts_with($a['excerpt'], 'Qui som Som un estudi de webs a Vic.'), "$host: excerpt comes from main");
    check((array) $a['cache']['headers'] === ['cf-cache-status' => 'HIT'], "$host: only cache headers reported");
    check($a['cache']['html_markers'] === ['wp_rocket'], "$host: WP Rocket marker seen");
    check($a['body_truncated'] === false && !isset($a['regions_available']), "$host: whole body parsed");
    check(!str_contains(json_encode($a), 'a=b'), "$host: cookies never echoed");

    $a = $I::analyze('<p>x</p>', ['x-robots-tag' => 'noindex'], []);
    check($a['noindex'] === true && !isset($a['expect']) && $a['title'] === null, "$host: x-robots-tag noindex, no expect key without expect");
    check($a['has_main_region'] === false && $a['excerpt'] === 'x', "$host: page without main still has an excerpt");

    $a = $I::analyze(str_repeat('a', $I::MAX_BODY_BYTES + 10), [], []);
    check($a['body_truncated'] === true, "$host: oversize body truncated");

    // The tool.
    $seen = [];
    $T = $classes['tool'];
    $tool = new $T($site, pages([
        'https://example.cat/qui-som' => ['status' => 200, 'headers' => ['age' => '120'], 'body' => $html],
        'https://example.cat/qui-som?mirasai_nocache=X' => ['status' => 200, 'headers' => [], 'body' => '<main>Nou</main>'],
    ], $seen));
    check($tool->getName() === 'site/public-fetch', "$host: tool name");
    check(($tool->getInputSchema()['required'] ?? null) === ['url'], "$host: url required");
    check($tool->toMcpTool()['annotations']['readOnlyHint'] === true, "$host: read-only");

    $r = $tool->handle(['url' => '/qui-som', 'expect' => ['Qui som']]);
    check($r['status'] === 200 && $r['cache_bust'] === false && $r['expect'][0]['count'] === 2, "$host: tool reads the page");
    check(!isset($r['body']) && !isset($r['headers']), "$host: no raw body or headers in the answer");
    check((array) $r['cache']['headers'] === ['age' => '120'], "$host: cache headers in the answer");

    $r = $tool->handle(['url' => 'https://example.cat/qui-som', 'cache_bust' => true, 'expect' => ['Nou']]);
    check($r['expect'][0]['found'] === true && $r['cache_bust'] === true, "$host: cache_bust adds a parameter");
    check(preg_match('#^https://example\.cat/qui-som\?mirasai_nocache=[0-9a-f]{8}$#', end($seen)) === 1, "$host: cache_bust parameter shape");

    $r = $tool->handle(['url' => 'https://evil.example/', 'cache_bust' => true]);
    check(($r['code'] ?? '') === 'foreign_host', "$host: cache_bust does not skip the host rule");
    $r = $tool->handle(['url' => '/qui-som', 'expect' => array_fill(0, 11, 'x')]);
    check(($r['code'] ?? '') === 'too_many_expect', "$host: expect checked before fetching");
}

// Joomla: where the site is when live_site is empty and the Host header decides Uri::root().
$J = \Mirasai\Library\Tool\SitePublicFetchTool::class;
check($J::siteTarget('https://example.cat', 'https://evil.example/', '10.0.0.5') === ['site_url' => 'https://example.cat/', 'pin' => null], 'joomla: live_site wins and is not pinned');
check($J::siteTarget('', 'https://example.cat/', '185.254.206.69') === ['site_url' => 'https://example.cat/', 'pin' => '185.254.206.69'], 'joomla: request host is pinned to the receiving address');
check($J::siteTarget('', 'https://example.cat:443/', '185.254.206.69')['pin'] === '185.254.206.69', 'joomla: explicit default port accepted');
check(($J::siteTarget('', 'https://example.cat:8080/', '185.254.206.69')['code'] ?? '') === 'site_url_untrusted', 'joomla: port from the Host header refused');
check(($J::siteTarget('', 'http://example.cat:443/', '185.254.206.69')['code'] ?? '') === 'site_url_untrusted', 'joomla: https port on http refused');
check($J::siteTarget('http://example.cat:8080', 'http://example.cat:8080/', '10.0.0.5') === ['site_url' => 'http://example.cat:8080/', 'pin' => null], 'joomla: a non-default port works through live_site');
check(($J::siteTarget('', 'https://example.cat/', null)['code'] ?? '') === 'site_url_untrusted', 'joomla: no server address refused');
check(($J::siteTarget('', 'https://example.cat/', 'not-an-ip')['code'] ?? '') === 'site_url_untrusted', 'joomla: malformed server address refused');
check(($J::siteTarget('', '/', '10.0.0.5')['code'] ?? '') === 'site_url_unknown', 'joomla: root without host refused');

// The two hosts carry the same inspector.
$wp = file_get_contents($root . '/packages/mirasai-wp/src/Tool/PublicPageInspector.php');
$joomla = file_get_contents($root . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/PublicPageInspector.php');
$strip = static fn (string $s): string => preg_replace(['/^namespace .*$/m', '/^ \* Mirrored in .*$/m'], '', $s);
check($strip($wp) === $strip($joomla), 'inspector copies are identical apart from namespace and mirror note');

echo $fails === 0 ? "OK: $count checks\n" : "$fails of $count checks failed\n";
exit($fails === 0 ? 0 : 1);
