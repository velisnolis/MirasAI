<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

/**
 * Read access to the YOOtheme Pro 5 Style layer.
 *
 * Three facts about the native system drive this class:
 *
 * 1. Style configuration does NOT live in the `yootheme` option. That option
 *    holds Builder templates. The Style lives in `theme_mods_{stylesheet}`
 *    under the `config` key, itself a JSON string. With a child theme that is
 *    `theme_mods_<child>`, not the parent `theme_mods_yootheme` row.
 * 2. That JSON also carries `yootheme_apikey`. It must never be returned to a
 *    caller, and must be preserved byte for byte by any future writer.
 * 3. YOOtheme Pro 5 has NO server-side Less compiler. `GET /theme/style` hands
 *    the browser the whole import tree and a web worker compiles it; the server
 *    only stores the CSS that comes back. So the compiled CSS can silently fall
 *    behind its own sources, and detecting that is part of reading the state.
 */
class YoothemeStyleHelper
{
    private const REDACTED_KEYS = ['yootheme_apikey'];
    private const STYLE_PROVENANCE_OPTION = 'mirasai_yootheme_style_compile_state';

    /**
     * Style config keys that are read-only context rather than style input.
     */
    private const STYLE_KEYS = ['style', 'less', 'custom_less'];

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $theme = wp_get_theme('yootheme');

        return [
            'active' => get_template() === 'yootheme',
            'installed' => $theme->exists(),
            'version' => $theme->exists() ? (string) $theme->get('Version') : null,
            'container_available' => $this->containerAvailable(),
            'read_tool' => 'template/style-read',
            'sources_tool' => 'template/style-sources',
            'create_tool' => 'template/style-create',
        ];
    }

    /**
     * Option that stores the live Style JSON for this request.
     *
     * YOOtheme writes `config` through theme mods of the active stylesheet.
     * A child theme therefore has its own `theme_mods_<child>` row; reading
     * via `get_theme_mod('config')` and writing `theme_mods_yootheme` is a
     * CAS mismatch that aborts the guarded write.
     */
    public function styleModsOptionName(): string
    {
        return $this->styleStorageState()['source_option'];
    }

    /**
     * Resolve the effective Style source separately from the active stylesheet's
     * write target. A freshly activated child can inherit the parent config while
     * its own theme_mods row has no config yet. Reading that fallback is useful,
     * but mutating it would silently change the parent theme.
     *
     * @return array{
     *   active_option: string,
     *   source_option: string,
     *   inherited_from_parent: bool,
     *   write_safe: bool,
     *   reason: ?string
     * }
     */
    public function styleStorageState(): array
    {
        $stylesheet = function_exists('get_stylesheet') ? (string) get_stylesheet() : 'yootheme';
        $template = function_exists('get_template') ? (string) get_template() : 'yootheme';
        $activeOption = 'theme_mods_' . ($stylesheet !== '' ? $stylesheet : 'yootheme');
        $parentOption = 'theme_mods_' . ($template !== '' ? $template : 'yootheme');
        $activeMods = get_option($activeOption, []);
        $activeConfigPresent = is_array($activeMods)
            && is_string($activeMods['config'] ?? null)
            && $activeMods['config'] !== '';
        $isChild = $stylesheet !== '' && $template !== '' && $stylesheet !== $template;

        if ($activeConfigPresent) {
            return [
                'active_option' => $activeOption,
                'source_option' => $activeOption,
                'inherited_from_parent' => false,
                'write_safe' => true,
                'reason' => null,
            ];
        }

        if ($isChild) {
            return [
                'active_option' => $activeOption,
                'source_option' => $parentOption,
                'inherited_from_parent' => true,
                'write_safe' => false,
                'reason' => 'The active child theme has no Style config of its own and is reading the parent fallback. Initialize the child config explicitly before writing.',
            ];
        }

        return [
            'active_option' => $activeOption,
            'source_option' => $activeOption,
            'inherited_from_parent' => false,
            'write_safe' => $activeConfigPresent,
            'reason' => $activeConfigPresent
                ? null
                : 'The active theme has no initialized Style config to update.',
        ];
    }

    /**
     * Decoded Style `config`, secrets included.
     * Never return this to a caller without running it through redact().
     *
     * @return array<string, mixed>
     */
    public function loadConfig(): array
    {
        $mods = get_theme_mod('config', null);

        if (!is_string($mods) || $mods === '') {
            $raw = get_option($this->styleModsOptionName(), []);
            $mods = is_array($raw) && is_string($raw['config'] ?? null) ? $raw['config'] : '';
        }

        if ($mods === '') {
            return [];
        }

        $decoded = json_decode($mods, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function redact(array $config): array
    {
        foreach (self::REDACTED_KEYS as $key) {
            if (array_key_exists($key, $config)) {
                $config[$key] = $config[$key] === '' || $config[$key] === null
                    ? null
                    : '__redacted__';
            }
        }

        return $config;
    }

    /**
     * The active style id and variation. `flow:white-pink` means style `flow`,
     * variation `white-pink`; a bare `flow` means the style's own defaults.
     *
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
     * Variable overrides and custom Less currently applied on top of the style.
     *
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
            'customised' => (is_array($less) && $less !== []) || (is_string($custom) && $custom !== ''),
        ];
    }

    /**
     * Selects the stored overrides which belong in one compilation.
     *
     * The active config belongs to the active style. Applying its variation to
     * a different style can resolve an unrelated optional import, while its
     * variable overrides can make a candidate style look unlike its defaults.
     * Non-active styles therefore start clean unless the caller explicitly asks
     * to carry the active customizations across.
     *
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
            'less' => $useActive && is_array($config['less'] ?? null) ? $config['less'] : [],
            'custom_less' => $useActive && is_string($config['custom_less'] ?? null)
                ? $config['custom_less']
                : '',
            'source' => $useActive ? 'active_config' : 'style_defaults',
        ];
    }

    /**
     * Styles available to the Style library: every `less/theme.*.less` in the
     * parent theme and, when present, the child theme. A child theme dropping a
     * file there is the native way to add a style.
     *
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

                if ($id === '') {
                    continue;
                }

                $styles[$id] = ['id' => $id, 'source' => $source] + $this->parseStyleMeta($file);
            }
        }

        ksort($styles);

        return array_values($styles);
    }

    /**
     * Parses the leading block comment of a style file the same way
     * Styler::getMeta() does: Name/Background/Color/Type/Preview, plus one
     * `Style:` block per variation.
     *
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

        foreach ($matches[1] as $i => $rawKey) {
            $key = strtolower(trim($rawKey));
            $value = trim($matches[2][$i]);

            if ($key === 'style') {
                $current = $value;
                $meta['variations'][$current] = ['id' => $current, 'name' => $this->namify($current)];
                continue;
            }

            if ($current === null) {
                $meta[$key] = $key === 'name' ? $value : $this->splitList($value);
                continue;
            }

            $meta['variations'][$current][$key] = $key === 'name' ? $value : $this->splitList($value);
        }

        $meta['variations'] = array_values($meta['variations']);

        return $meta;
    }

    /**
     * State of the compiled CSS, including whether it has fallen behind.
     *
     * `stale_version` is what YOOtheme's own StylerConfig checks: the version
     * stamped in the CSS header against the running theme version.
     *
     * `stale_sources` is ours, and it is the one that catches the common case:
     * a plugin that contributes Less to the Style updates, nobody reopens the
     * customizer, and the site keeps serving CSS compiled before the change.
     *
     * @return array<string, mixed>
     */
    public function compiledState(): array
    {
        $themeId = $this->themeConfigId();
        $dir = $this->themeDir() . '/css';
        $file = $dir . '/theme.' . $themeId . '.css';

        if (!is_file($file)) {
            $file = $dir . '/theme.css';
        }

        if (!is_file($file)) {
            $configFreshness = $this->configFreshness($this->loadConfig());

            return [
                'present' => false,
                'file' => null,
                'stale_version' => null,
                'stale_sources' => null,
                'freshness_method' => 'broad_less_mtime_heuristic',
                'freshness_caveat' => 'The broad stale_sources heuristic ignores Style config. Config freshness is only provable after a router-controlled compile; missing or externally replaced CSS is reported as unknown.',
                'stale_config_detectable' => $configFreshness['state'] !== 'unknown',
                'stale_config' => null,
                'config_freshness' => $configFreshness,
            ];
        }

        $header = (string) file_get_contents($file, false, null, 0, 128);
        $compiledVersion = preg_match('/YOOtheme Pro v([\w\d.\-]+)/', $header, $m) ? $m[1] : null;
        $compiledAt = preg_match('/compiled on ([0-9T:+\-]+)/', $header, $m) ? $m[1] : null;
        $mtime = (int) filemtime($file);

        $newest = $this->newestSourceMtime();
        $configFreshness = $this->configFreshness($this->loadConfig());

        return [
            'present' => true,
            'file' => $this->relativeToRoot($file),
            'bytes' => (int) filesize($file),
            'mtime' => gmdate('c', $mtime),
            'compiled_at' => $compiledAt,
            'compiled_version' => $compiledVersion,
            'theme_version' => $this->themeVersion(),
            'stale_version' => $compiledVersion !== null && $compiledVersion !== $this->themeVersion(),
            'stale_sources' => $newest['mtime'] !== null && $newest['mtime'] > $mtime,
            'freshness_method' => 'broad_less_mtime_heuristic',
            'freshness_caveat' => 'stale_sources ignores Style config and only compares Less mtimes. config_freshness proves config drift when the current CSS still matches a router-controlled compile; external CSS changes are conservatively unknown.',
            'stale_config_detectable' => $configFreshness['state'] !== 'unknown',
            'stale_config' => $configFreshness['state'] === 'stale'
                ? true
                : ($configFreshness['state'] === 'fresh' ? false : null),
            'config_freshness' => $configFreshness,
            'newest_source' => $newest['file'],
            'newest_source_mtime' => $newest['mtime'] !== null ? gmdate('c', $newest['mtime']) : null,
        ];
    }

    /**
     * Proves whether the stored Style config still belongs to the CSS emitted
     * by the last router-controlled compile. The proof is deliberately lost
     * when any CSS artefact changes outside that path: such a replacement may
     * be perfectly valid, so calling it stale would be a false positive.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function configFreshness(array $config): array
    {
        $unknown = static fn (string $reason): array => [
            'state' => 'unknown',
            'method' => 'router_provenance_v1',
            'reason' => $reason,
            'recorded_at' => null,
            'worker_sha256' => null,
            'sources_sha256' => null,
        ];
        $record = get_option(self::STYLE_PROVENANCE_OPTION, null);

        if (!is_array($record) || ($record['version'] ?? null) !== 1) {
            return $unknown('no_router_provenance');
        }

        $storage = $this->styleStorageState();
        if (!is_string($record['theme_mods_option'] ?? null)
            || $record['theme_mods_option'] !== $storage['source_option']) {
            return $unknown('style_storage_changed');
        }

        $storedCss = is_array($record['css_sha256'] ?? null) ? $record['css_sha256'] : [];
        $configHash = strtolower((string) ($record['config_sha256'] ?? ''));
        $workerHash = strtolower((string) ($record['worker_sha256'] ?? ''));
        $sourcesHash = strtolower((string) ($record['sources_sha256'] ?? ''));
        if ($storedCss === []
            || !preg_match('/^[a-f0-9]{64}$/', $configHash)
            || !preg_match('/^[a-f0-9]{64}$/', $workerHash)
            || !preg_match('/^[a-f0-9]{64}$/', $sourcesHash)) {
            return $unknown('invalid_router_provenance');
        }

        $currentCss = [];
        foreach (array_keys($this->styleCssTargets()) as $path) {
            $relative = $this->relativeToRoot($path);
            $currentCss[$relative] = is_file($path) ? hash_file('sha256', $path) : null;
        }
        ksort($storedCss);
        ksort($currentCss);

        if ($storedCss !== $currentCss) {
            return $unknown('compiled_css_changed_outside_router');
        }

        $state = hash_equals($configHash, $this->configHash($config)) ? 'fresh' : 'stale';

        return [
            'state' => $state,
            'method' => 'router_provenance_v1',
            'reason' => $state === 'fresh' ? 'config_and_css_match' : 'config_changed_after_compile',
            'recorded_at' => is_string($record['recorded_at'] ?? null) ? $record['recorded_at'] : null,
            'worker_sha256' => $workerHash,
            'sources_sha256' => $sourcesHash,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configHash(array $config): string
    {
        // Only values consumed by the Style compiler belong in this hash.
        // In particular, yootheme_apikey and bookkeeping fields must neither
        // influence CSS freshness nor become part of its provenance input.
        $normalized = $this->normalizeForHash([
            'style' => is_string($config['style'] ?? null) ? $config['style'] : '',
            'less' => is_array($config['less'] ?? null) ? $config['less'] : [],
            'custom_less' => is_string($config['custom_less'] ?? null) ? $config['custom_less'] : '',
        ]);
        $encoded = wp_json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', is_string($encoded) ? $encoded : 'null');
    }

    private function normalizeForHash(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeForHash($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeForHash($item);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $cssHashes
     * @param array<string, mixed> $compileProvenance
     * @return array<string, mixed>
     */
    private function recordCompileProvenance(
        array $config,
        string $modsOption,
        array $cssHashes,
        array $compileProvenance
    ): array {
        if ($compileProvenance === []) {
            return ['stored' => false, 'reason' => 'compile_provenance_not_supplied'];
        }

        $workerHash = strtolower((string) ($compileProvenance['worker_sha256'] ?? ''));
        $sourcesHash = strtolower((string) ($compileProvenance['sources_sha256'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $workerHash)
            || !preg_match('/^[a-f0-9]{64}$/', $sourcesHash)
            || $cssHashes === []) {
            return ['stored' => false, 'reason' => 'invalid_compile_provenance'];
        }

        ksort($cssHashes);
        $record = [
            'version' => 1,
            'theme_mods_option' => $modsOption,
            'config_sha256' => $this->configHash($config),
            'css_sha256' => $cssHashes,
            'worker_sha256' => $workerHash,
            'sources_sha256' => $sourcesHash,
            'recorded_at' => gmdate('c'),
        ];
        update_option(self::STYLE_PROVENANCE_OPTION, $record, false);
        $stored = get_option(self::STYLE_PROVENANCE_OPTION, null);

        return [
            'stored' => $stored === $record,
            'version' => 1,
            'worker_sha256' => $workerHash,
            'sources_sha256' => $sourcesHash,
            ...($stored === $record ? [] : ['reason' => 'provenance_write_not_verified']),
        ];
    }

    /**
     * Newest modification time across everything that feeds the compiled CSS:
     * the theme's own Less, the child theme's Less, and any Less contributed by
     * plugins through `theme.styles.components`.
     *
     * @return array{file: ?string, mtime: ?int}
     */
    public function newestSourceMtime(): array
    {
        $newest = ['file' => null, 'mtime' => null];

        $roots = [];
        foreach ($this->styleDirectories() as $dir) {
            $roots[] = $dir;
        }
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
                    $newest = ['file' => $this->relativeToRoot($item->getPathname()), 'mtime' => $mtime];
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
     * Less files injected into the Style by plugins, declared through the
     * `theme.styles.components` config. This is how third-party plugins add
     * their own Less, and the usual reason compiled CSS goes stale.
     *
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

            $matches = glob($pattern);

            if (is_array($matches)) {
                foreach ($matches as $match) {
                    $files[] = $match;
                }
            }
        }

        return $files;
    }

    /**
     * Locally stored web fonts. YOOtheme downloads the Google Fonts referenced
     * by the compiled CSS into the theme's fonts directory and rewrites the
     * import, so this reflects the fonts the site actually serves.
     *
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
     * @return array<string, mixed>
     */
    public function childTheme(): array
    {
        $stylesheet = get_stylesheet();
        $present = $stylesheet !== get_template();

        return [
            'present' => $present,
            'stylesheet' => $stylesheet,
            'dir' => $present ? $this->relativeToRoot(get_stylesheet_directory()) : null,
            'less_dir_present' => $present && is_dir(get_stylesheet_directory() . '/less'),
        ];
    }

    /**
     * Identifies the state a writer must match. Covers both the stored config
     * and the compiled artefact, because a style write has to keep them in step.
     *
     * @param array<string, mixed> $config
     */
    public function etag(array $config, array $compiled): string
    {
        return substr(hash('sha256', (string) wp_json_encode([
            'config' => $config,
            'css' => [$compiled['file'] ?? null, $compiled['bytes'] ?? null, $compiled['mtime'] ?? null],
        ])), 0, 32);
    }

    /**
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
     * @param array<string, mixed> $candidateConfig
     * @return array<string, mixed>
     */
    public function commitStyleUpdate(
        array $candidateConfig,
        string $compiledCss,
        string $compiledRtl,
        string $ifMatch,
        array $compileProvenance = []
    ): array {
        $storage = $this->styleStorageState();
        if (!$storage['write_safe']) {
            return [
                'error' => $storage['reason'],
                'code' => 'style_storage_uninitialized',
                'storage' => $storage,
            ];
        }
        $modsOption = $storage['source_option'];

        $freshConfig = $this->loadConfig();
        $freshCompiled = $this->compiledState();
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

        $encodedConfig = wp_json_encode(
            $candidateConfig,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($encodedConfig)) {
            return [
                'error' => 'Unable to encode the candidate Style config.',
                'code' => 'style_config_encode_failed',
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

        $writeConfig = $this->loadConfig();
        $writeCompiled = $this->compiledState();
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

        // Re-read the full option at the write gate so unrelated theme mods
        // changed concurrently are preserved even though the Style ETag
        // intentionally covers config + CSS only. Must be the same row
        // loadConfig() used (child theme mods when a child is active).
        $writeStorage = $this->styleStorageState();
        if (!$writeStorage['write_safe'] || $writeStorage['source_option'] !== $modsOption) {
            foreach ($staged as $temporary) {
                @unlink($temporary);
            }

            return [
                'error' => 'Style storage changed while CSS was being prepared. Re-read it and retry.',
                'code' => 'stale_etag',
                'storage' => $writeStorage,
            ];
        }

        $mods = get_option($modsOption, []);
        if (!is_array($mods)) {
            foreach ($staged as $temporary) {
                @unlink($temporary);
            }

            return [
                'error' => $modsOption . ' is not an array.',
                'code' => 'invalid_style_params',
            ];
        }

        $modsConfig = is_string($mods['config'] ?? null)
            ? json_decode($mods['config'], true)
            : null;
        if (!is_array($modsConfig) || $modsConfig !== $writeConfig) {
            foreach ($staged as $temporary) {
                @unlink($temporary);
            }

            return [
                'error' => 'Style config changed at the write gate. Re-read it and retry.',
                'code' => 'stale_etag',
            ];
        }

        $candidateMods = $mods;
        $candidateMods['config'] = $encodedConfig;
        $snapshot = $this->createStyleSnapshot($mods, array_keys($targets), $modsOption);

        if (isset($snapshot['error'])) {
            foreach ($staged as $temporary) {
                @unlink($temporary);
            }

            return $snapshot;
        }

        $renamed = [];
        $configWritten = false;

        try {
            $configWrite = $this->compareAndSwapThemeMods($mods, $candidateMods, $modsOption);
            $configWritten = ($configWrite['changed'] ?? false) === true;
            if (($configWrite['ok'] ?? false) !== true) {
                throw new \RuntimeException(
                    (string) ($configWrite['error'] ?? $modsOption . ' changed concurrently.')
                );
            }

            foreach ($staged as $target => $temporary) {
                if (!@rename($temporary, $target)) {
                    throw new \RuntimeException(sprintf('Unable to replace %s atomically.', $target));
                }
                $renamed[] = $target;
            }
        } catch (\Throwable $exception) {
            $configRollback = [
                'attempted' => $configWritten,
                'restored' => true,
            ];
            if ($configWritten) {
                $rollbackWrite = $this->compareAndSwapThemeMods($candidateMods, $mods, $modsOption);
                $configRollback = [
                    'attempted' => true,
                    'restored' => ($rollbackWrite['ok'] ?? false) === true,
                    ...(($rollbackWrite['ok'] ?? false) === true
                        ? []
                        : ['error' => (string) ($rollbackWrite['error'] ?? 'Unknown config rollback failure.')]),
                ];
            }

            foreach ($staged ?? [] as $temporary) {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }

            $fileRollback = $this->restoreSnapshotFiles($snapshot);
            $rollbackFailures = $fileRollback['failures'];
            if (($configRollback['restored'] ?? false) !== true) {
                $rollbackFailures[] = 'Unable to restore ' . $modsOption . ' without overwriting a concurrent change.';
            }
            $rollback = [
                'restored' => ($configRollback['restored'] ?? false) === true
                    && ($fileRollback['restored'] ?? false) === true,
                'config' => $configRollback,
                'files' => $fileRollback,
                'failures' => $rollbackFailures,
            ];

            return [
                'error' => ($rollback['restored']
                    ? 'Style write failed and rollback completed: '
                    : 'Style write failed and rollback is incomplete; restore from the private snapshot before retrying: ')
                    . $exception->getMessage(),
                'code' => 'style_write_failed',
                'snapshot_id' => $snapshot['snapshot_id'],
                'rollback' => $rollback,
                'renamed_before_failure' => array_map([$this, 'relativeToRoot'], $renamed),
            ];
        }

        clearstatcache(true);
        $updatedConfig = $this->loadConfig();
        $paths = array_keys($staged);
        $relativePaths = array_map([$this, 'relativeToRoot'], $paths);
        $writtenHashes = array_combine(
            $relativePaths,
            array_map(static fn (string $path): string => (string) hash_file('sha256', $path), $paths)
        );
        $provenance = $this->recordCompileProvenance(
            $updatedConfig,
            $modsOption,
            is_array($writtenHashes) ? $writtenHashes : [],
            $compileProvenance
        );
        $updatedCompiled = $this->compiledState();

        return [
            'snapshot_id' => $snapshot['snapshot_id'],
            'written_files' => $relativePaths,
            'written_sha256' => $writtenHashes,
            'provenance' => $provenance,
            'cache' => $this->invalidateStyleCache(),
            'new_etag' => $this->etag($updatedConfig, $updatedCompiled),
            'compiled' => $updatedCompiled,
        ];
    }

    /**
     * Everything a headless compiler needs, mirroring what
     * `GET /theme/style` hands the browser: the entry file, the fully resolved
     * import tree, and the forced style vars.
     *
     * Requires the YOOtheme container, because `Styler::resolveImports()` and
     * the `styler.imports` event are the only faithful way to build the tree.
     * Reimplementing them here would drift from the installed version.
     *
     * @return array<string, mixed>|array{error: string, code: string}
     */
    public function sources(string $styleId): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $styleId)) {
            return ['error' => 'style_id may only contain letters, numbers, underscores, and hyphens.', 'code' => 'invalid_style_id'];
        }

        if (!$this->containerAvailable()) {
            return [
                'error' => 'The YOOtheme container is not available in this request, so the import tree cannot be resolved faithfully.',
                'code' => 'container_unavailable',
            ];
        }

        $app = \YOOtheme\app();

        try {
            $styler = $app(\YOOtheme\Theme\Styler\Styler::class);
            $config = $app(\YOOtheme\Config::class);
            $theme = $styler->getTheme($styleId);

            if (!is_array($theme)) {
                return ['error' => sprintf('Style "%s" not found.', $styleId), 'code' => 'style_not_found'];
            }

            $imports = \YOOtheme\Event::emit('styler.imports|filter', [], $styleId);
            $filename = \YOOtheme\Url::to($theme['file']);
        } catch (\Throwable $e) {
            return [
                'error' => 'Failed to resolve the style import tree: ' . $e->getMessage(),
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
        ];
    }

    /**
     * Only the entry point can be probed cheaply.
     *
     * Do NOT extend this with class_exists() on Styler or Config: those are
     * resolved by YOOtheme's own container from its bootstrap files, not by a
     * PHP autoloader, so class_exists() reports false even when the container
     * resolves them perfectly well. Callers must attempt resolution and catch.
     */
    public function containerAvailable(): bool
    {
        return function_exists('YOOtheme\\app');
    }

    /**
     * The YOOtheme Config service, or null when the container is unavailable.
     */
    private function themeConfig(): ?object
    {
        if (!$this->containerAvailable()) {
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
     * Compiled CSS is keyed by the theme config id, not by style name:
     * `theme.1.css`, plus `theme.css` as the default fallback.
     */
    public function themeConfigId(): string
    {
        $config = $this->themeConfig();

        if ($config !== null) {
            $id = $config('theme.id');

            if (is_string($id) || is_int($id)) {
                return (string) $id;
            }
        }

        return '1';
    }

    public function themeVersion(): ?string
    {
        $theme = wp_get_theme('yootheme');

        return $theme->exists() ? (string) $theme->get('Version') : null;
    }

    /**
     * @return array<string, string>
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

        if ($this->containerAvailable()) {
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
     * @return array<string, string>
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
     * @param array<string, mixed> $mods
     * @param list<string> $files
     * @return array<string, mixed>
     */
    private function createStyleSnapshot(array $mods, array $files, string $modsOption): array
    {
        $root = defined('ABSPATH') ? rtrim((string) ABSPATH, '/') : $this->themeDir();
        $base = dirname($root) . '/mirasai-backups/style';
        $snapshotId = sprintf('wordpress-style-%s-%s', gmdate('Ymd-His'), bin2hex(random_bytes(4)));
        $dir = $base . '/' . $snapshotId;

        if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) {
            return ['error' => 'Unable to create the private Style backup directory.', 'code' => 'snapshot_failed'];
        }
        @chmod($base, 0700);

        if (!mkdir($dir, 0700) || !is_dir($dir)) {
            return ['error' => 'Unable to create the Style snapshot.', 'code' => 'snapshot_failed'];
        }
        @chmod($dir, 0700);

        $serializedMods = serialize($mods);
        $safeOptionName = preg_replace('/[^A-Za-z0-9_.-]/', '_', $modsOption) ?: 'theme_mods';
        $modsPath = $dir . '/' . $safeOptionName . '.serialized';
        if (file_put_contents($modsPath, $serializedMods, LOCK_EX) !== strlen($serializedMods)) {
            return ['error' => 'Unable to snapshot ' . $modsOption . '.', 'code' => 'snapshot_failed'];
        }
        @chmod($modsPath, 0600);

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
            $manifestFiles[] = [
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
            'platform' => 'wordpress',
            'created_at' => gmdate('c'),
            'theme_mods_option' => $modsOption,
            'theme_mods_sha256' => hash('sha256', $serializedMods),
            'files' => $manifestFiles,
        ];
        $encodedManifest = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $manifestPath = $dir . '/manifest.json';

        if (!is_string($encodedManifest)
            || file_put_contents($manifestPath, $encodedManifest, LOCK_EX) !== strlen($encodedManifest)) {
            return ['error' => 'Unable to write the Style snapshot manifest.', 'code' => 'snapshot_failed'];
        }
        @chmod($manifestPath, 0600);

        return $manifest + ['original_files' => $originalFiles];
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
            if (!is_string($file)) continue;

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
     * Replace the whole theme-mods option only when its raw serialized value
     * still matches the value read at the write gate. WordPress' option API has
     * no compare-and-swap primitive, so the guarded writer performs the
     * conditional UPDATE directly and clears the option caches afterwards.
     *
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $candidate
     * @return array{ok: bool, changed: bool, error?: string}
     */
    private function compareAndSwapThemeMods(array $expected, array $candidate, string $optionName): array
    {
        if ($expected === $candidate) {
            return ['ok' => true, 'changed' => false];
        }

        global $wpdb;

        if (!is_object($wpdb)
            || !isset($wpdb->options)
            || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'query')) {
            return [
                'ok' => false,
                'changed' => false,
                'error' => 'WordPress database compare-and-swap is unavailable.',
            ];
        }

        $serialize = static fn (array $value): string => function_exists('maybe_serialize')
            ? (string) maybe_serialize($value)
            : serialize($value);
        $expectedSerialized = $serialize($expected);
        $candidateSerialized = $serialize($candidate);
        $query = $wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            $candidateSerialized,
            $optionName,
            $expectedSerialized
        );
        $affected = $wpdb->query($query);

        if ($affected !== 1) {
            return [
                'ok' => false,
                'changed' => false,
                'error' => $optionName . ' changed concurrently at the write gate.',
            ];
        }

        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($optionName, 'options');
            wp_cache_delete('alloptions', 'options');
        }

        $readback = get_option($optionName, []);
        if ($readback !== $candidate) {
            return [
                'ok' => false,
                'changed' => true,
                'error' => $optionName . ' compare-and-swap could not be verified.',
            ];
        }

        return ['ok' => true, 'changed' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function invalidateStyleCache(): array
    {
        $cleared = [];

        if (function_exists('wp_cache_delete')) {
            $modsOption = $this->styleModsOptionName();
            wp_cache_delete($modsOption, 'options');
            $cleared[] = $modsOption;
        }

        if (function_exists('wp_clean_themes_cache')) {
            wp_clean_themes_cache();
            $cleared[] = 'themes';
        }

        return [
            'cleared' => $cleared !== [],
            'groups' => $cleared,
            'failures' => [],
        ];
    }

    /**
     * @return array<string, string> source label => directory
     */
    private function styleDirectories(): array
    {
        $dirs = ['theme' => $this->themeDir() . '/less'];
        $child = get_stylesheet_directory();

        if ($child !== get_template_directory() && is_dir($child . '/less')) {
            $dirs['child'] = $child . '/less';
        }

        return $dirs;
    }

    private function themeDir(): string
    {
        return rtrim(get_template_directory(), '/');
    }

    private function relativeToRoot(string $path): string
    {
        $root = defined('ABSPATH') ? rtrim((string) ABSPATH, '/') . '/' : '';

        if ($root !== '' && str_starts_with($path, $root)) {
            return ltrim(substr($path, strlen($root)), '/');
        }

        return $path;
    }

    /**
     * @return list<string>
     */
    private function splitList(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn($v) => $v !== ''));
    }

    private function namify(string $id): string
    {
        return ucwords(str_replace('-', ' ', $id));
    }
}
