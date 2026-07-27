<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class TemplateStyleSourcesTool extends AbstractTool
{
    /**
     * The resolved import tree for a full style runs to roughly 800 KB across
     * ~280 files. Callers that only want to inspect the shape should leave
     * include_imports off.
     */
    private const DEFAULT_MAX_BYTES = 4194304;

    public function getName(): string
    {
        return 'template/style-sources';
    }

    public function getDescription(): string
    {
        return 'Returns everything needed to compile a YOOtheme Pro style outside the browser: the entry Less file, the fully resolved import tree (url => Less source), forced style vars, and the active overrides. YOOtheme has no server-side Less compiler, so a client must compile this tree itself. Read-only.';
    }

    public function getSurface(): string
    {
        return 'advanced';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'style_id' => [
                    'type' => 'string',
                    'description' => 'Style to resolve, for example "flow". Defaults to the active style.',
                ],
                'apply_active_overrides' => [
                    'type' => 'boolean',
                    'description' => 'Apply the active style variation, Less overrides, and custom Less when previewing a different style. Defaults to false; active styles always receive their own stored overrides.',
                ],
                'include_imports' => [
                    'type' => 'boolean',
                    'description' => 'Include the import tree contents. Defaults to true. Set false to inspect only the shape and size.',
                ],
                'max_bytes' => [
                    'type' => 'integer',
                    'description' => 'Refuse to return an import tree larger than this. Defaults to 4 MiB.',
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
        $helper = new YoothemeStyleHelper();
        $status = $helper->status();

        if (!$status['installed'] || !$status['active']) {
            return ['error' => 'YOOtheme Pro is not the active template.', 'code' => 'yootheme_inactive'];
        }

        $config = $helper->loadConfig();
        $active = $helper->activeStyle($config);

        $styleId = $arguments['style_id'] ?? null;

        if ($styleId !== null && !is_string($styleId)) {
            return ['error' => 'style_id must be a string.', 'code' => 'invalid_style_id'];
        }

        $styleId = is_string($styleId) && $styleId !== '' ? $styleId : $active['style_id'];

        if ($styleId === '') {
            return ['error' => 'No style is configured and no style_id was given.', 'code' => 'no_style'];
        }

        $sources = $helper->sources($styleId);

        if (isset($sources['error'])) {
            return $sources;
        }

        $maxBytes = $arguments['max_bytes'] ?? self::DEFAULT_MAX_BYTES;

        if (!is_int($maxBytes) || $maxBytes <= 0) {
            return ['error' => 'max_bytes must be a positive integer.', 'code' => 'invalid_max_bytes'];
        }

        $includeImports = ($arguments['include_imports'] ?? true) !== false;

        if ($includeImports && $sources['import_bytes'] > $maxBytes) {
            return [
                'error' => sprintf(
                    'The import tree is %d bytes, over the %d byte limit. Raise max_bytes or set include_imports to false.',
                    $sources['import_bytes'],
                    $maxBytes
                ),
                'code' => 'imports_too_large',
                'import_count' => $sources['import_count'],
                'import_bytes' => $sources['import_bytes'],
            ];
        }

        if (!$includeImports) {
            $sources['import_files'] = array_keys($sources['imports']);
            unset($sources['imports']);
        }

        $compiled = $helper->compiledState();
        $isActiveStyle = $styleId === $active['style_id'];
        $overrides = $helper->compilationOverrides(
            $config,
            $active,
            $styleId,
            ($arguments['apply_active_overrides'] ?? false) === true
        );

        return $sources + [
            'active' => $active,
            'is_active_style' => $isActiveStyle,
            // The variation is applied as a Less variable, not as an import
            // path: the entry file ends with
            //   @import (optional) ".../styles/@{internal-style}.less";
            // so a compiler must pass it through the caller-supplied vars.
            'overrides' => $overrides,
            'compiled' => $compiled,
            'compile_contract' => [
                'note' => 'YOOtheme Pro 5 compiles Less in a browser web worker and only stores the resulting CSS. To reproduce it, run the installed worker.js against this import tree.',
                'platform' => 'wordpress',
                'worker' => 'wp-content/themes/yootheme/assets/admin/js/worker.js',
                'base_url' => admin_url('customize.php'),
                'commands' => ['css', 'vars', 'minify', 'rtl'],
                'css_input' => [
                    'style' => ['filename', 'filepath', 'imports', 'vars'],
                    'input' => 'custom_less',
                    'vars' => 'variable overrides, including @internal-style for the variation',
                ],
                'precedence' => 'render() merges {...callerVars, ...styleVars}: style vars win.',
            ],
            'etag' => $helper->etag($config, $compiled),
        ];
    }
}
