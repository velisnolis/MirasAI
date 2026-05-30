<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class FilePathValidator
{
    private const SENSITIVE_BASENAMES = [
        '.env',
        '.htpasswd',
        '.my.cnf',
        'auth.json',
        'authorized_keys',
        'debug.log',
        'id_dsa',
        'id_ecdsa',
        'id_ed25519',
        'id_rsa',
        'known_hosts',
        'wp-config.php',
    ];

    private const SENSITIVE_EXTENSIONS = [
        '.db',
        '.key',
        '.pem',
        '.p12',
        '.pfx',
        '.sqlite',
        '.sqlite3',
    ];

    private const SENSITIVE_ARCHIVE_SUFFIXES = [
        '.gz',
        '.bz2',
        '.xz',
        '.zip',
        '.7z',
        '.tar',
        '.tgz',
    ];

    public function validateReadPath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            throw new \InvalidArgumentException('Path is required.');
        }

        $base = $this->normalizeBasePath();
        $candidate = $path;

        if (!$this->isAbsolutePath($candidate)) {
            $candidate = $base . '/' . ltrim($candidate, '/');
        }

        $resolved = realpath($candidate);

        if ($resolved === false) {
            throw new \InvalidArgumentException('Path does not exist: ' . $path);
        }

        $normalized = str_replace('\\', '/', $resolved);

        if ($normalized !== $base && !str_starts_with($normalized, $base . '/')) {
            throw new \InvalidArgumentException('Path must stay inside the WordPress root.');
        }

        if ($this->isSensitivePath($normalized)) {
            throw new \InvalidArgumentException('Read access denied for sensitive path: ' . $path);
        }

        return $normalized;
    }

    public function isSensitivePath(string $resolved): bool
    {
        $normalized = str_replace('\\', '/', $resolved);
        $segments = array_map(
            'strtolower',
            array_values(array_filter(explode('/', $normalized), static fn(string $segment): bool => $segment !== ''))
        );
        $basename = strtolower(basename($normalized));

        if (in_array($basename, self::SENSITIVE_BASENAMES, true)) {
            return true;
        }

        if (str_starts_with($basename, '.env.')) {
            return true;
        }

        if ($this->isWpConfigBackup($basename)) {
            return true;
        }

        if ($this->isDatabaseDump($basename)) {
            return true;
        }

        foreach (self::SENSITIVE_EXTENSIONS as $extension) {
            if (str_ends_with($basename, $extension)) {
                return true;
            }
        }

        return in_array('.ssh', $segments, true);
    }

    private function normalizeBasePath(): string
    {
        $base = realpath(ABSPATH);

        if ($base === false) {
            throw new \RuntimeException('Could not resolve ABSPATH.');
        }

        return rtrim(str_replace('\\', '/', $base), '/');
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }

    private function isWpConfigBackup(string $basename): bool
    {
        return str_starts_with($basename, 'wp-config') && str_contains($basename, '.php');
    }

    private function isDatabaseDump(string $basename): bool
    {
        if (str_ends_with($basename, '.sql')) {
            return true;
        }

        foreach (self::SENSITIVE_ARCHIVE_SUFFIXES as $suffix) {
            if (str_ends_with($basename, '.sql' . $suffix)) {
                return true;
            }
        }

        return false;
    }
}
