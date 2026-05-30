<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class FileListTool extends AbstractTool
{
    private const MAX_DEPTH = 3;
    private const MAX_ENTRIES = 500;

    private FilePathValidator $pathValidator;

    public function __construct(?FilePathValidator $pathValidator = null)
    {
        $this->pathValidator = $pathValidator ?? new FilePathValidator();
    }

    public function getName(): string
    {
        return 'file/list';
    }

    public function getDescription(): string
    {
        return 'Lists directory contents under the WordPress root. Sensitive paths are skipped. Default depth=1, max depth=3, max 500 entries.';
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
                    'description' => 'Directory path, absolute or relative to the WordPress root.',
                ],
                'depth' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_DEPTH,
                    'description' => 'Recursion depth. Default 1, max 3.',
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
        $depth = isset($arguments['depth']) ? max(1, min(self::MAX_DEPTH, (int) $arguments['depth'])) : 1;

        try {
            $resolved = $this->pathValidator->validateReadPath($path);
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
                'code' => 'path_denied',
            ];
        }

        if (!is_dir($resolved)) {
            return [
                'error' => 'Not a directory: ' . $path,
                'code' => 'not_directory',
            ];
        }

        $entries = [];
        $truncated = false;
        $this->scan($resolved, $resolved, $depth, 1, $entries, $truncated);

        return [
            'path' => $resolved,
            'entries' => $entries,
            'entry_count' => count($entries),
            'truncated' => $truncated,
            'depth' => $depth,
        ];
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function scan(string $basePath, string $currentPath, int $maxDepth, int $currentDepth, array &$entries, bool &$truncated): void
    {
        if (count($entries) >= self::MAX_ENTRIES) {
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

            $fullPath = $currentPath . '/' . $item;
            $normalized = str_replace('\\', '/', $fullPath);

            if ($this->pathValidator->isSensitivePath($normalized)) {
                continue;
            }

            if (count($entries) >= self::MAX_ENTRIES) {
                $truncated = true;
                return;
            }

            $relativePath = substr($normalized, strlen($basePath) + 1);
            $isDir = is_dir($normalized);
            $entry = [
                'name' => $relativePath,
                'type' => $isDir ? 'directory' : 'file',
            ];

            if (!$isDir) {
                $entry['size'] = (int) @filesize($normalized);
            }

            $entries[] = $entry;

            if ($isDir && $currentDepth < $maxDepth) {
                $this->scan($basePath, $normalized, $maxDepth, $currentDepth + 1, $entries, $truncated);
            }
        }
    }
}
