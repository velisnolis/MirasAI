<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Mirasai\Library\Sandbox\PathValidator;

/**
 * file/edit — String replacement in sandbox files.
 *
 * Similar to sed — finds old_string and replaces with new_string.
 * By default requires exactly one match. Use replace_all for multiple.
 * PHP files get post-edit syntax validation.
 */
class FileEditTool extends AbstractTool
{
    private PathValidator $pathValidator;

    public function __construct(?PathValidator $pathValidator = null)
    {
        parent::__construct();
        $this->pathValidator = $pathValidator ?? new PathValidator();
    }

    public function getName(): string
    {
        return 'file/edit';
    }

    public function getDescription(): string
    {
        return 'Replace a string in a file within the sandbox directory. By default, old_string must appear exactly once. Set replace_all=true for multiple replacements. PHP files are syntax-validated after editing.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'File path (absolute or relative to sandbox dir)',
                ],
                'old_string' => [
                    'type' => 'string',
                    'description' => 'The string to find and replace',
                ],
                'new_string' => [
                    'type' => 'string',
                    'description' => 'The replacement string',
                ],
                'replace_all' => [
                    'type' => 'boolean',
                    'description' => 'Replace all occurrences (default: false)',
                ],
            ],
            'required' => ['path', 'old_string', 'new_string'],
        ];
    }

    public function handle(array $arguments): array
    {
        $path = $arguments['path'] ?? '';
        $oldString = $arguments['old_string'] ?? '';
        $newString = $arguments['new_string'] ?? '';
        $replaceAll = (bool) ($arguments['replace_all'] ?? false);

        if ($path === '') {
            return ['error' => 'Missing required parameter: path'];
        }

        if ($oldString === '') {
            return ['error' => 'Missing required parameter: old_string'];
        }

        // Validate write path (sandbox only)
        try {
            $resolved = $this->pathValidator->validateWrite($path);
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }

        if (!file_exists($resolved)) {
            return ['error' => 'File does not exist: ' . $path];
        }

        $content = file_get_contents($resolved);

        if ($content === false) {
            return ['error' => 'Failed to read file: ' . $path];
        }

        // Count occurrences
        $count = substr_count($content, $oldString);

        if ($count === 0) {
            return ['error' => 'old_string not found in file'];
        }

        if ($count > 1 && !$replaceAll) {
            return [
                'error' => "old_string found {$count} times. Set replace_all=true to replace all, or provide a more specific old_string.",
                'match_count' => $count,
            ];
        }

        // Perform replacement
        if ($replaceAll) {
            $newContent = str_replace($oldString, $newString, $content);
        } else {
            // Replace only the first occurrence
            $pos = strpos($content, $oldString);
            $newContent = substr_replace($content, $newString, $pos, strlen($oldString));
        }

        // PHP syntax validation after edit
        $warnings = [];

        if ($this->pathValidator->isPhpFile($resolved)) {
            $lint = $this->pathValidator->validatePhpSyntax($newContent);

            if (!$lint['available']) {
                $warnings[] = 'PHP syntax validation unavailable (shell_exec disabled). File edited without validation.';
            } elseif (!$lint['valid']) {
                return [
                    'error' => 'PHP syntax error after replacement — file NOT modified',
                    'lint_error' => $lint['error'],
                ];
            }
        }

        // Write the modified content
        $result = file_put_contents($resolved, $newContent);

        if ($result === false) {
            return ['error' => 'Failed to write modified file: ' . $path];
        }

        $response = [
            'edited' => true,
            'path' => $resolved,
            'replacements' => $replaceAll ? $count : 1,
            'size' => $result,
        ];

        if ($warnings !== []) {
            $response['warnings'] = $warnings;
        }

        return $response;
    }

    public function getPermissions(): array
    {
        return [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ];
    }
}
