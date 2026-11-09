<?php
/**
 * Test: a confirmed guarded write that sends back the dry run's preview_token
 * must carry the same arguments, on both hosts.
 *
 * Run from the repo root:
 *   php docker/test-preview-token.php
 */

declare(strict_types=1);

namespace {
    putenv('MIRASAI_ENV=staging');
    $_SERVER['MIRASAI_ENV'] = 'staging';
    $root = dirname(__DIR__);

    foreach (['mirasai-wp/src/Tool', 'mirasai-joomla/packages/lib_mirasai/src/Tool'] as $dir) {
        require_once "$root/packages/$dir/ToolInterface.php";
        require_once "$root/packages/$dir/AbstractTool.php";
        require_once "$root/packages/$dir/ToolArgumentValidator.php";
        require_once "$root/packages/$dir/ToolRegistry.php";
    }
    require_once "$root/packages/mirasai-wp/src/Tool/RuntimeSettings.php";
    require_once "$root/packages/mirasai-wp/src/Mcp/McpActionRecord.php";
    require_once "$root/packages/mirasai-wp/src/Mcp/PreviewToken.php";
    require_once "$root/packages/mirasai-wp/src/Mcp/McpHandler.php";
    require_once "$root/packages/mirasai-joomla/packages/lib_mirasai/src/Mirasai.php";
    require_once "$root/packages/mirasai-joomla/packages/lib_mirasai/src/Sandbox/EnvironmentGuard.php";
    require_once "$root/packages/mirasai-joomla/packages/lib_mirasai/src/Mcp/McpActionRecord.php";
    require_once "$root/packages/mirasai-joomla/packages/lib_mirasai/src/Mcp/McpActionLog.php";
    require_once "$root/packages/mirasai-joomla/packages/lib_mirasai/src/Mcp/PreviewToken.php";
    require_once "$root/packages/mirasai-joomla/packages/lib_mirasai/src/Mcp/McpHandler.php";

    if (!function_exists('wp_json_encode')) {
        function wp_json_encode($value, int $options = 0, int $depth = 512)
        {
            return json_encode($value, $options, $depth);
        }
    }

    $fails = 0;
    $count = 0;
    function check(bool $ok, string $name): void
    {
        global $fails, $count;
        $count++;
        if (!$ok) {
            $fails++;
            echo "FAIL: $name\n";
        }
    }
}

namespace WpStub {
    final class Guarded extends \Mirasai\WordPress\Tool\AbstractTool
    {
        public array $calls = [];
        public function getName(): string { return 'test/guarded'; }
        public function getDescription(): string { return 'test'; }
        public function getInputSchema(): array { return ['type' => 'object', 'properties' => ['key' => ['type' => 'string'], 'props' => ['type' => 'object'], 'if_match' => ['type' => 'string'], 'dry_run' => ['type' => 'boolean'], 'confirm_guarded_write' => ['type' => 'boolean']]]; }
        public function getPermissions(): array { return ['risk_level' => self::RISK_GUARDED_WRITE]; }
        public function handle(array $arguments): array { $this->calls[] = $arguments; return ['dry_run' => ($arguments['dry_run'] ?? false) === true, 'action' => ($arguments['dry_run'] ?? false) === true ? 'preview' : 'updated']; }
    }
}

namespace JoomlaStub {
    final class Guarded extends \Mirasai\Library\Tool\AbstractTool
    {
        public array $calls = [];
        public function __construct() {}
        public function getName(): string { return 'test/guarded'; }
        public function getDescription(): string { return 'test'; }
        public function getInputSchema(): array { return ['type' => 'object', 'properties' => ['key' => ['type' => 'string'], 'props' => ['type' => 'object'], 'if_match' => ['type' => 'string'], 'dry_run' => ['type' => 'boolean'], 'confirm_guarded_write' => ['type' => 'boolean']]]; }
        public function getPermissions(): array { return ['risk_level' => self::RISK_GUARDED_WRITE]; }
        public function handle(array $arguments): array { $this->calls[] = $arguments; return ['dry_run' => ($arguments['dry_run'] ?? false) === true, 'action' => ($arguments['dry_run'] ?? false) === true ? 'preview' : 'updated']; }
    }
}

namespace {
    use Mirasai\WordPress\Mcp\PreviewToken as WpToken;
    use Mirasai\Library\Mcp\PreviewToken as JToken;

    // The token itself.
    $a = WpToken::for('t', ['key' => 'k', 'props' => ['b' => 1, 'a' => 2], 'if_match' => 'e', 'dry_run' => true]);
    check($a === WpToken::for('t', ['if_match' => 'e', 'props' => ['a' => 2, 'b' => 1], 'key' => 'k', 'confirm_guarded_write' => true, 'dry_run' => false]), 'token ignores key order, dry_run and confirm');
    check($a !== WpToken::for('t', ['key' => 'k', 'props' => ['a' => 2, 'b' => 3], 'if_match' => 'e']), 'token changes with a value');
    check($a !== WpToken::for('t', ['key' => 'k', 'props' => ['b' => 1, 'a' => 2], 'if_match' => 'f']), 'token changes with if_match');
    check($a !== WpToken::for('u', ['key' => 'k', 'props' => ['b' => 1, 'a' => 2], 'if_match' => 'e']), 'token changes with the tool');
    check(WpToken::for('t', ['l' => [2, 1]]) !== WpToken::for('t', ['l' => [1, 2]]), 'list order matters');
    check($a === JToken::for('t', ['key' => 'k', 'props' => ['b' => 1, 'a' => 2], 'if_match' => 'e']), 'both hosts compute the same token');

    $call = static fn ($handler, array $args): array => $handler->handleRequest(['jsonrpc' => '2.0', 'method' => 'tools/call', 'params' => ['name' => 'test/guarded', 'arguments' => $args], 'id' => 1])['result'];

    foreach (['wordpress' => [\WpStub\Guarded::class, \Mirasai\WordPress\Tool\ToolRegistry::class, \Mirasai\WordPress\Mcp\McpHandler::class],
              'joomla' => [\JoomlaStub\Guarded::class, \Mirasai\Library\Tool\ToolRegistry::class, \Mirasai\Library\Mcp\McpHandler::class]] as $host => [$T, $Reg, $H]) {
        $tool = new $T();
        $registry = new $Reg();
        $registry->register($tool);
        $handler = new $H($registry);
        $write = ['key' => 'k', 'props' => ['content' => 'A'], 'if_match' => 'e'];

        $preview = $call($handler, $write + ['dry_run' => true]);
        $token = $preview['structuredContent']['preview_token'] ?? null;
        check(is_string($token) && strlen($token) === 64, "$host: a guarded dry run returns a preview_token");

        $ok = $call($handler, $write + ['confirm_guarded_write' => true, 'dry_run' => false, 'preview_token' => $token]);
        check(($ok['isError'] ?? false) === false && ($ok['structuredContent']['action'] ?? '') === 'updated', "$host: confirm with the same arguments and the token writes");
        check(!array_key_exists('preview_token', end($tool->calls)), "$host: the token never reaches the tool");

        $before = count($tool->calls);
        $bad = $call($handler, ['key' => 'k', 'props' => ['content' => 'B'], 'if_match' => 'e', 'confirm_guarded_write' => true, 'dry_run' => false, 'preview_token' => $token]);
        check(($bad['structuredContent']['code'] ?? '') === 'preview_mismatch' && count($tool->calls) === $before, "$host: other arguments with the token are refused before the tool runs");

        $legacy = $call($handler, ['key' => 'k', 'props' => ['content' => 'B'], 'if_match' => 'e', 'confirm_guarded_write' => true, 'dry_run' => false]);
        check(($legacy['isError'] ?? false) === false, "$host: a confirm without token works as before");

        $again = $call($handler, ['key' => 'k', 'props' => ['content' => 'B'], 'if_match' => 'e', 'dry_run' => true, 'preview_token' => $token]);
        check(($again['isError'] ?? false) === false && ($again['structuredContent']['preview_token'] ?? '') !== $token, "$host: a new dry run with an old token just previews again");
    }

    $strip = static fn (string $s): string => preg_replace(['/^namespace .*$/m', '/^ \* Mirrored in .*$/m'], '', $s);
    check($strip(file_get_contents("$root/packages/mirasai-wp/src/Mcp/PreviewToken.php")) === $strip(file_get_contents("$root/packages/mirasai-joomla/packages/lib_mirasai/src/Mcp/PreviewToken.php")), 'token copies are identical apart from namespace and mirror note');

    echo $fails === 0 ? "OK: $count checks\n" : "$fails of $count checks failed\n";
    exit($fails === 0 ? 0 : 1);
}
