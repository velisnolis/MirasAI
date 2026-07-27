<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Read access to the YOOtheme Pro 5 Style layer on Joomla.
 *
 * Joomla stores the YOOtheme config JSON in #__template_styles.params.config.
 * The active template-style row id is also the suffix of theme.<id>.css.
 * Compilation itself is shared with WordPress: YOOtheme resolves the Less
 * import tree through Styler and sends it to the browser worker.js.
 */
class YoothemeStyleHelper
{
    private ?DatabaseInterface $db;
    private string $siteRoot;

    public function __construct(?DatabaseInterface $db = null, ?string $siteRoot = null)
    {
        $resolvedRoot = $siteRoot ?? (defined('JPATH_ROOT') ? (string) JPATH_ROOT : null);

        if (!is_string($resolvedRoot) || trim($resolvedRoot) === '') {
            throw new \InvalidArgumentException(
                'A Joomla site root is required when JPATH_ROOT is unavailable.'
            );
        }

        $this->db = $db;
        $this->siteRoot = rtrim($resolvedRoot, '/');
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $activeId = $this->resolveActiveTemplateStyleId();
        $installed = is_dir($this->themeDir());
        $runtime = $installed ? $this->ensureRuntime() : [];

        return [
            'active' => $activeId !== null,
            'installed' => $installed,
            'version' => $this->themeVersion(),
            'container_available' => $this->containerAvailable(),
            'template_style_id' => $activeId,
            'read_tool' => 'template/style-read',
            'sources_tool' => 'template/style-sources',
            ...(isset($runtime['error']) ? ['runtime_error' => $runtime['error']] : []),
        ];
    }

    public function resolveActiveTemplateStyleId(): ?int
    {
        $db = $this->database();
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__template_styles'))
            ->where($db->quoteName('template') . ' = ' . $db->quote('yootheme'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('home') . ' = 1');

        $result = $db->setQuery($query)->loadResult();

        return $result ? (int) $result : null;
    }

    /**
     * Decodes #__template_styles.params for one YOOtheme site style.
     *
     * @return array<string, mixed>|null
     */
    public function loadStyleParams(int $templateStyleId): ?array
    {
        $db = $this->database();
        $query = $db->getQuery(true)
            ->select($db->quoteName('params'))
            ->from($db->quoteName('#__template_styles'))
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('template') . ' = ' . $db->quote('yootheme'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->bind(':id', $templateStyleId, ParameterType::INTEGER);

        $encoded = $db->setQuery($query)->loadResult();

        if (!is_string($encoded) || $encoded === '') {
            return null;
        }

        $params = json_decode($encoded, true);

        return is_array($params) ? $params : null;
    }

    public function loadStyleParamsEncoded(int $templateStyleId): ?string
    {
        $db = $this->database();
        $query = $db->getQuery(true)
            ->select($db->quoteName('params'))
            ->from($db->quoteName('#__template_styles'))
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('template') . ' = ' . $db->quote('yootheme'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->bind(':id', $templateStyleId, ParameterType::INTEGER);

        $encoded = $db->setQuery($query)->loadResult();

        return is_string($encoded) && $encoded !== '' ? $encoded : null;
    }

    /**
     * Decoded params.config payload. It may contain yootheme_apikey, so callers
     * must return only the dedicated summaries exposed by this helper.
     *
     * @return array<string, mixed>
     */
    public function loadConfig(int $templateStyleId): array
    {
        $params = $this->loadStyleParams($templateStyleId);
        $encoded = is_array($params) ? ($params['config'] ?? null) : null;

        if (!is_string($encoded) || $encoded === '') {
            return [];
        }

        $config = json_decode($encoded, true);

        return is_array($config) ? $config : [];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{raw: string, style_id: string, variation: ?string}
     */
    public function activeStyle(array $config): array
    {
        $raw = is_string($config['style'] ?? null) ? (string) $config['style'] : '';

        if ($raw === '') {
            return ['raw' => '', 'style_id' => '', 'variation' => null];
        }

        $parts = explode(':', $raw, 2);

        return [
            'raw' => $raw,
            'style_id' => $parts[0],
            'variation' => isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function overrides(array $config): array
    {
        $less = $config['less'] ?? [];
        $custom = $config['custom_less'] ?? '';

        return [
            'less' => is_array($less) ? $less : [],
            'less_count' => is_array($less) ? count($less) : 0,
            'custom_less' => is_string($custom) ? $custom : '',
            'custom_less_bytes' => is_string($custom) ? strlen($custom) : 0,
            'customised' => (is_array($less) && $less !== [])
                || (is_string($custom) && $custom !== ''),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array{style_id: string, variation: ?string} $active
     * @return array{internal_style: ?string, less: array<mixed>, custom_less: string, source: string}
     */
    public function compilationOverrides(
        array $config,
        array $active,
        string $styleId,
        bool $applyActiveOverrides = false
    ): array {
        $useActive = $styleId === $active['style_id'] || $applyActiveOverrides;

        return [
            'internal_style' => $useActive ? $active['variation'] : null,
            'less' => $useActive && is_array($config['less'] ?? null)
                ? $config['less']
                : [],
            'custom_less' => $useActive && is_string($config['custom_less'] ?? null)
                ? $config['custom_less']
                : '',
            'source' => $useActive ? 'active_config' : 'style_defaults',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function availableStyles(): array
    {
        $styles = [];

        foreach ($this->styleDirectories() as $source => $dir) {
            $files = glob($dir . '/theme.*.less');

            if (!is_array($files)) {
                continue;
            }

            foreach ($files as $file) {
                $id = substr(basename($file, '.less'), 6);

                if ($id !== '') {
                    $styles[$id] = ['id' => $id, 'source' => $source]
                        + $this->parseStyleMeta($file);
                }
            }
        }

        ksort($styles);

        return array_values($styles);
    }

    /**
     * @return array<string, mixed>
     */
    public function parseStyleMeta(string $file): array
    {
        $meta = ['name' => null, 'variations' => []];
        $handle = @fopen($file, 'r');

        if ($handle === false) {
            return $meta;
        }

        $content = str_replace("\r", "\n", (string) fread($handle, 8192));
        fclose($handle);

        if (!preg_match('/^\s*\/\*(?:(?!\*\/).|\n)+\*\//', $content, $block)) {
            return $meta;
        }

        if (!preg_match_all('/^[ \t\/*#@]*(name|style|background|color|type|preview):(.*)$/mi', $block[0], $matches)) {
            return $meta;
        }

        $current = null;

        foreach ($matches[1] as $index => $rawKey) {
            $key = strtolower(trim($rawKey));
            $value = trim($matches[2][$index]);

            if ($key === 'style') {
                $current = $value;
                $meta['variations'][$current] = [
                    'id' => $current,
                    'name' => $this->namify($current),
                ];
                continue;
            }

            if ($current === null) {
                $meta[$key] = $key === 'name' ? $value : $this->splitList($value);
                continue;
            }

            $meta['variations'][$current][$key] = $key === 'name'
                ? $value
                : $this->splitList($value);
        }

        $meta['variations'] = array_values($meta['variations']);

        return $meta;
    }

    /**
     * @return array<string, mixed>
     */
    public function compiledState(?int $templateStyleId = null): array
    {
        $templateStyleId ??= $this->resolveActiveTemplateStyleId();
        $dir = $this->themeDir() . '/css';
        $file = $templateStyleId !== null
            ? $dir . '/theme.' . $templateStyleId . '.css'
            : '';

        if ($file === '' || !is_file($file)) {
            $file = $dir . '/theme.css';
        }

        if (!is_file($file)) {
            return [
                'present' => false,
                'file' => null,
                'stale_version' => null,
                'stale_sources' => null,
                'freshness_method' => 'broad_less_mtime_heuristic',
            ];
        }

        $header = (string) file_get_contents($file, false, null, 0, 160);
        $compiledVersion = preg_match('/YOOtheme Pro v([\w\d.\-]+)/', $header, $match)
            ? $match[1]
            : null;
        $compiledAt = preg_match('/compiled on ([0-9T:+\-]+)/', $header, $match)
            ? $match[1]
            : null;
        $mtime = (int) filemtime($file);
        $newest = $this->newestSourceMtime();

        return [
            'present' => true,
            'file' => $this->relativeToRoot($file),
            'bytes' => (int) filesize($file),
            'mtime' => gmdate('c', $mtime),
            'compiled_at' => $compiledAt,
            'compiled_version' => $compiledVersion,
            'theme_version' => $this->themeVersion(),
            'stale_version' => $compiledVersion !== null
                && $compiledVersion !== $this->themeVersion(),
            'stale_sources' => $newest['mtime'] !== null && $newest['mtime'] > $mtime,
            'freshness_method' => 'broad_less_mtime_heuristic',
            'newest_source' => $newest['file'],
            'newest_source_mtime' => $newest['mtime'] !== null
                ? gmdate('c', $newest['mtime'])
                : null,
        ];
    }

    /**
     * @return array{file: ?string, mtime: ?int}
     */
    public function newestSourceMtime(): array
    {
        $newest = ['file' => null, 'mtime' => null];
        $roots = array_values($this->styleDirectories());
        $roots[] = $this->themeDir() . '/vendor/assets';

        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $item) {
                /** @var \SplFileInfo $item */
                if (!$item->isFile() || strtolower($item->getExtension()) !== 'less') {
                    continue;
                }

                $mtime = (int) $item->getMTime();

                if ($newest['mtime'] === null || $mtime > $newest['mtime']) {
                    $newest = [
                        'file' => $this->relativeToRoot($item->getPathname()),
                        'mtime' => $mtime,
                    ];
                }
            }
        }

        foreach ($this->componentLessFiles() as $file) {
            if (!is_file($file)) {
                continue;
            }

            $mtime = (int) filemtime($file);

            if ($newest['mtime'] === null || $mtime > $newest['mtime']) {
                $newest = ['file' => $this->relativeToRoot($file), 'mtime' => $mtime];
            }
        }

        return $newest;
    }

    /**
     * @return list<string>
     */
    public function componentLessFiles(): array
    {
        $config = $this->themeConfig();

        if ($config === null) {
            return [];
        }

        $paths = $config('theme.styles.components', []);

        if (!is_array($paths)) {
            return [];
        }

        $files = [];

        foreach ($paths as $pattern) {
            if (!is_string($pattern)) {
                continue;
            }

            foreach (glob($pattern) ?: [] as $match) {
                $files[] = $match;
            }
        }

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    public function fonts(): array
    {
        $dir = $this->themeDir() . '/fonts';
        $families = [];
        $files = is_dir($dir) ? (glob($dir . '/*.woff2') ?: []) : [];

        foreach ($files as $file) {
            $name = basename($file, '.woff2');
            $family = preg_replace('/-[0-9a-f]{6,}$/', '', $name);

            if (is_string($family) && $family !== '') {
                $families[$family] = true;
            }
        }

        ksort($families);

        return [
            'dir' => $this->relativeToRoot($dir),
            'families' => array_keys($families),
            'file_count' => count($files),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $compiled
     */
    public function etag(array $config, array $compiled): string
    {
        return substr(hash('sha256', (string) json_encode([
            'config' => $config,
            'css' => [
                $compiled['file'] ?? null,
                $compiled['bytes'] ?? null,
                $compiled['mtime'] ?? null,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 32);
    }

    /**
     * Apply only the requested Style keys while preserving every other config
     * entry, including yootheme_apikey.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $vars
     * @param list<string> $unsetVars
     * @return array<string, mixed>
     */
    public function patchConfig(
        array $config,
        string $styleId,
        ?string $variation,
        array $vars,
        array $unsetVars,
        ?string $customLess,
        bool $replaceCustomLess
    ): array {
        $less = is_array($config['less'] ?? null) ? $config['less'] : [];

        foreach ($vars as $name => $value) {
            $less[$name] = $value;
        }

        foreach ($unsetVars as $name) {
            unset($less[$name]);
        }

        $config['style'] = $styleId . ($variation !== null && $variation !== ''
            ? ':' . $variation
            : '');
        $config['less'] = $less;

        if ($replaceCustomLess) {
            $config['custom_less'] = $customLess ?? '';
        }

        return $config;
    }

    /**
     * Persist config and compiled CSS as one guarded operation.
     *
     * The CSS is staged before the database transaction. If a filesystem or
     * database step fails, the transaction is rolled back and every affected
     * CSS file is restored from the private snapshot.
     *
     * @param array<string, mixed> $candidateConfig
     * @return array<string, mixed>
     */
    public function commitStyleUpdate(
        int $templateStyleId,
        array $candidateConfig,
        string $compiledCss,
        string $compiledRtl,
        string $ifMatch
    ): array {
        $freshConfig = $this->loadConfig($templateStyleId);
        $freshCompiled = $this->compiledState($templateStyleId);
        $freshEtag = $this->etag($freshConfig, $freshCompiled);

        if (!hash_equals($freshEtag, $ifMatch)) {
            return [
                'error' => 'Style changed before write. Re-read it and retry with the fresh etag.',
                'code' => 'stale_etag',
                'expected_etag' => $freshEtag,
                'provided_etag' => $ifMatch,
            ];
        }

        if (($freshConfig['yootheme_apikey'] ?? null) !== ($candidateConfig['yootheme_apikey'] ?? null)) {
            return [
                'error' => 'The candidate config does not preserve the YOOtheme API key.',
                'code' => 'secret_preservation_failed',
            ];
        }

        $targets = $this->styleCssTargets();

        try {
            $processed = [
                'ltr' => $this->prepareCompiledCss($compiledCss),
                'rtl' => $this->prepareCompiledCss($compiledRtl),
            ];
            $staged = $this->stageCssFiles($targets, $processed);
        } catch (\Throwable $exception) {
            return [
                'error' => 'Unable to prepare the compiled Style CSS: ' . $exception->getMessage(),
                'code' => 'style_css_stage_failed',
            ];
        }

        // This is the second optimistic-lock check, intentionally after any
        // potentially slow font processing and immediately before snapshot +
        // write. The first check above rejects stale callers cheaply.
        $writeConfig = $this->loadConfig($templateStyleId);
        $writeCompiled = $this->compiledState($templateStyleId);
        $writeEtag = $this->etag($writeConfig, $writeCompiled);

        if (!hash_equals($writeEtag, $ifMatch)) {
            foreach ($staged as $temporary) {
                @unlink($temporary);
            }

            return [
                'error' => 'Style changed while CSS was being prepared. Re-read it and retry with the fresh etag.',
                'code' => 'stale_etag',
                'expected_etag' => $writeEtag,
                'provided_etag' => $ifMatch,
            ];
        }

        $encodedParams = $this->loadStyleParamsEncoded($templateStyleId);
        $params = is_string($encodedParams) ? json_decode($encodedParams, true) : null;

        if (!is_array($params)) {
            foreach ($staged as $temporary) {
                @unlink($temporary);
            }

            return [
                'error' => 'The Joomla template style params are missing or invalid JSON.',
                'code' => 'invalid_style_params',
            ];
        }

        $paramsConfig = is_string($params['config'] ?? null)
            ? json_decode($params['config'], true)
            : null;
        if (!is_array($paramsConfig) || $paramsConfig !== $writeConfig) {
            foreach ($staged as $temporary) {
                @unlink($temporary);
            }

            return [
                'error' => 'Style config changed at the write gate. Re-read it and retry.',
                'code' => 'stale_etag',
            ];
        }

        try {
            $params['config'] = json_encode(
                $candidateConfig,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            $candidateParams = json_encode(
                $params,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            foreach ($staged as $temporary) {
                @unlink($temporary);
            }

            return [
                'error' => 'Unable to encode the candidate Style config: ' . $exception->getMessage(),
                'code' => 'style_config_encode_failed',
            ];
        }

        $snapshot = $this->createStyleSnapshot(
            $templateStyleId,
            $encodedParams,
            array_keys($targets)
        );

        if (isset($snapshot['error'])) {
            foreach ($staged as $temporary) {
                @unlink($temporary);
            }

            return $snapshot;
        }

        $db = $this->database();
        $renamed = [];
        $transactionStarted = false;
        $databaseWritten = false;
        $concurrentWrite = false;

        try {
            $db->transactionStart();
            $transactionStarted = true;

            if ($candidateParams !== $encodedParams) {
                $query = $db->getQuery(true)
                    ->update($db->quoteName('#__template_styles'))
                    ->set($db->quoteName('params') . ' = :params')
                    ->where($db->quoteName('id') . ' = :id')
                    ->where($db->quoteName('template') . ' = ' . $db->quote('yootheme'))
                    ->where($db->quoteName('client_id') . ' = 0')
                    ->where($db->quoteName('params') . ' = :expected_params')
                    ->bind(':params', $candidateParams)
                    ->bind(':expected_params', $encodedParams)
                    ->bind(':id', $templateStyleId, ParameterType::INTEGER);
                $db->setQuery($query)->execute();

                if ($db->getAffectedRows() !== 1) {
                    $concurrentWrite = true;
                    throw new \RuntimeException(
                        'Joomla Style params changed concurrently at the write gate.'
                    );
                }

                $databaseWritten = true;
            }

            foreach ($staged as $target => $temporary) {
                if (!@rename($temporary, $target)) {
                    throw new \RuntimeException(sprintf('Unable to replace %s atomically.', $target));
                }
                $renamed[] = $target;
            }

            $db->transactionCommit();
            $transactionStarted = false;
        } catch (\Throwable $exception) {
            $databaseRollback = [
                'attempted' => $transactionStarted,
                'restored' => !$transactionStarted,
            ];

            try {
                if ($transactionStarted) {
                    $db->transactionRollback();
                    $databaseRollback['restored'] = true;

                    if ($databaseWritten
                        && $this->loadStyleParamsEncoded($templateStyleId) !== $encodedParams) {
                        $databaseRollback['restored'] = false;
                        $databaseRollback['error'] = 'Joomla Style params do not match the snapshot after transaction rollback.';
                    }
                }
            } catch (\Throwable $rollbackException) {
                $databaseRollback['restored'] = false;
                $databaseRollback['error'] = $rollbackException->getMessage();
            }

            foreach ($staged ?? [] as $temporary) {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }

            $fileRollback = $this->restoreSnapshotFiles($snapshot);
            $rollbackFailures = $fileRollback['failures'];
            if (($databaseRollback['restored'] ?? false) !== true) {
                $rollbackFailures[] = 'Unable to verify restoration of Joomla Style params.';
            }
            $rollback = [
                'restored' => ($databaseRollback['restored'] ?? false) === true
                    && ($fileRollback['restored'] ?? false) === true,
                'database' => $databaseRollback,
                'files' => $fileRollback,
                'failures' => $rollbackFailures,
            ];

            return [
                'error' => ($rollback['restored']
                    ? 'Style write failed and rollback completed: '
                    : 'Style write failed and rollback is incomplete; restore from the private snapshot before retrying: ')
                    . $exception->getMessage(),
                'code' => $concurrentWrite ? 'stale_etag' : 'style_write_failed',
                'snapshot_id' => $snapshot['snapshot_id'],
                'rollback' => $rollback,
                'renamed_before_failure' => array_map([$this, 'relativeToRoot'], $renamed),
            ];
        }

        clearstatcache(true);
        $updatedConfig = $this->loadConfig($templateStyleId);
        $updatedCompiled = $this->compiledState($templateStyleId);

        return [
            'snapshot_id' => $snapshot['snapshot_id'],
            'written_files' => array_map([$this, 'relativeToRoot'], array_keys($staged)),
            'written_sha256' => array_combine(
                array_map([$this, 'relativeToRoot'], array_keys($staged)),
                array_map(static fn (string $path): string => (string) hash_file('sha256', $path), array_keys($staged))
            ),
            'cache' => $this->invalidateStyleCache(),
            'new_etag' => $this->etag($updatedConfig, $updatedCompiled),
            'compiled' => $updatedCompiled,
        ];
    }

    /**
     * @return array<string, mixed>|array{error: string, code: string}
     */
    public function sources(string $styleId): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $styleId)) {
            return [
                'error' => 'style_id may only contain letters, numbers, underscores, and hyphens.',
                'code' => 'invalid_style_id',
            ];
        }

        $bootstrap = $this->ensureRuntime();

        if (isset($bootstrap['error'])) {
            return [
                'error' => $bootstrap['error'],
                'code' => 'container_unavailable',
            ];
        }

        try {
            $app = \YOOtheme\app();
            $styler = $app(\YOOtheme\Theme\Styler\Styler::class);
            $config = $app(\YOOtheme\Config::class);
            $theme = $styler->getTheme($styleId);

            if (!is_array($theme)) {
                return [
                    'error' => sprintf('Style "%s" not found.', $styleId),
                    'code' => 'style_not_found',
                ];
            }

            $imports = \YOOtheme\Event::emit('styler.imports|filter', [], $styleId);
            $filename = \YOOtheme\Url::to($theme['file']);
        } catch (\Throwable $exception) {
            return [
                'error' => 'Failed to resolve the style import tree: ' . $exception->getMessage(),
                'code' => 'resolve_failed',
            ];
        }

        if (!is_array($imports) || $imports === []) {
            return ['error' => 'The resolved import tree is empty.', 'code' => 'empty_imports'];
        }

        $bytes = 0;
        foreach ($imports as $source) {
            $bytes += is_string($source) ? strlen($source) : 0;
        }

        $vars = $config('theme.styles.vars', []);

        return [
            'style_id' => $styleId,
            'filename' => $filename,
            'filepath' => rtrim(dirname($filename), '/') . '/',
            'desturl' => \YOOtheme\Url::to('~theme/css'),
            'vars' => is_array($vars) ? $vars : [],
            'meta' => array_diff_key($theme, ['file' => 1]),
            'imports' => $imports,
            'import_count' => count($imports),
            'import_bytes' => $bytes,
            'theme_version' => $this->themeVersion(),
            'theme_config_id' => $this->themeConfigId(),
            'runtime_bootstrap' => $bootstrap,
        ];
    }

    public function containerAvailable(): bool
    {
        return function_exists('YOOtheme\\app');
    }

    /**
     * @return array<string, mixed>|array{error: string}
     */
    public function ensureRuntime(): array
    {
        $root = $this->themeDir();

        try {
            if (!function_exists('YOOtheme\\app')) {
                if (!is_file($root . '/bootstrap.php')) {
                    return ['error' => 'YOOtheme bootstrap.php was not found.'];
                }

                require $root . '/bootstrap.php';
            }

            $app = \YOOtheme\app();

            if (!is_object($app) || !method_exists($app, 'load')) {
                return ['error' => 'The YOOtheme application loader is unavailable.'];
            }

            $app->load(
                $root
                . '/{packages/{platform-joomla,'
                . 'theme{,-consent,-highlight,-settings},'
                . 'builder{,-source*,-templates,-newsletter},'
                . 'styler,theme-joomla*,builder-joomla*}'
                . '/bootstrap.php,config.php}'
            );
        } catch (\Throwable $exception) {
            return ['error' => 'Unable to bootstrap the YOOtheme Style runtime: ' . $exception->getMessage()];
        }

        return [
            'attempted' => true,
            'root' => $this->relativeToRoot($root),
            'platform' => 'joomla',
        ];
    }

    public function themeConfigId(): string
    {
        $config = $this->themeConfig();

        if ($config !== null) {
            $id = $config('theme.id');

            if (is_string($id) || is_int($id)) {
                return (string) $id;
            }
        }

        $activeId = $this->resolveActiveTemplateStyleId();

        return $activeId !== null ? (string) $activeId : '1';
    }

    public function themeVersion(): ?string
    {
        $manifest = $this->themeDir() . '/templateDetails.xml';

        if (!is_file($manifest)) {
            return null;
        }

        $xml = @simplexml_load_file($manifest);
        $version = $xml !== false ? trim((string) ($xml->version ?? '')) : '';

        return $version !== '' ? $version : null;
    }

    /**
     * @return array<string, string> absolute target => ltr|rtl
     */
    private function styleCssTargets(): array
    {
        $dir = $this->themeDir() . '/css';
        $id = $this->themeConfigId();
        $targets = [
            $dir . '/theme.' . $id . '.css' => 'ltr',
            $dir . '/theme.' . $id . '.rtl.css' => 'rtl',
        ];
        $config = $this->themeConfig();

        if ($config !== null && (bool) $config('theme.default')) {
            $targets[$dir . '/theme.css'] = 'ltr';
            $targets[$dir . '/theme.rtl.css'] = 'rtl';
        }

        return $targets;
    }

    private function prepareCompiledCss(string $css): string
    {
        $data = $css;
        $bootstrap = $this->ensureRuntime();

        if (!isset($bootstrap['error'])) {
            try {
                $font = \YOOtheme\app()(\YOOtheme\Theme\Styler\StyleFontLoader::class);
                if (is_object($font) && method_exists($font, 'parse') && method_exists($font, 'css')) {
                    $matches = $font->parse($data);

                    if (is_array($matches) && count($matches) >= 2) {
                        [$import, $url] = $matches;
                        // Match YOOtheme's native StyleController::save(): the
                        // second argument is the CSS destination directory.
                        // StyleFontLoader stores files in its own fonts cache
                        // and makes their URLs relative to this base path.
                        $fonts = $font->css($url, $this->themeDir() . '/css');

                        if (is_string($fonts) && $fonts !== '') {
                            $data = str_replace((string) $import, $fonts, $data);
                        }
                    }
                }
            } catch (\RuntimeException) {
                // Native YOOtheme save treats font localization as best effort.
            }
        }

        $this->assertRelativeCssAssetsExist($data);

        return sprintf(
            "/* YOOtheme Pro v%s compiled on %s */\n%s",
            (string) ($this->themeVersion() ?? 'unknown'),
            date(DATE_W3C),
            $data
        );
    }

    /**
     * Refuse compiled CSS whose relative url(...) values do not resolve from
     * the destination CSS directory. Absolute/root-relative URLs, data URIs
     * and fragments are runtime URLs and are deliberately skipped.
     */
    private function assertRelativeCssAssetsExist(string $css): void
    {
        preg_match_all(
            '~url\(\s*(?:(["\'])(.*?)\1|([^)]*))\s*\)~i',
            $css,
            $matches,
            PREG_SET_ORDER
        );

        $missing = [];
        $cssDir = $this->themeDir() . '/css';

        foreach ($matches as $match) {
            $url = trim((string) (($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? '')));

            if (
                $url === ''
                || str_starts_with($url, '#')
                || str_starts_with($url, '/')
                || str_starts_with($url, '//')
                || preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) === 1
            ) {
                continue;
            }

            $path = preg_split('/[?#]/', $url, 2)[0] ?? '';
            $path = rawurldecode(trim($path));
            if ($path === '') {
                continue;
            }

            $resolved = realpath($cssDir . '/' . $path);
            if ($resolved === false || !is_file($resolved)) {
                $missing[$url] = true;
            }
        }

        if ($missing !== []) {
            throw new \RuntimeException(sprintf(
                'Compiled CSS references missing relative asset(s) from %s: %s',
                $this->relativeToRoot($cssDir),
                implode(', ', array_keys($missing))
            ));
        }
    }

    /**
     * @param array<string, string> $targets
     * @param array{ltr: string, rtl: string} $processed
     * @return array<string, string> absolute target => temporary file
     */
    private function stageCssFiles(array $targets, array $processed): array
    {
        $staged = [];

        try {
            foreach ($targets as $target => $direction) {
                $dir = dirname($target);

                if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new \RuntimeException(sprintf('Unable to create CSS directory %s.', $dir));
                }

                $temporary = tempnam($dir, '.mirasai-style-');

                if (!is_string($temporary)) {
                    throw new \RuntimeException(sprintf('Unable to stage CSS in %s.', $dir));
                }

                $bytes = file_put_contents($temporary, $processed[$direction], LOCK_EX);

                if (!is_int($bytes) || $bytes !== strlen($processed[$direction])) {
                    @unlink($temporary);
                    throw new \RuntimeException(sprintf('Incomplete staged CSS write for %s.', $target));
                }

                @chmod($temporary, 0644);
                $staged[$target] = $temporary;
            }
        } catch (\Throwable $exception) {
            foreach ($staged as $temporary) {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }

            throw $exception;
        }

        return $staged;
    }

    /**
     * @param list<string> $files
     * @return array<string, mixed>
     */
    private function createStyleSnapshot(int $templateStyleId, string $encodedParams, array $files): array
    {
        $base = dirname($this->siteRoot) . '/mirasai-backups/style';
        $snapshotId = sprintf(
            'joomla-style-%d-%s-%s',
            $templateStyleId,
            gmdate('Ymd-His'),
            bin2hex(random_bytes(4))
        );
        $dir = $base . '/' . $snapshotId;

        if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) {
            return ['error' => 'Unable to create the private Style backup directory.', 'code' => 'snapshot_failed'];
        }

        @chmod($base, 0700);

        if (!mkdir($dir, 0700) || !is_dir($dir)) {
            return ['error' => 'Unable to create the Style snapshot.', 'code' => 'snapshot_failed'];
        }

        @chmod($dir, 0700);
        $paramsPath = $dir . '/params.raw.json';

        if (file_put_contents($paramsPath, $encodedParams, LOCK_EX) !== strlen($encodedParams)) {
            return ['error' => 'Unable to snapshot Joomla Style params.', 'code' => 'snapshot_failed'];
        }
        @chmod($paramsPath, 0600);

        $manifestFiles = [];
        $originalFiles = [];

        foreach ($files as $file) {
            $name = basename($file);
            $present = is_file($file);
            $content = $present ? file_get_contents($file) : null;

            if ($present && !is_string($content)) {
                return ['error' => sprintf('Unable to read %s for the snapshot.', $name), 'code' => 'snapshot_failed'];
            }

            $originalFiles[$file] = $content;
            $manifestFiles[$file] = [
                'name' => $name,
                'present' => $present,
                'bytes' => is_string($content) ? strlen($content) : 0,
                'sha256' => is_string($content) ? hash('sha256', $content) : null,
            ];

            if (is_string($content)) {
                $snapshotFile = $dir . '/' . $name;
                if (file_put_contents($snapshotFile, $content, LOCK_EX) !== strlen($content)) {
                    return ['error' => sprintf('Unable to snapshot %s.', $name), 'code' => 'snapshot_failed'];
                }
                @chmod($snapshotFile, 0600);
            }
        }

        $manifest = [
            'snapshot_id' => $snapshotId,
            'platform' => 'joomla',
            'template_style_id' => $templateStyleId,
            'created_at' => gmdate('c'),
            'params_sha256' => hash('sha256', $encodedParams),
            'files' => array_values($manifestFiles),
        ];
        $manifestPath = $dir . '/manifest.json';
        $encodedManifest = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        if (file_put_contents($manifestPath, $encodedManifest, LOCK_EX) !== strlen($encodedManifest)) {
            return ['error' => 'Unable to write the Style snapshot manifest.', 'code' => 'snapshot_failed'];
        }
        @chmod($manifestPath, 0600);

        return $manifest + [
            'dir' => $dir,
            'encoded_params' => $encodedParams,
            'original_files' => $originalFiles,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array{restored: bool, failures: list<string>}
     */
    private function restoreSnapshotFiles(array $snapshot): array
    {
        $failures = [];
        $originalFiles = is_array($snapshot['original_files'] ?? null)
            ? $snapshot['original_files']
            : [];

        foreach ($originalFiles as $file => $content) {
            if (!is_string($file)) {
                continue;
            }

            if ($content === null) {
                if (is_file($file) && !@unlink($file)) {
                    $failures[] = sprintf('Unable to remove newly created %s.', $file);
                }
                continue;
            }

            if (!is_string($content) || file_put_contents($file, $content, LOCK_EX) !== strlen($content)) {
                $failures[] = sprintf('Unable to restore %s.', $file);
            }
        }

        return ['restored' => $failures === [], 'failures' => $failures];
    }

    /**
     * @return array{cleared: bool, groups: list<string>, failures: list<array{group: string, error: string}>}
     */
    private function invalidateStyleCache(): array
    {
        $groups = ['com_templates', 'plg_system_yootheme', 'yootheme', 'page', '_system'];
        $cleaned = [];
        $failures = [];

        foreach ($groups as $group) {
            try {
                Factory::getCache($group)->clean();
                $cleaned[] = $group;
            } catch (\Throwable $exception) {
                $failures[] = ['group' => $group, 'error' => $exception->getMessage()];
            }
        }

        return [
            'cleared' => $cleaned !== [] && $failures === [],
            'groups' => $cleaned,
            'failures' => $failures,
        ];
    }

    private function database(): DatabaseInterface
    {
        if ($this->db === null) {
            $this->db = Factory::getContainer()->get(DatabaseInterface::class);
        }

        return $this->db;
    }

    private function themeConfig(): ?object
    {
        $bootstrap = $this->ensureRuntime();

        if (isset($bootstrap['error'])) {
            return null;
        }

        try {
            $config = \YOOtheme\app()(\YOOtheme\Config::class);

            return is_object($config) ? $config : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function styleDirectories(): array
    {
        return ['theme' => $this->themeDir() . '/less'];
    }

    private function themeDir(): string
    {
        return $this->siteRoot . '/templates/yootheme';
    }

    private function relativeToRoot(string $path): string
    {
        $root = $this->siteRoot . '/';

        return str_starts_with($path, $root)
            ? ltrim(substr($path, strlen($root)), '/')
            : $path;
    }

    /**
     * @return list<string>
     */
    private function splitList(string $value): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $item): bool => $item !== ''
        ));
    }

    private function namify(string $id): string
    {
        return ucwords(str_replace('-', ' ', $id));
    }
}
