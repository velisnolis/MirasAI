<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Mirasai\Library\Sandbox\PathValidator;

/**
 * file/delete — Delete a file from the sandbox directory.
 */
class FileDeleteTool extends AbstractTool
{
    private PathValidator $pathValidator;

    public function __construct(?PathValidator $pathValidator = null)
    {
        parent::__construct();
        $this->pathValidator = $pathValidator ?? new PathValidator();
    }

    public function getName(): string
    {
        return 'file/delete';
    }

    public function getDescription(): string
    {
        return 'Delete a file from the sandbox directory (media/mirasai/sandbox/).';
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

        // For delete, we need the file to exist — use validateRead first to resolve,
        // then validateWrite to confirm it's in sandbox
        try {
            $resolved = $this->pathValidator->validateWrite($path);
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }

        if (!file_exists($resolved)) {
            return ['error' => 'File does not exist: ' . $path];
        }

        if (!is_file($resolved)) {
            return ['error' => 'Not a file (directory deletion not supported): ' . $path];
        }

        $deleted = @unlink($resolved);

        if (!$deleted) {
            return ['error' => 'Failed to delete file: ' . $path];
        }

        return [
            'deleted' => true,
            'path' => $resolved,
        ];
    }

    public function getPermissions(): array
    {
        return [
            'readonly' => false,
            'destructive' => true,
            'requires_elevation' => true,
            'idempotent' => true,
        ];
    }

    public function getAuditSummary(array $arguments): string
    {
        return json_encode([
            'path' => $arguments['path'] ?? '(unknown)',
        ], JSON_UNESCAPED_SLASHES);
    }
}
