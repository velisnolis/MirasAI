<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

/**
 * Locates and loads an installed YOOtheme element definition.
 *
 * Extracted from TemplateElementSchemaTool so the write tools can consult the
 * same definitions when validating prop values. Reading the element registry
 * is not a schema-tool concern; both readers and writers need it.
 */
final class YoothemeElementDefinitionLoader
{
    /**
     * @return array<string, mixed>|array{error: string, code: string}
     */
    public static function load(string $type): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $type)) {
            return [
                'error' => 'type may only contain letters, numbers, underscores, and hyphens.',
                'code' => 'invalid_type',
            ];
        }

        $root = self::root();

        if ($root === null) {
            return [
                'error' => 'Installed YOOtheme Builder elements directory was not found.',
                'code' => 'yootheme_elements_root_missing',
            ];
        }

        $file = $root . '/' . $type . '/element.php';

        if (!is_file($file)) {
            return [
                'error' => "Element type {$type} was not found in the installed YOOtheme Builder registry.",
                'code' => 'element_schema_not_found',
            ];
        }

        return self::loadFile($file);
    }

    public static function root(): ?string
    {
        $siteRoot = defined('JPATH_SITE') ? JPATH_SITE : (defined('JPATH_ROOT') ? JPATH_ROOT : '');

        if (!is_string($siteRoot) || $siteRoot === '') {
            return null;
        }

        $candidates = [
            $siteRoot . '/templates/yootheme/packages/builder/elements',
            $siteRoot . '/media/templates/site/yootheme/packages/builder/elements',
        ];

        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                return rtrim($candidate, '/');
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|array{error: string, code: string}
     */
    public static function loadFile(string $file): array
    {
        try {
            $definition = include $file;
        } catch (\Throwable $exception) {
            return [
                'error' => 'Unable to load YOOtheme element definition: ' . $exception->getMessage(),
                'code' => 'element_schema_load_failed',
            ];
        }

        if (!is_array($definition)) {
            return [
                'error' => 'YOOtheme element definition did not return an array.',
                'code' => 'element_schema_invalid',
            ];
        }

        return $definition;
    }
}
