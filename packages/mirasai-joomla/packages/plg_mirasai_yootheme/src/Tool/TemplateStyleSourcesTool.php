<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Joomla\CMS\Uri\Uri;
use Mirasai\Library\Tool\AbstractTool;

class TemplateStyleSourcesTool extends AbstractTool
{
    private const DEFAULT_MAX_BYTES = 4194304;

    public function getName(): string
    {
        return 'template/style-sources';
    }

    public function getDescription(): string
    {
        return 'Returns the Joomla YOOtheme import tree so a client can compile LESS outside the browser. This host has no compiler. The local MirasAI router consumes this via mirasai/style-preview. mcp2cli against this endpoint cannot compile the tree by itself.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'style_id' => [
                    'type' => 'string',
                    'description' => 'YOOtheme library style to resolve, for example "nioh-studio". Defaults to the active style.',
                ],
                'apply_active_overrides' => [
                    'type' => 'boolean',
                    'description' => 'Apply active variation, Less overrides, and custom Less to a different style. Defaults to false.',
                ],
                'include_imports' => [
                    'type' => 'boolean',
                    'description' => 'Include the import contents. Defaults to true.',
                ],
                'max_bytes' => [
                    'type' => 'integer',
                    'description' => 'Refuse import trees larger than this. Defaults to 4 MiB.',
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
        $helper = new YoothemeStyleHelper($this->db);
        $status = $helper->status();

        if (!$status['installed'] || !$status['active'] || !is_int($status['template_style_id'])) {
            return [
                'error' => 'YOOtheme Pro is not the active Joomla site template.',
                'code' => 'yootheme_inactive',
            ];
        }

        $templateStyleId = $status['template_style_id'];
        $config = $helper->loadConfig($templateStyleId);
        $active = $helper->activeStyle($config);
        $styleId = $arguments['style_id'] ?? null;

        if ($styleId !== null && !is_string($styleId)) {
            return ['error' => 'style_id must be a string.', 'code' => 'invalid_style_id'];
        }

        $styleId = is_string($styleId) && $styleId !== ''
            ? $styleId
            : $active['style_id'];

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

        $compiled = $helper->compiledState($templateStyleId);
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
            'template_style_id' => $templateStyleId,
            'overrides' => $overrides,
            'compiled' => $compiled,
            'compile_contract' => [
                'note' => 'YOOtheme Pro 5 compiles Less in a browser worker and only stores the resulting CSS.',
                'platform' => 'joomla',
                'worker' => 'templates/yootheme/assets/admin/js/worker.js',
                'base_url' => rtrim(Uri::root(), '/') . '/administrator/index.php',
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
