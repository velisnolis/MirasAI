<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Mcp;

/**
 * Ties a confirmed guarded write to the dry run that previewed it.
 *
 * if_match protects the state of what is written; it does not stop a confirm
 * from carrying other arguments than the preview did. A dry run returns this
 * token; a confirm that sends it back is refused unless its arguments are the
 * same. Sending it is optional, so older clients keep working.
 *
 * Mirrored in lib_mirasai. Both copies must stay free of WordPress and Joomla
 * calls so docker/test-preview-token.php can run them as plain PHP.
 */
final class PreviewToken
{
    public const ARGUMENT = 'preview_token';

    /** @param array<string, mixed> $arguments */
    public static function for(string $tool, array $arguments): string
    {
        unset($arguments['dry_run'], $arguments['confirm_guarded_write'], $arguments[self::ARGUMENT]);

        return hash('sha256', $tool . "\n" . json_encode(self::canonical($arguments), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map([self::class, 'canonical'], $value);
    }
}
