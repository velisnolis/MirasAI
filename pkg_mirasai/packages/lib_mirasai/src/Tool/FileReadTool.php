<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Mirasai\Library\Sandbox\PathValidator;

/**
 * file/read — Read file content from anywhere under ABSPATH.
 */
class FileReadTool extends AbstractTool
{
    private PathValidator $pathValidator;

    public function __construct(?PathValidator $pathValidator = null)
    {
        parent::__construct();
        $this->pathValidator = $pathValidator ?? new PathValidator();
    }

    public function getName(): string
    {
        return 'file/read';
    }

    public function getDescription(): string
    {
        return 'Read the content of a file. Accessible anywhere under the Joomla root directory.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'File path (absolute or relative to Joomla root)',
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function handle(array $arguments): array
    {
        $path = $arguments['path'] ?? '';

        if ($path === '') {
            return ['error' => 'Missing required parameter: path'];
        }

        try {
            $resolved = $this->pathValidator->validateRead($path);
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }

        if (!is_file($resolved)) {
            return ['error' => 'Not a file: ' . $path];
        }

        if (!is_readable($resolved)) {
            return ['error' => 'File is not readable: ' . $path];
        }

        $content = file_get_contents($resolved);

        if ($content === false) {
            return ['error' => 'Failed to read file: ' . $path];
        }

        $size = strlen($content);
        $isBinary = !mb_check_encoding($content, 'UTF-8');

        if ($isBinary) {
            return [
                'path' => $resolved,
                'size' => $size,
                'content' => base64_encode($content),
                'encoding' => 'base64',
                'binary' => true,
            ];
        }

        return [
            'path' => $resolved,
            'size' => $size,
            'content' => $content,
            'encoding' => 'utf-8',
            'binary' => false,
        ];
    }

    public function getPermissions(): array
    {
        return [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ];
    }
}
