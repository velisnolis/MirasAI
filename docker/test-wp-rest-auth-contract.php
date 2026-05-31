<?php
/**
 * Test: WordPress REST auth accepts a valid MirasAI token even when a lower
 * capability user is logged in via cookies.
 *
 * Run from the repo root:
 *   php docker/test-wp-rest-auth-contract.php
 */

declare(strict_types=1);

final class WP_Error
{
    public function __construct(
        public string $code,
        public string $message,
        public array $data = []
    ) {}
}

final class FakeRestRequest
{
    /** @param array<string, string> $headers */
    public function __construct(private array $headers) {}

    public function get_header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }
}

$GLOBALS['mirasai_wp_logged_in'] = false;
$GLOBALS['mirasai_wp_manage_options'] = false;

function is_user_logged_in(): bool
{
    return $GLOBALS['mirasai_wp_logged_in'];
}

function current_user_can(string $capability): bool
{
    return $capability === 'manage_options' && $GLOBALS['mirasai_wp_manage_options'];
}

function get_option(string $name, mixed $default = false): mixed
{
    return $default;
}

function wp_check_password(string $password, string $hash): bool
{
    return false;
}

putenv('MIRASAI_WP_TOKEN=valid-token');

require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Mcp/RestController.php';

use Mirasai\WordPress\Mcp\RestController;

$passed = 0;
$failed = 0;

function expectWpRestAuth(string $label, mixed $actual, mixed $expected): void
{
    global $passed, $failed;

    if ($actual === $expected) {
        echo "[PASS] {$label}\n";
        $passed++;

        return;
    }

    echo "[FAIL] {$label}\n";
    echo "       Expected: " . var_export($expected, true) . "\n";
    echo "       Actual:   " . var_export($actual, true) . "\n";
    $failed++;
}

$controller = (new ReflectionClass(RestController::class))->newInstanceWithoutConstructor();

$GLOBALS['mirasai_wp_logged_in'] = true;
$GLOBALS['mirasai_wp_manage_options'] = false;
$validTokenAsEditor = $controller->authorize(new FakeRestRequest(['x-mirasai-token' => 'valid-token']));
expectWpRestAuth('valid token wins even when logged-in user lacks manage_options', $validTokenAsEditor, true);

$missingTokenAsEditor = $controller->authorize(new FakeRestRequest([]));
expectWpRestAuth('logged-in user without manage_options is still rejected without token', $missingTokenAsEditor instanceof WP_Error ? $missingTokenAsEditor->code : null, 'mirasai_insufficient_capability');

$GLOBALS['mirasai_wp_manage_options'] = true;
$invalidTokenAsAdmin = $controller->authorize(new FakeRestRequest(['x-mirasai-token' => 'wrong']));
expectWpRestAuth('logged-in admin may authenticate through cookie even with stale token header', $invalidTokenAsAdmin, true);

$GLOBALS['mirasai_wp_logged_in'] = false;
$GLOBALS['mirasai_wp_manage_options'] = false;
$invalidAnonymous = $controller->authorize(new FakeRestRequest(['x-mirasai-token' => 'wrong']));
expectWpRestAuth('anonymous invalid token is rejected', $invalidAnonymous instanceof WP_Error ? $invalidAnonymous->code : null, 'mirasai_invalid_token');

if ($failed > 0) {
    echo "\n{$failed} WordPress REST auth contract test(s) failed.\n";
    exit(1);
}

echo "\nAll {$passed} WordPress REST auth contract tests passed.\n";
