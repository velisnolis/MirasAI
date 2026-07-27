<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

/**
 * Creates a portable YOOtheme Style source inside a WordPress child theme.
 *
 * This deliberately does not activate the child theme or select/compile the
 * new style. Those operations change live theme_mods/CSS and belong to the
 * separate guarded style-update workflow.
 */
class TemplateStyleCreateTool extends AbstractTool
{
    private const MAX_LESS_BYTES = 1048576;

    public function getName(): string
    {
        return 'template/style-create';
    }

    public function getDescription(): string
    {
        return 'Creates a versionable YOOtheme Style Less source in a WordPress child theme. Requires if_match, dry_run first, and confirm_guarded_write for a real write. It never activates the child theme or applies/compiles the new style.';
    }

    public function getPermissions(): array
    {
        return [
            'risk_level' => self::RISK_GUARDED_WRITE,
            'idempotent' => true,
        ];
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'if_match' => ['type' => 'string'],
                'style_id' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'less_source' => ['type' => 'string'],
                'background' => ['type' => 'string'],
                'color' => ['type' => 'string'],
                'type' => ['type' => 'string'],
                'preview' => ['type' => 'string'],
                'variations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'name' => ['type' => 'string'],
                            'background' => ['type' => 'string'],
                            'color' => ['type' => 'string'],
                        ],
                        'required' => ['id'],
                    ],
                ],
                'child_theme_slug' => ['type' => 'string'],
                'child_theme_name' => ['type' => 'string'],
                'replace_existing' => ['type' => 'boolean'],
                'dry_run' => ['type' => 'boolean'],
                'confirm_guarded_write' => ['type' => 'boolean'],
            ],
            'required' => ['if_match', 'style_id', 'name', 'less_source'],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        $helper = new YoothemeStyleHelper();
        $status = $helper->status();

        if (!$status['installed'] || !$status['active']) {
            return ['error' => 'YOOtheme Pro is not the active WordPress parent theme.', 'code' => 'yootheme_inactive'];
        }

        $ifMatch = trim((string) ($arguments['if_match'] ?? ''));
        $dryRun = ($arguments['dry_run'] ?? null) === true;
        $confirmed = ($arguments['confirm_guarded_write'] ?? null) === true;

        if ($ifMatch === '') {
            return ['error' => 'if_match is required.', 'code' => 'missing_if_match'];
        }

        if (!$dryRun && !$confirmed) {
            return [
                'error' => 'This is a guarded write. Run dry_run=true first, then retry with confirm_guarded_write=true and a fresh if_match.',
                'code' => 'guarded_write_confirmation_required',
            ];
        }

        $currentEtag = $helper->etag($helper->loadConfig(), $helper->compiledState());
        if (!hash_equals($currentEtag, $ifMatch)) {
            return [
                'error' => 'Style etag mismatch. Re-read it and retry with the fresh etag.',
                'code' => 'stale_etag',
                'expected_etag' => $currentEtag,
                'provided_etag' => $ifMatch,
            ];
        }

        $styleId = trim((string) ($arguments['style_id'] ?? ''));
        $name = trim((string) ($arguments['name'] ?? ''));
        $lessSource = is_string($arguments['less_source'] ?? null)
            ? rtrim((string) $arguments['less_source']) . "\n"
            : '';
        $activeChild = get_stylesheet() !== get_template() ? get_stylesheet() : null;
        $childSlug = trim((string) ($arguments['child_theme_slug'] ?? ($activeChild ?? 'yootheme-child')));
        $childName = trim((string) ($arguments['child_theme_name'] ?? 'YOOtheme Child'));

        if (!preg_match('/^[A-Za-z0-9_-]+$/', $styleId)) {
            return ['error' => 'style_id is invalid.', 'code' => 'invalid_style_id'];
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $childSlug)) {
            return ['error' => 'child_theme_slug is invalid.', 'code' => 'invalid_child_theme_slug'];
        }
        if (!$this->validMetadataValue($name) || !$this->validMetadataValue($childName)) {
            return ['error' => 'name and child_theme_name must be non-empty single-line values.', 'code' => 'invalid_metadata'];
        }
        if ($lessSource === "\n" || strlen($lessSource) > self::MAX_LESS_BYTES) {
            return ['error' => 'less_source must be non-empty and no larger than 1 MiB.', 'code' => 'invalid_less_source'];
        }

        $meta = $this->buildMetadata($arguments, $name);
        if (isset($meta['error'])) {
            return $meta;
        }
        $styleContent = $meta['header'] . $lessSource;

        $themeRoot = function_exists('get_theme_root')
            ? rtrim((string) get_theme_root(), '/')
            : dirname(rtrim(get_template_directory(), '/'));
        $childDir = $themeRoot . '/' . $childSlug;
        $stylePath = $childDir . '/less/theme.' . $styleId . '.less';
        $styleCssPath = $childDir . '/style.css';
        $functionsPath = $childDir . '/functions.php';

        $conflict = $this->validateChildThemeTarget($childDir, $styleCssPath);
        if ($conflict !== null) {
            return $conflict;
        }

        $styleCss = "/*\nTheme Name: {$childName}\nTemplate: yootheme\nVersion: 1.0.0\n*/\n";
        $functions = "<?php\n\n/**\n * YOOtheme child theme scaffold created by MirasAI.\n */\n";
        $desired = [$stylePath => $styleContent];

        if (!is_file($styleCssPath)) {
            $desired[$styleCssPath] = $styleCss;
        }
        if (!is_file($functionsPath)) {
            $desired[$functionsPath] = $functions;
        }

        $replaceExisting = !empty($arguments['replace_existing']);
        if (is_file($stylePath)) {
            $existing = file_get_contents($stylePath);
            if (!is_string($existing)) {
                return ['error' => 'Unable to read the existing Style source.', 'code' => 'style_source_read_failed'];
            }
            if ($existing !== $styleContent && !$replaceExisting) {
                return [
                    'error' => 'The Style source already exists with different content. Set replace_existing=true only after reviewing the diff.',
                    'code' => 'style_exists',
                    'path' => $this->relativeToRoot($stylePath),
                    'existing_sha256' => hash('sha256', $existing),
                    'candidate_sha256' => hash('sha256', $styleContent),
                ];
            }
        }

        $changes = [];
        foreach ($desired as $path => $content) {
            $existing = is_file($path) ? file_get_contents($path) : null;
            if (!is_string($existing) || $existing !== $content) {
                $changes[$path] = $content;
            }
        }

        $response = [
            'dry_run' => $dryRun,
            'style_id' => $styleId,
            'child_theme' => [
                'slug' => $childSlug,
                'active' => $activeChild === $childSlug,
                'will_activate' => false,
            ],
            'style_file' => $this->relativeToRoot($stylePath),
            'candidate_sha256' => hash('sha256', $styleContent),
            'would_write' => array_map([$this, 'relativeToRoot'], array_keys($changes)),
            'would_replace_existing_style' => is_file($stylePath)
                && (string) file_get_contents($stylePath) !== $styleContent,
            'old_etag' => $currentEtag,
            'activation_required_for_runtime_visibility' => $activeChild !== $childSlug,
        ];

        if ($dryRun) {
            return $response + [
                'action' => 'preview',
                'snapshot_created' => false,
                'note' => 'Nothing was written or activated. Retry with confirm_guarded_write=true and the same fresh if_match.',
            ];
        }

        if ($changes === []) {
            return $response + [
                'action' => 'unchanged',
                'snapshot_created' => false,
                'written_files' => [],
            ];
        }

        // Recheck the Style state after validation and immediately before the
        // snapshot/write gate.
        $freshEtag = $helper->etag($helper->loadConfig(), $helper->compiledState());
        if (!hash_equals($freshEtag, $ifMatch)) {
            return [
                'error' => 'Style changed before the child-theme write. Re-read it and retry.',
                'code' => 'stale_etag',
                'expected_etag' => $freshEtag,
                'provided_etag' => $ifMatch,
            ];
        }

        $originals = [];
        foreach (array_keys($changes) as $path) {
            $originals[$path] = is_file($path) ? file_get_contents($path) : null;
            if ($originals[$path] !== null && !is_string($originals[$path])) {
                return ['error' => 'Unable to read a target before snapshot.', 'code' => 'snapshot_failed'];
            }
        }

        $snapshot = $this->createSnapshot($originals);
        if (isset($snapshot['error'])) {
            return $snapshot;
        }

        $written = [];
        try {
            foreach ($changes as $path => $content) {
                $this->atomicWrite($path, $content);
                $written[] = $path;
            }
        } catch (\Throwable $exception) {
            $rollback = $this->restoreOriginals($originals);

            return [
                'error' => 'Child-theme Style write failed and rollback was attempted: ' . $exception->getMessage(),
                'code' => 'style_create_failed',
                'snapshot_id' => $snapshot['snapshot_id'],
                'rollback' => $rollback,
            ];
        }

        if (function_exists('wp_clean_themes_cache')) {
            wp_clean_themes_cache();
        }

        $visible = false;
        if ($activeChild === $childSlug) {
            foreach ($helper->availableStyles() as $style) {
                if (($style['id'] ?? null) === $styleId && ($style['source'] ?? null) === 'child') {
                    $visible = true;
                    break;
                }
            }
        }

        return $response + [
            'action' => 'created',
            'snapshot_created' => true,
            'snapshot_id' => $snapshot['snapshot_id'],
            'written_files' => array_map([$this, 'relativeToRoot'], $written),
            'runtime_visible' => $visible,
            'note' => $visible
                ? 'The active child theme now exposes the Style to YOOtheme.'
                : 'The Style source is versionable on disk but remains inactive until the child theme is reviewed and activated.',
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{header: string}|array{error: string, code: string}
     */
    private function buildMetadata(array $arguments, string $name): array
    {
        $lines = ['/*', 'Name: ' . $name];

        foreach (['background', 'color', 'type', 'preview'] as $key) {
            if (!array_key_exists($key, $arguments)) {
                continue;
            }
            $value = trim((string) $arguments[$key]);
            if (!$this->validMetadataValue($value)) {
                return ['error' => "{$key} must be a non-empty single-line value.", 'code' => 'invalid_metadata'];
            }
            $lines[] = ucfirst($key) . ': ' . $value;
        }

        $seen = [];
        $variations = is_array($arguments['variations'] ?? null) ? $arguments['variations'] : [];
        foreach ($variations as $variation) {
            if (!is_array($variation)) {
                return ['error' => 'Every variation must be an object.', 'code' => 'invalid_variation'];
            }
            $id = trim((string) ($variation['id'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $id) || isset($seen[$id])) {
                return ['error' => 'Every variation id must be valid and unique.', 'code' => 'invalid_variation'];
            }
            $seen[$id] = true;
            $lines[] = '';
            $lines[] = 'Style: ' . $id;

            foreach (['name', 'background', 'color'] as $key) {
                if (!array_key_exists($key, $variation)) {
                    continue;
                }
                $value = trim((string) $variation[$key]);
                if (!$this->validMetadataValue($value)) {
                    return ['error' => "Variation {$key} must be a non-empty single-line value.", 'code' => 'invalid_variation'];
                }
                $lines[] = ucfirst($key) . ': ' . $value;
            }
        }

        $lines[] = '*/';
        $lines[] = '';

        return ['header' => implode("\n", $lines)];
    }

    private function validMetadataValue(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= 200
            && !str_contains($value, "\n")
            && !str_contains($value, "\r")
            && !str_contains($value, '*/');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function validateChildThemeTarget(string $childDir, string $styleCssPath): ?array
    {
        if (!is_dir($childDir)) {
            return null;
        }

        if (!is_file($styleCssPath)) {
            $entries = array_values(array_diff(scandir($childDir) ?: [], ['.', '..']));
            if ($entries !== []) {
                return [
                    'error' => 'The target child-theme directory exists but has no style.css. Refusing to adopt it.',
                    'code' => 'child_theme_conflict',
                ];
            }

            return null;
        }

        $css = file_get_contents($styleCssPath);
        if (!is_string($css) || preg_match('/^[ \t*#@]*Template:\s*yootheme\s*$/mi', $css) !== 1) {
            return [
                'error' => 'The target theme is not declared as a YOOtheme child theme.',
                'code' => 'child_theme_conflict',
            ];
        }

        return null;
    }

    /**
     * @param array<string, string|null> $originals
     * @return array<string, mixed>
     */
    private function createSnapshot(array $originals): array
    {
        $siteRoot = defined('ABSPATH') ? rtrim((string) ABSPATH, '/') : get_theme_root();
        $base = dirname($siteRoot) . '/mirasai-backups/style-create';
        $snapshotId = sprintf('wordpress-style-create-%s-%s', gmdate('Ymd-His'), bin2hex(random_bytes(4)));
        $dir = $base . '/' . $snapshotId;

        if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) {
            return ['error' => 'Unable to create the private Style-create backup directory.', 'code' => 'snapshot_failed'];
        }
        @chmod($base, 0700);
        if (!mkdir($dir, 0700) || !is_dir($dir)) {
            return ['error' => 'Unable to create the Style-create snapshot.', 'code' => 'snapshot_failed'];
        }

        $manifest = ['snapshot_id' => $snapshotId, 'created_at' => gmdate('c'), 'files' => []];
        $index = 0;
        foreach ($originals as $path => $content) {
            $entry = [
                'path' => $this->relativeToRoot($path),
                'present' => is_string($content),
                'sha256' => is_string($content) ? hash('sha256', $content) : null,
            ];
            if (is_string($content)) {
                $backupName = sprintf('original-%02d-%s', $index, basename($path));
                if (file_put_contents($dir . '/' . $backupName, $content, LOCK_EX) !== strlen($content)) {
                    return ['error' => 'Unable to snapshot an existing child-theme file.', 'code' => 'snapshot_failed'];
                }
                @chmod($dir . '/' . $backupName, 0600);
                $entry['backup'] = $backupName;
            }
            $manifest['files'][] = $entry;
            $index++;
        }

        $encoded = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)
            || file_put_contents($dir . '/manifest.json', $encoded, LOCK_EX) !== strlen($encoded)) {
            return ['error' => 'Unable to write the Style-create snapshot manifest.', 'code' => 'snapshot_failed'];
        }
        @chmod($dir . '/manifest.json', 0600);

        return $manifest;
    }

    private function atomicWrite(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create {$dir}.");
        }

        $temporary = tempnam($dir, '.mirasai-style-create-');
        if (!is_string($temporary)) {
            throw new \RuntimeException("Unable to stage {$path}.");
        }

        try {
            if (file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)) {
                throw new \RuntimeException("Incomplete write for {$path}.");
            }
            @chmod($temporary, 0644);
            if (!@rename($temporary, $path)) {
                throw new \RuntimeException("Unable to replace {$path} atomically.");
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * @param array<string, string|null> $originals
     * @return array{restored: bool, failures: list<string>}
     */
    private function restoreOriginals(array $originals): array
    {
        $failures = [];

        foreach ($originals as $path => $content) {
            try {
                if ($content === null) {
                    if (is_file($path) && !@unlink($path)) {
                        throw new \RuntimeException('unlink failed');
                    }
                } else {
                    $this->atomicWrite($path, $content);
                }
            } catch (\Throwable) {
                $failures[] = $this->relativeToRoot($path);
            }
        }

        return ['restored' => $failures === [], 'failures' => $failures];
    }

    private function relativeToRoot(string $path): string
    {
        $root = defined('ABSPATH') ? rtrim((string) ABSPATH, '/') . '/' : '';

        return $root !== '' && str_starts_with($path, $root)
            ? ltrim(substr($path, strlen($root)), '/')
            : $path;
    }
}
