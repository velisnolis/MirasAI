<?php
/**
 * Test: post-update log deltas and the page cache verdict, on real temporary files.
 *
 * Run from the repo root:
 *   php docker/test-wp-checks.php
 */

declare(strict_types=1);

require __DIR__ . '/../packages/mirasai-wp/src/Checks/LogReader.php';
require __DIR__ . '/../packages/mirasai-wp/src/Checks/CachePurger.php';

use Mirasai\WordPress\Checks\CachePurger;
use Mirasai\WordPress\Checks\LogReader;

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

$root = sys_get_temp_dir() . '/mirasai-checks-' . bin2hex(random_bytes(4));
mkdir($root . '/wp-content', 0700, true);
mkdir($root . '/wp-admin', 0700);
$log = $root . '/error_log';
$reader = fn (?string $configured = null, bool $logging = true) => new LogReader($root, 'secret', $logging, $configured ?? $log);

// Nothing on disk.
$r = $reader();
$b = $r->baseline();
check($b['state'] === 'pass', 'configured fixed file is covered');
$c = $r->compare($b['cursor']);
check($c['state'] === 'pass' && $c['files']['error_log']['reason'] === 'absent', 'absent stays pass');
check(!isset($c['files'][LogReader::CONFIGURED]), 'no extra label for a fixed file');

// A warning is counted but does not fail the check.
file_put_contents($log, "[1] old PHP Fatal error: before the baseline\n");
$b = $r->baseline();
file_put_contents($log, "[2] PHP Warning: x\n[3] PHP Deprecated: y\n", FILE_APPEND);
$c = $r->compare($b['cursor']);
check($c['state'] === 'pass', 'warnings are not an issue');
check($c['files']['error_log']['counts']['warning'] === 1 && $c['files']['error_log']['counts']['deprecated'] === 1, 'warnings are counted');
check($c['files']['error_log']['counts']['fatal'] === 0, 'an old fatal is outside the delta');

// A new fatal is.
$b = $r->baseline();
file_put_contents($log, "[4] PHP Fatal error: boom\nStack trace:\n#0 {main}\n  thrown in /x on line 1\n", FILE_APPEND);
$c = $r->compare($b['cursor']);
check($c['state'] === 'issue' && $c['files']['error_log']['counts']['fatal'] === 1, 'new fatal is an issue');
check($c['files']['error_log']['counts']['other'] === 0, 'stack trace lines are not counted');
check(!str_contains(json_encode($c), 'boom') && !str_contains(json_encode($c), $root), 'no message or path in the response');

// Rotation, partial line and limit never read as clean.
$b = $r->baseline();
unlink($log);
file_put_contents($log, "new\n");
check($r->compare($b['cursor'])['files']['error_log']['reason'] === 'rotated_or_truncated', 'rotation detected');
$b = $r->baseline();
file_put_contents($log, 'no newline', FILE_APPEND);
$c = $r->compare($b['cursor']);
check($c['state'] === 'unknown' && $c['files']['error_log']['reason'] === 'partial', 'partial line is unknown');
file_put_contents($log, "\n", FILE_APPEND);
$b = $r->baseline();
file_put_contents($log, str_repeat("x\n", 140000), FILE_APPEND);
$c = $r->compare($b['cursor']);
check($c['state'] === 'unknown' && $c['files']['error_log']['reason'] === 'limit', 'too much new data is unknown');

// Coverage.
check($reader('')->baseline()['state'] === 'unknown', 'no configured log is not covered');
check($reader('syslog')->baseline()['state'] === 'unknown', 'syslog is not covered');
check($reader($log, false)->baseline()['state'] === 'unknown', 'log_errors off is not covered');
$u = $reader('');
check($u->compare($u->baseline()['cursor'])['covered'] === false, 'coverage is reported');
$relative = $reader('error_log');
check($relative->baseline()['state'] === 'pass', 'relative error_log is covered');

// A log php.ini keeps outside the fixed list is read, and its path stays private.
$outside = sys_get_temp_dir() . '/mirasai-outside-' . bin2hex(random_bytes(4));
file_put_contents($outside, "first\n");
$e = $reader($outside);
$b = $e->baseline();
file_put_contents($outside, "PHP Fatal error: elsewhere\n", FILE_APPEND);
$c = $e->compare($b['cursor']);
check($c['state'] === 'issue' && $c['files'][LogReader::CONFIGURED]['counts']['fatal'] === 1, 'configured outside log is compared');
check(!str_contains(json_encode($c), basename($outside)), 'outside path is not returned');

// Links below the root are refused.
symlink($outside, $root . '/wp-content/debug.log');
$c = $r->compare($r->baseline()['cursor']);
check($c['files']['wp-content/debug.log']['reason'] === 'unreadable' && $c['state'] === 'unknown', 'link is refused');
unlink($root . '/wp-content/debug.log');

// Cursors.
$bad = 0;
foreach (['', 'x', 'a.b', str_repeat('a', 9000) . '.b'] as $cursor) {
    try {
        $r->compare($cursor);
    } catch (\InvalidArgumentException) {
        $bad++;
    }
}
check($bad === 4, 'malformed cursors are refused');
$b = $r->baseline();
foreach ([new LogReader($root, 'other', true, $log), $reader($outside)] as $i => $foreign) {
    $refused = false;
    try {
        $foreign->compare($b['cursor']);
    } catch (\InvalidArgumentException) {
        $refused = true;
    }
    check($refused, 'foreign cursor refused ' . $i);
}
[$payload] = explode('.', $b['cursor']);
$old = json_decode(base64_decode($payload), true);
$old['at'] = time() - 7201;
$oldPayload = base64_encode(json_encode($old));
$refused = false;
try {
    $r->compare($oldPayload . '.' . hash_hmac('sha256', 'mirasai-checks:' . $oldPayload, 'secret'));
} catch (\InvalidArgumentException) {
    $refused = true;
}
check($refused, 'expired cursor refused');

// Page cache verdict.
check(CachePurger::pageCacheState(['wp_rocket'], 0, true) === 'purged', 'known cache purged');
check(CachePurger::pageCacheState([], 0, true) === 'unsupported', 'unknown drop-in is not hidden');
check(CachePurger::pageCacheState([], 0, false) === 'none', 'no cache');
check(CachePurger::pageCacheState(['wp_rocket'], 1, true) === 'error', 'an error wins');

@unlink($outside);
exec('rm -rf ' . escapeshellarg($root));
echo $fails ? "$fails of $count failed\n" : "$count checks passed\n";
exit($fails ? 1 : 0);
