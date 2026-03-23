<?php

declare(strict_types=1);

namespace Mirasai\Library\Sandbox;

/**
 * Validates file paths for sandbox security.
 *
 * Read operations: allowed anywhere under ABSPATH (JPATH_ROOT).
 * Write operations: allowed only under the sandbox directory.
 *
 * Uses realpath() canonicalization to prevent directory traversal.
 */
class PathValidator
{
    private string $sandboxDir;
    private string $abspath;

    public function __construct(?string $sandboxDir = null, ?string $abspath = null)
    {
        $this->sandboxDir = $sandboxDir ?? (JPATH_ROOT . '/media/mirasai/sandbox');
        $this->abspath = $abspath ?? JPATH_ROOT;
    }

    /**
     * Validate a path for reading.
     *
     * @throws \InvalidArgumentException if path is outside ABSPATH.
     */
    public function validateRead(string $path): string
    {
        $resolved = $this->resolve($path);

        if (!str_starts_with($resolved, $this->abspath . '/') && $resolved !== $this->abspath) {
            throw new \InvalidArgumentException(
                'Path is outside the Joomla root directory. Read access denied.'
            );
        }

        return $resolved;
    }

    /**
     * Validate a path for writing (create, edit, delete).
     *
     * @throws \InvalidArgumentException if path is outside sandbox dir.
     */
    public function validateWrite(string $path): string
    {
        $resolved = $this->resolveForWrite($path);

        $sandboxReal = realpath($this->sandboxDir);

        if ($sandboxReal === false) {
            throw new \InvalidArgumentException(
                'Sandbox directory does not exist: ' . $this->sandboxDir
            );
        }

        if (!str_starts_with($resolved, $sandboxReal . '/') && $resolved !== $sandboxReal) {
            throw new \InvalidArgumentException(
                'Write access is restricted to the sandbox directory: ' . $this->sandboxDir
            );
        }

        return $resolved;
    }

    /**
     * Check if a file is a PHP file (by extension).
     */
    public function isPhpFile(string $path): bool
    {
        return str_ends_with(strtolower($path), '.php');
    }

    /**
     * Validate PHP syntax using php -l.
     *
     * @param string $content The PHP code to validate.
     * @return array{valid: bool, error: string|null, available: bool}
     */
    public function validatePhpSyntax(string $content): array
    {
        if (!\function_exists('shell_exec')) {
            return ['valid' => true, 'error' => null, 'available' => false];
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'mirasai_lint_');

        if ($tmpFile === false) {
            return ['valid' => true, 'error' => null, 'available' => false];
        }

        try {
            file_put_contents($tmpFile, $content);
            $output = @shell_exec('php -l ' . escapeshellarg($tmpFile) . ' 2>&1');

            if ($output === null) {
                return ['valid' => true, 'error' => null, 'available' => false];
            }

            $valid = str_contains($output, 'No syntax errors');

            return [
                'valid' => $valid,
                'error' => $valid ? null : trim($output),
                'available' => true,
            ];
        } finally {
            @unlink($tmpFile);
        }
    }

    public function getSandboxDir(): string
    {
        return $this->sandboxDir;
    }

    /**
     * Resolve a path for reading (file must exist).
     */
    private function resolve(string $path): string
    {
        // If path is relative, resolve from ABSPATH
        if (!str_starts_with($path, '/')) {
            $path = $this->abspath . '/' . $path;
        }

        $resolved = realpath($path);

        if ($resolved === false) {
            throw new \InvalidArgumentException(
                'Path does not exist: ' . $path
            );
        }

        return $resolved;
    }

    /**
     * Resolve a path for writing (file may not exist yet).
     *
     * Resolves the parent directory (which must exist) and appends the filename.
     */
    private function resolveForWrite(string $path): string
    {
        // If path is relative, resolve from sandbox dir
        if (!str_starts_with($path, '/')) {
            $path = $this->sandboxDir . '/' . $path;
        }

        $dir = \dirname($path);
        $basename = basename($path);

        $resolvedDir = realpath($dir);

        if ($resolvedDir === false) {
            throw new \InvalidArgumentException(
                'Parent directory does not exist: ' . $dir
            );
        }

        return $resolvedDir . '/' . $basename;
    }
}
