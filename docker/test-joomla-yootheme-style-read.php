<?php

declare(strict_types=1);

require_once dirname(__DIR__)
    . '/packages/mirasai-joomla/packages/plg_mirasai_yootheme/src/Tool/YoothemeStyleHelper.php';

use Mirasai\Plugin\Mirasai\Yootheme\Tool\YoothemeStyleHelper;

$passed = 0;
$failed = 0;

function checkJoomlaStyle(string $label, bool $condition): void
{
    global $passed, $failed;

    if ($condition) {
        echo "[PASS] {$label}\n";
        $passed++;
        return;
    }

    echo "[FAIL] {$label}\n";
    $failed++;
}

function removeFixture(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    rmdir($path);
}

$fixture = sys_get_temp_dir() . '/mirasai-joomla-style-' . bin2hex(random_bytes(6));
$theme = $fixture . '/templates/yootheme';

mkdir($theme . '/less', 0777, true);
mkdir($theme . '/css', 0777, true);
mkdir($theme . '/fonts', 0777, true);
mkdir($theme . '/vendor/assets/uikit', 0777, true);

file_put_contents(
    $theme . '/templateDetails.xml',
    '<extension><version>5.0.37</version></extension>'
);
file_put_contents(
    $theme . '/less/theme.nioh-studio.less',
    "/*\nName: Nioh Studio\nStyle: white-blue\nBackground: light\nColor: blue\n*/\n@x: 1;\n"
);
file_put_contents(
    $theme . '/vendor/assets/uikit/current.less',
    '@global-color: #222;'
);
file_put_contents(
    $theme . '/css/theme.12.css',
    '/* YOOtheme Pro v5.0.34 | compiled on 2026-05-25T12:04:27+00:00 */.x{}'
);
file_put_contents($theme . '/fonts/Roboto-abcdef.woff2', 'font');

$cssMtime = strtotime('2026-05-25T12:04:27+00:00');
$sourceMtime = strtotime('2026-07-09T11:55:50+00:00');
touch($theme . '/css/theme.12.css', $cssMtime);
touch($theme . '/less/theme.nioh-studio.less', $sourceMtime);
touch($theme . '/vendor/assets/uikit/current.less', $sourceMtime);

$helper = new YoothemeStyleHelper(null, $fixture);
$config = [
    'style' => 'nioh-studio:white-blue',
    'less' => ['@global-primary-background' => '#07155E'],
    'custom_less' => '.custom { color: red; }',
    'yootheme_apikey' => 'must-not-be-returned',
];
$active = $helper->activeStyle($config);
$overrides = $helper->overrides($config);
$compiled = $helper->compiledState(12);
$available = $helper->availableStyles();
$fonts = $helper->fonts();

checkJoomlaStyle('active style id is split from variation', $active['style_id'] === 'nioh-studio');
checkJoomlaStyle('active style variation is returned', $active['variation'] === 'white-blue');
checkJoomlaStyle('override count is reported', $overrides['less_count'] === 1);
checkJoomlaStyle('custom Less byte count is reported', $overrides['custom_less_bytes'] === 23);
checkJoomlaStyle(
    'non-active styles start without active overrides',
    $helper->compilationOverrides($config, $active, 'flow')['less'] === []
);
checkJoomlaStyle(
    'active overrides can be explicitly carried to another style',
    $helper->compilationOverrides($config, $active, 'flow', true)['source'] === 'active_config'
);
checkJoomlaStyle('available styles are read from templates/yootheme/less', count($available) === 1);
checkJoomlaStyle('style metadata name is parsed', ($available[0]['name'] ?? null) === 'Nioh Studio');
checkJoomlaStyle(
    'style variation metadata is parsed',
    ($available[0]['variations'][0]['id'] ?? null) === 'white-blue'
);
checkJoomlaStyle(
    'compiled CSS uses the Joomla template style id',
    $compiled['file'] === 'templates/yootheme/css/theme.12.css'
);
checkJoomlaStyle('compiled CSS version drift is detected', $compiled['stale_version'] === true);
checkJoomlaStyle('compiled CSS source drift is detected', $compiled['stale_sources'] === true);
checkJoomlaStyle(
    'freshness method is explicitly an mtime heuristic',
    $compiled['freshness_method'] === 'broad_less_mtime_heuristic'
);
checkJoomlaStyle('theme version comes from templateDetails.xml', $compiled['theme_version'] === '5.0.37');
checkJoomlaStyle('local fonts are summarized', $fonts['families'] === ['Roboto']);
checkJoomlaStyle(
    'invalid style ids are rejected before runtime bootstrap',
    ($helper->sources('../secret')['code'] ?? null) === 'invalid_style_id'
);

$etag = $helper->etag($config, $compiled);
checkJoomlaStyle('style etag is stable', $etag === $helper->etag($config, $compiled));
$config['less']['@global-primary-background'] = '#000';
checkJoomlaStyle('style etag changes with config', $etag !== $helper->etag($config, $compiled));

$secret = $config['yootheme_apikey'];
$patched = $helper->patchConfig(
    $config,
    'nioh-studio',
    'white-blue',
    ['@global-primary-background' => '#123456', '@new-token' => '7px'],
    ['@new-token'],
    null,
    false
);
checkJoomlaStyle(
    'style config patch preserves the YOOtheme API key',
    ($patched['yootheme_apikey'] ?? null) === $secret
);
checkJoomlaStyle(
    'style config patch changes only requested variables',
    ($patched['less']['@global-primary-background'] ?? null) === '#123456'
        && !array_key_exists('@new-token', $patched['less'])
);
checkJoomlaStyle(
    'omitted custom Less is preserved',
    ($patched['custom_less'] ?? null) === $config['custom_less']
);

// The native YOOtheme save path asks StyleFontLoader to make cached font URLs
// relative to the CSS directory. Using the fonts directory would emit bare
// filenames and break the resulting URLs when the stylesheet lives in /css.
$GLOBALS['test_font_base_path'] = null;
$GLOBALS['test_font_relative_url'] = '../fonts/Roboto-abcdef.woff2';
$GLOBALS['test_yootheme_app'] = new class {
    public function load(string $pattern): void
    {
    }

    public function __invoke(string $service): object
    {
        return new class {
            public function parse(string $source): array
            {
                return [
                    '@import url(https://fonts.googleapis.com/css?family=Roboto);',
                    'https://fonts.googleapis.com/css?family=Roboto',
                ];
            }

            public function css(string $url, string $basePath): string
            {
                $GLOBALS['test_font_base_path'] = $basePath;

                return "@font-face{src:url({$GLOBALS['test_font_relative_url']})}\n";
            }
        };
    }
};
eval('namespace YOOtheme { function app(): object { return $GLOBALS["test_yootheme_app"]; } }');
$prepareCss = new ReflectionMethod(YoothemeStyleHelper::class, 'prepareCompiledCss');
$preparedCss = $prepareCss->invoke(
    $helper,
    '@import url(https://fonts.googleapis.com/css?family=Roboto);.x{color:red}'
);
checkJoomlaStyle(
    'style-update resolves localized fonts relative to the CSS directory',
    $GLOBALS['test_font_base_path'] === $theme . '/css'
);
checkJoomlaStyle(
    'style-update preserves the ../fonts URL needed by CSS files',
    str_contains($preparedCss, 'url(../fonts/Roboto-abcdef.woff2)')
);

$GLOBALS['test_font_relative_url'] = 'roboto-missing.woff2';
$missingAssetRejected = false;
try {
    $prepareCss->invoke(
        $helper,
        '@import url(https://fonts.googleapis.com/css?family=Roboto);.x{color:red}'
    );
} catch (RuntimeException $exception) {
    $missingAssetRejected = str_contains($exception->getMessage(), 'roboto-missing.woff2');
}
checkJoomlaStyle(
    'style-update rejects a missing relative CSS asset before staging',
    $missingAssetRejected
);

$provider = (string) file_get_contents(
    dirname(__DIR__) . '/packages/mirasai-joomla/packages/plg_mirasai_yootheme/provider.php'
);
foreach ([
    'YoothemeStyleHelper.php',
    'TemplateStyleReadTool.php',
    'TemplateStyleSourcesTool.php',
    'TemplateStyleUpdateTool.php',
] as $requiredStyleFile) {
    checkJoomlaStyle(
        "standalone provider explicitly loads {$requiredStyleFile}",
        str_contains($provider, "/Tool/{$requiredStyleFile}")
    );
}

removeFixture($fixture);

if ($failed > 0) {
    echo "\n{$failed} Joomla Style test(s) failed.\n";
    exit(1);
}

echo "\nAll {$passed} Joomla Style read tests passed.\n";
