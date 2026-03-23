<?php

declare(strict_types=1);

namespace Mirasai\Library\Sandbox;

/**
 * Crash-recovery sandbox loader.
 *
 * Three-state machine:
 *   CLEAN → LOADING → OK      (normal boot)
 *   LOADING → CRASHED          (if .loading found on next boot)
 *   CRASHED → SAFE_MODE        (skip sandbox loading)
 *
 * The .loading and .crashed marker files live in the sandbox directory.
 * In safe mode the MCP bridge still works (tools are available) but
 * no sandbox PHP files are auto-loaded.
 */
class SandboxLoader
{
    public const STATE_OK = 'ok';
    public const STATE_LOADING = 'loading';
    public const STATE_CRASHED = 'crashed';
    public const STATE_SAFE_MODE = 'safe_mode';

    private string $sandboxDir;
    private string $loadingMarker;
    private string $crashedMarker;

    /** @var string[] Files that were loaded this boot. */
    private array $loadedFiles = [];

    /** @var string[] Files that caused a crash (from .crashed content). */
    private array $crashedFiles = [];

    private string $state = self::STATE_OK;

    public function __construct(?string $sandboxDir = null)
    {
        $this->sandboxDir = $sandboxDir ?? (JPATH_ROOT . '/media/mirasai/sandbox');
        $this->loadingMarker = $this->sandboxDir . '/.loading';
        $this->crashedMarker = $this->sandboxDir . '/.crashed';
    }

    /**
     * Run the sandbox boot sequence.
     *
     * Called early in plg_system_mirasai::onAfterInitialise().
     */
    public function boot(): void
    {
        // Ensure the sandbox directory exists
        if (!is_dir($this->sandboxDir)) {
            @mkdir($this->sandboxDir, 0755, true);

            if (!is_dir($this->sandboxDir)) {
                // Cannot create sandbox dir — nothing to do
                return;
            }
        }

        // Step 1: Check for .loading marker from a previous failed boot
        if (file_exists($this->loadingMarker)) {
            // Previous boot didn't finish — mark as crashed
            $content = @file_get_contents($this->loadingMarker) ?: '';
            @file_put_contents($this->crashedMarker, $content);
            @unlink($this->loadingMarker);
        }

        // Step 2: Check for .crashed marker → enter safe mode
        if (file_exists($this->crashedMarker)) {
            $this->state = self::STATE_SAFE_MODE;
            $content = @file_get_contents($this->crashedMarker) ?: '';
            $this->crashedFiles = array_filter(array_map('trim', explode("\n", $content)));

            return;
        }

        // Step 3: Normal loading
        $files = glob($this->sandboxDir . '/*.php');

        if ($files === false || $files === []) {
            $this->state = self::STATE_OK;

            return;
        }

        // Filter out disabled files
        $activeFiles = array_filter($files, static function (string $file): bool {
            return !str_ends_with($file, '.php.disabled');
        });

        if ($activeFiles === []) {
            $this->state = self::STATE_OK;

            return;
        }

        // Write .loading marker with the list of files about to be loaded
        $fileNames = array_map('basename', $activeFiles);
        @file_put_contents($this->loadingMarker, implode("\n", $fileNames));

        $this->state = self::STATE_LOADING;

        // Load each file
        foreach ($activeFiles as $file) {
            try {
                include_once $file;
                $this->loadedFiles[] = basename($file);
            } catch (\Throwable $e) {
                // Individual file error — continue loading others
                // The file will be listed in loadedFiles only if it succeeded
            }
        }

        // Loading complete — remove marker
        @unlink($this->loadingMarker);
        $this->state = self::STATE_OK;
    }

    /**
     * Clear safe mode by removing the .crashed marker.
     */
    public function clearSafeMode(): void
    {
        if (file_exists($this->crashedMarker)) {
            @unlink($this->crashedMarker);
        }

        $this->state = self::STATE_OK;
        $this->crashedFiles = [];
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function isSafeMode(): bool
    {
        return $this->state === self::STATE_SAFE_MODE;
    }

    public function getSandboxDir(): string
    {
        return $this->sandboxDir;
    }

    /**
     * @return string[] Filenames loaded this boot (relative to sandbox dir).
     */
    public function getLoadedFiles(): array
    {
        return $this->loadedFiles;
    }

    /**
     * @return string[] Filenames that caused the crash.
     */
    public function getCrashedFiles(): array
    {
        return $this->crashedFiles;
    }

    /**
     * Check if php -l (lint) is available.
     */
    public function isPhpLintAvailable(): bool
    {
        if (!\function_exists('shell_exec')) {
            return false;
        }

        $output = @shell_exec('php -l -r "echo 1;" 2>&1');

        return $output !== null && str_contains($output, 'No syntax errors');
    }

    /**
     * List all PHP files in the sandbox dir.
     *
     * @return string[] Bare filenames.
     */
    public function listSandboxFiles(): array
    {
        $files = glob($this->sandboxDir . '/*.php');

        if ($files === false) {
            return [];
        }

        return array_map('basename', $files);
    }
}
