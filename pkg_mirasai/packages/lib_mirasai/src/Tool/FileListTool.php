<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Mirasai\Library\Sandbox\PathValidator;

/**
 * file/list — List directory contents anywhere under ABSPATH.
 *
 * Default depth=1 (non-recursive), max depth=3, max entries=500.
 */
class FileListTool extends AbstractTool
{
    private const MAX_DEPTH = 3;
    private const MAX_ENTRIES = 500;

    private PathValidator $pathValidator;

    public function __construct(?PathValidator $pathValidator = null)
    {
        parent::__construct();
        $this->pathValidator = $pathValidator ?? new PathValidator();
    }

    public function getName(): string
    {
        return 'file/list';
    }

    public function getDescription(): string
    {
        return 'List directory contents anywhere under the Joomla root. Default depth=1 (non-recursive). Max depth=3, max 500 entries.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Directory path (absolute or relative to Joomla root)',
                ],
                'depth' => [
                    'type' => 'integer',
                    'description' => 'Recursion depth (default: 1, max: 3)',
                    'minimum' => 1,
                    'maximum' => self::MAX_DEPTH,
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function handle(array $arguments): array
    {
        $path = $arguments['path'] ?? '';
        $depth = min((int) ($arguments['depth'] ?? 1), self::MAX_DEPTH);

        if ($path === '') {
            return ['error' => 'Missing required parameter: path'];
        }

        try {
            $resolved = $this->pathValidator->validateRead($path);
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }

        if (!is_dir($resolved)) {
            return ['error' => 'Not a directory: ' . $path];
        }

        $entries = [];
        $truncated = false;
        $this->scan($resolved, $resolved, $depth, 1, $entries, $truncated);

        return [
            'path' => $resolved,
            'entries' => $entries,
            'entry_count' => \count($entries),
            'truncated' => $truncated,
            'depth' => $depth,
        ];
    }

    /**
     * @param list<array{name: string, type: string, size: int}> $entries
     */
    private function scan(
        string $basePath,
        string $currentPath,
        int $maxDepth,
        int $currentDepth,
        array &$entries,
        bool &$truncated,
    ): void {
        if (\count($entries) >= self::MAX_ENTRIES) {
            $truncated = true;

            return;
        }

        $items = @scandir($currentPath);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            if (\count($entries) >= self::MAX_ENTRIES) {
                $truncated = true;

                return;
            }

            $fullPath = $currentPath . '/' . $item;
            $relativePath = substr($fullPath, \strlen($basePath) + 1);
            $isDir = is_dir($fullPath);

            $entry = [
                'name' => $relativePath,
                'type' => $isDir ? 'directory' : 'file',
            ];

            if (!$isDir) {
                $entry['size'] = (int) @filesize($fullPath);
            }

            $entries[] = $entry;

            // Recurse if directory and within depth limit
            if ($isDir && $currentDepth < $maxDepth) {
                $this->scan($basePath, $fullPath, $maxDepth, $currentDepth + 1, $entries, $truncated);
            }
        }
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
