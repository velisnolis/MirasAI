<?php
/**
 * Test: MCP path normalization for root and subdirectory Joomla installs.
 *
 * Run from the repo root:
 *   php docker/test-mcp-path-normalizer.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/packages/mirasai-joomla/packages/lib_mirasai/src/Mcp/McpPathNormalizer.php';

use Mirasai\Library\Mcp\McpPathNormalizer;

$passed = 0;
$failed = 0;

function expectPath(string $label, string $path, string $base, string $expected): void
{
    global $passed, $failed;

    $actual = McpPathNormalizer::normalize($path, $base);

    if ($actual === $expected) {
        echo "[PASS] {$label}\n";
        $passed++;

        return;
    }

    echo "[FAIL] {$label}\n";
    echo "       Expected: {$expected}\n";
    echo "       Actual:   {$actual}\n";
    $failed++;
}

echo "\n=== MCP path normalization ===\n";

expectPath('site app at domain root', '/api/v1/mirasai/mcp', '', '/api/v1/mirasai/mcp');
expectPath('site app under Joomla subdirectory', '/a-rts/api/v1/mirasai/mcp', '/a-rts', '/api/v1/mirasai/mcp');
expectPath('API app under Joomla subdirectory with index.php', '/a-rts/api/index.php/v1/mirasai/mcp', '/a-rts/api', '/v1/mirasai/mcp');
expectPath('API app at domain root with index.php', '/api/index.php/v1/mirasai/mcp', '/api', '/v1/mirasai/mcp');
expectPath('base slash does not strip the whole path', '/api/v1/mirasai/mcp', '/', '/api/v1/mirasai/mcp');

if ($failed > 0) {
    echo "\n{$failed} path normalization test(s) failed.\n";
    exit(1);
}

echo "\nAll {$passed} path normalization tests passed.\n";
