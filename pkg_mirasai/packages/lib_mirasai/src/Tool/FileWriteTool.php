<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Mirasai\Library\Sandbox\PathValidator;

/**
 * file/write — Write file content to the sandbox directory.
 *
 * PHP files are syntax-validated via php -l before writing.
 */
class FileWriteTool extends AbstractTool
{
    private PathValidator $pathValidator;

    public function __construct(?PathValidator $pathValidator = null)
    {
        parent::__construct();
        $this->pathValidator = $pathValidator ?? new PathValidator();
    }

    public function getName(): string
    {
        return 'file/write';
    }

    public function getDescription(): string
    {
        return 'Write content to a file in the sandbox directory (media/mirasai/sandbox/). PHP files are syntax-validated before writing. Supports overwrite and append modes, and UTF-8 or base64 encoding.';
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
                'content' => [
                    'type' => 'string',
                    'description' => 'File content to write',
                ],
                'encoding' => [
                    'type' => 'string',
                    'enum' => ['utf-8', 'base64'],
                    'description' => 'Content encoding (default: utf-8)',
                ],
                'mode' => [
                    'type' => 'string',
                    'enum' => ['overwrite', 'append'],
                    'description' => 'Write mode (default: overwrite)',
                ],
            ],
            'required' => ['path', 'content'],
        ];
    }

    public function handle(array $arguments): array
    {
        $path = $arguments['path'] ?? '';
        $content = $arguments['content'] ?? '';
        $encoding = $arguments['encoding'] ?? 'utf-8';
        $mode = $arguments['mode'] ?? 'overwrite';

        if ($path === '') {
            return ['error' => 'Missing required parameter: path'];
        }

        // Decode content if base64
        if ($encoding === 'base64') {
            $decoded = base64_decode($content, true);

            if ($decoded === false) {
                return ['error' => 'Invalid base64 content'];
            }

            $content = $decoded;
        }

        // Validate write path (sandbox only)
        try {
            $resolved = $this->pathValidator->validateWrite($path);
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }

        // PHP syntax validation
        $warnings = [];

        if ($this->pathValidator->isPhpFile($resolved)) {
            $fullContent = $content;

            // For append mode, we need to validate the combined content
            if ($mode === 'append' && file_exists($resolved)) {
                $existing = file_get_contents($resolved);
                $fullContent = ($existing !== false ? $existing : '') . $content;
            }

            $lint = $this->pathValidator->validatePhpSyntax($fullContent);

            if (!$lint['available']) {
                $warnings[] = 'PHP syntax validation unavailable (shell_exec disabled). File written without validation.';
            } elseif (!$lint['valid']) {
                return [
                    'error' => 'PHP syntax error — file NOT written',
                    'lint_error' => $lint['error'],
                ];
            }
        }

        // Ensure sandbox directory exists
        $dir = \dirname($resolved);

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Write the file
        $flags = ($mode === 'append') ? FILE_APPEND : 0;
        $result = file_put_contents($resolved, $content, $flags);

        if ($result === false) {
            return ['error' => 'Failed to write file: ' . $path];
        }

        $response = [
            'written' => true,
            'path' => $resolved,
            'size' => $result,
            'mode' => $mode,
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

    public function getAuditSummary(array $arguments): string
    {
        return json_encode([
            'path' => $arguments['path'] ?? '(unknown)',
        ], JSON_UNESCAPED_SLASHES);
    }
}
