<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class FileReadTool extends AbstractTool
{
    private const MAX_BYTES = 1024 * 1024;

    private FilePathValidator $pathValidator;

    public function __construct(?FilePathValidator $pathValidator = null)
    {
        $this->pathValidator = $pathValidator ?? new FilePathValidator();
    }

    public function getName(): string
    {
        return 'file/read';
    }

    public function getDescription(): string
    {
        return 'Reads non-sensitive file content under the WordPress root. Blocks wp-config.php variants, .env files, private keys, SQL dumps, and common secret-bearing files. Max size: 1MB.';
    }

    /**
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['path'],
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'File path, absolute or relative to the WordPress root.',
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
        $path = isset($arguments['path']) && is_string($arguments['path']) ? $arguments['path'] : '';

        try {
            $resolved = $this->pathValidator->validateReadPath($path);
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
                'code' => 'path_denied',
            ];
        }

        if (!is_file($resolved)) {
            return [
                'error' => 'Not a file: ' . $path,
                'code' => 'not_file',
            ];
        }

        if (!is_readable($resolved)) {
            return [
                'error' => 'File is not readable: ' . $path,
                'code' => 'not_readable',
            ];
        }

        $size = (int) filesize($resolved);
        if ($size > self::MAX_BYTES) {
            return [
                'error' => 'File exceeds 1MB read limit.',
                'code' => 'file_too_large',
                'path' => $resolved,
                'size' => $size,
            ];
        }

        $content = file_get_contents($resolved);
        if ($content === false) {
            return [
                'error' => 'Failed to read file: ' . $path,
                'code' => 'read_failed',
            ];
        }

        $binary = !mb_check_encoding($content, 'UTF-8');

        return [
            'path' => $resolved,
            'size' => strlen($content),
            'content' => $binary ? base64_encode($content) : $content,
            'encoding' => $binary ? 'base64' : 'utf-8',
            'binary' => $binary,
        ];
    }
}
