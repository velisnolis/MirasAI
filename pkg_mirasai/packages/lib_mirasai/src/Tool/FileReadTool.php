<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Mirasai\Library\Sandbox\PathValidator;

/**
 * file/read — Read non-sensitive file content from anywhere under ABSPATH.
 */
class FileReadTool extends AbstractTool
{
    private const SENSITIVE_BASENAMES = [
        '.env',
        '.htpasswd',
        '.my.cnf',
        'auth.json',
        'authorized_keys',
        'configuration.php',
        'id_dsa',
        'id_ecdsa',
        'id_ed25519',
        'id_rsa',
        'known_hosts',
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
        return 'Reads non-sensitive file content anywhere under the Joomla root directory. Returns the raw file content as text. '
            . 'Blocks common secret-bearing files such as Joomla configuration, .env files, private keys, and certificate bundles. '
            . 'Useful for inspecting template overrides, language .ini files, or plugin code. '
            . 'Path is relative to Joomla root (e.g. "language/en-GB/en-GB.ini", "templates/yootheme/config.php").';
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

        if ($this->isSensitivePath($resolved)) {
            return ['error' => 'Read access denied for sensitive file: ' . $path];
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

    private function isSensitivePath(string $resolved): bool
    {
        $normalized = str_replace('\\', '/', $resolved);
        $segments = array_map(
            'strtolower',
            array_values(array_filter(explode('/', $normalized), static fn (string $segment): bool => $segment !== ''))
        );
        $basename = strtolower(basename($normalized));

        if (in_array($basename, self::SENSITIVE_BASENAMES, true)) {
            return true;
        }

        if (str_starts_with($basename, '.env.')) {
            return true;
        }

        if ($this->isConfigurationBackup($basename)) {
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

    private function isConfigurationBackup(string $basename): bool
    {
        return str_starts_with($basename, 'configuration') && str_contains($basename, '.php');
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
