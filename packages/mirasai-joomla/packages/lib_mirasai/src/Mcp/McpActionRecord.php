<?php

declare(strict_types=1);

namespace Mirasai\Library\Mcp;

/**
 * What an activity log entry says about one MCP write: the tool, what it
 * targeted, the etags around it and the client that sent it. Never argument
 * values beyond the target ids: layouts, texts and code stay out of the log.
 *
 * Mirrored in mirasai-wp. Both copies must stay free of WordPress and Joomla
 * calls so docker/test-action-log.php can run them as plain PHP.
 */
final class McpActionRecord
{
    /** Arguments that name what a tool acts on, in the order they are reported. */
    private const TARGET_KEYS = ['key', 'post_id', 'article_id', 'module_id', 'widget_id', 'term_id', 'category_id', 'style_id', 'id', 'position', 'path', 'url'];

    private const ETAG_PAIRS = [['old_etag', 'new_etag'], ['source_etag', 'target_etag'], [null, 'etag'], [null, 'collection_etag']];

    private const MAX_FIELD = 120;

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $result
     * @param array<string, string> $headers lowercase names
     * @return array{tool: string, risk_level: string, target: string, client: string, old_etag: string, new_etag: string}|null
     *         null when nothing was written: a read, a dry run, a preview or an error
     */
    public static function build(string $tool, string $riskLevel, array $arguments, array $result, array $headers): ?array
    {
        if ($riskLevel === 'read' || isset($result['error'])
            || ($arguments['dry_run'] ?? null) === true || ($result['dry_run'] ?? null) === true
            || ($result['action'] ?? null) === 'preview'
        ) {
            return null;
        }

        $targets = [];

        foreach (self::TARGET_KEYS as $key) {
            $value = $arguments[$key] ?? null;

            if ((is_string($value) && trim($value) !== '') || is_int($value)) {
                $targets[] = $key . '=' . self::clip((string) $value);
            }
        }

        [$old, $new] = ['', ''];

        foreach (self::ETAG_PAIRS as [$oldKey, $newKey]) {
            if (is_string($result[$newKey] ?? null) && $result[$newKey] !== '') {
                $old = $oldKey !== null && is_string($result[$oldKey] ?? null) ? $result[$oldKey] : '';
                $new = $result[$newKey];
                break;
            }
        }

        return [
            'tool' => self::clip($tool),
            'risk_level' => $riskLevel,
            'target' => $targets === [] ? '-' : implode(', ', $targets),
            'client' => self::client($headers),
            'old_etag' => substr($old, 0, 12),
            'new_etag' => substr($new, 0, 12),
        ];
    }

    /**
     * The client name the router forwards (X-MCP-Client, read by Simple History on WordPress), else the User-Agent.
     *
     * @param array<string, string> $headers
     */
    public static function client(array $headers): string
    {
        foreach (['x-mcp-client', 'user-agent'] as $name) {
            $value = self::clip((string) ($headers[$name] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return 'unknown';
    }

    private static function clip(string $value): string
    {
        $value = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $value));

        return mb_strlen($value, 'UTF-8') > self::MAX_FIELD ? mb_substr($value, 0, self::MAX_FIELD, 'UTF-8') . '…' : $value;
    }
}
