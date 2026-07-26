<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

/**
 * Read access to the YOOtheme Pro 5 Style layer.
 *
 * Three facts about the native system drive this class:
 *
 * 1. Style configuration does NOT live in the `yootheme` option. That option
 *    holds Builder templates. The Style lives in `theme_mods_yootheme` under
 *    the `config` key, itself a JSON string.
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
        ];
    }

    /**
     * Decoded `theme_mods_yootheme.config`, secrets included.
     * Never return this to a caller without running it through redact().
     *
     * @return array<string, mixed>
     */
    public function loadConfig(): array
    {
        $mods = get_theme_mod('config', null);

        if (!is_string($mods) || $mods === '') {
            $raw = get_option('theme_mods_yootheme', []);
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
            return [
                'present' => false,
                'file' => null,
                'stale_version' => null,
                'stale_sources' => null,
            ];
        }

        $header = (string) file_get_contents($file, false, null, 0, 128);
        $compiledVersion = preg_match('/YOOtheme Pro v([\w\d.\-]+)/', $header, $m) ? $m[1] : null;
        $compiledAt = preg_match('/compiled on ([0-9T:+\-]+)/', $header, $m) ? $m[1] : null;
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
            'stale_version' => $compiledVersion !== null && $compiledVersion !== $this->themeVersion(),
            'stale_sources' => $newest['mtime'] !== null && $newest['mtime'] > $mtime,
            'newest_source' => $newest['file'],
            'newest_source_mtime' => $newest['mtime'] !== null ? gmdate('c', $newest['mtime']) : null,
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
