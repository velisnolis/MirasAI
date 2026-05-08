<?php

declare(strict_types=1);

namespace Mirasai\Library\Mcp;

final class McpPathNormalizer
{
    public static function normalize(string $path, string $base = ''): string
    {
        if ($base !== '' && $base !== '/' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        if (str_starts_with($path, '/index.php/')) {
            $path = substr($path, strlen('/index.php'));
        }

        return $path;
    }
}
