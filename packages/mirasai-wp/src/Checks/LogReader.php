<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Checks;

/**
 * Bounded PHP log deltas for post-update checks. Returns counts only, never message contents.
 *
 * WordPress sibling of the Joomla Miras Checks reader. A new fatal is an issue; noisier levels are
 * only counted, because busy WordPress sites log warnings and deprecations on ordinary requests.
 */
final class LogReader
{
    private const FILES = ['error_log', 'wp-admin/error_log', 'wp-content/debug.log'];
    /** Label of the file php.ini points to, when it is none of the fixed ones. Its path is never returned. */
    public const CONFIGURED = 'php';
    private const LIMIT = 262144;
    private const MAX_AGE = 7200;

    private string $external = '';

    public function __construct(
        private string $root,
        private string $secret,
        private bool $logging,
        private string $configured
    ) {
        $this->root = rtrim($root, '/');
        $configured = trim($configured);

        if (str_starts_with($configured, '/') && !in_array($this->label($configured), self::FILES, true)) {
            $this->external = $configured;
        }
    }

    public static function fromWordPress(): self
    {
        return new self(
            (string) (realpath(ABSPATH) ?: ABSPATH),
            wp_salt('auth'),
            (bool) filter_var(ini_get('log_errors'), FILTER_VALIDATE_BOOLEAN),
            (string) ini_get('error_log')
        );
    }

    private function label(string $path): string
    {
        return str_starts_with($path, $this->root . '/') ? substr($path, strlen($this->root) + 1) : $path;
    }

    /** @return list<string> */
    private function labels(): array
    {
        return $this->external === '' ? self::FILES : [...self::FILES, self::CONFIGURED];
    }

    private function covered(): bool
    {
        if (!$this->logging || $this->configured === '' || $this->configured === 'syslog') {
            return false;
        }

        if ($this->external !== '') {
            return true;
        }

        return in_array($this->label($this->configured), self::FILES, true);
    }

    /** @return array<string, mixed> */
    private function open(string $label): array
    {
        $external = $label === self::CONFIGURED;
        $path = $external ? $this->external : $this->root . '/' . $label;
        clearstatcache(true, $path);

        // Links below the root are rejected; the hosting's own path above it is not ours to judge.
        $part = $external ? $path : $this->root;

        foreach ($external ? [''] : explode('/', $label) as $segment) {
            $part .= $segment === '' ? '' : '/' . $segment;

            if (@is_link($part)) {
                return ['state' => 'unreadable'];
            }
        }

        if (!@file_exists($path)) {
            return ['state' => 'absent'];
        }

        if (!@is_file($path) || !@is_readable($path)) {
            return ['state' => 'unreadable'];
        }

        $f = @fopen($path, 'rb');

        if (!$f) {
            return ['state' => 'unreadable'];
        }

        $s = fstat($f);
        $ls = @lstat($path);

        if (!$s || !$ls || $s['ino'] !== $ls['ino'] || $s['dev'] !== $ls['dev'] || ($s['mode'] & 0170000) !== 0100000) {
            fclose($f);

            return ['state' => 'unreadable'];
        }

        return ['state' => 'readable', 'f' => $f, 'ino' => $s['ino'], 'dev' => $s['dev'], 'size' => $s['size']];
    }

    /** @param resource $f */
    private function anchor($f, int $size): string
    {
        fseek($f, max(0, $size - 1024));

        return hash('sha256', ($size ? fread($f, min(1024, $size)) : '') ?: '');
    }

    private function scope(): string
    {
        return hash('sha256', $this->root . '|' . $this->external);
    }

    /** @return array<string, mixed> */
    public function baseline(): array
    {
        $files = [];

        foreach ($this->labels() as $label) {
            $s = $this->open($label);

            if (isset($s['f'])) {
                $s['anchor'] = $this->anchor($s['f'], $s['size']);
                fseek($s['f'], max(0, $s['size'] - 1));
                $s['boundary'] = $s['size'] === 0 || fread($s['f'], 1) === "\n";
                fclose($s['f']);
                unset($s['f']);
            }

            $files[$label] = $s;
        }

        $payload = base64_encode(json_encode(
            ['v' => 1, 'at' => time(), 'scope' => $this->scope(), 'covered' => $this->covered(), 'files' => $files],
            JSON_THROW_ON_ERROR
        ));

        return [
            'version' => 1,
            'cursor' => $payload . '.' . hash_hmac('sha256', 'mirasai-checks:' . $payload, $this->secret),
            'state' => $this->covered() ? 'pass' : 'unknown',
        ];
    }

    /** @return array<string, int> */
    public static function counts(string $bytes): array
    {
        $counts = ['fatal' => 0, 'warning' => 0, 'notice' => 0, 'deprecated' => 0, 'other' => 0];

        foreach (explode("\n", $bytes) as $line) {
            if (trim($line) === '' || preg_match('/(?:Stack trace:|^\s*#\d+\s|^\s*thrown in\s)/', $line)) {
                continue;
            }

            if (preg_match('/(?:PHP\s+)?(?:Fatal error|Parse error|Uncaught\s+(?:Error|Exception))\b/i', $line)) {
                $counts['fatal']++;
            } elseif (preg_match('/\b(?:PHP\s+)?(?:Warning|Notice|Deprecated)\b/i', $line, $m)) {
                $counts[strtolower((string) preg_replace('/^PHP\s+/i', '', $m[0]))]++;
            } else {
                $counts['other']++;
            }
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     * @throws \InvalidArgumentException on a forged, foreign or expired cursor
     */
    public function compare(string $cursor): array
    {
        if (strlen($cursor) > 8192 || substr_count($cursor, '.') !== 1) {
            throw new \InvalidArgumentException('Invalid cursor');
        }

        [$payload, $signature] = explode('.', $cursor);

        if (!hash_equals(hash_hmac('sha256', 'mirasai-checks:' . $payload, $this->secret), $signature)) {
            throw new \InvalidArgumentException('Invalid cursor');
        }

        $b = json_decode(base64_decode($payload, true) ?: '', true, 32, JSON_THROW_ON_ERROR);

        if (($b['v'] ?? 0) !== 1 || ($b['scope'] ?? '') !== $this->scope()
            || ($b['at'] ?? 0) > time() + 30 || time() - ($b['at'] ?? 0) > self::MAX_AGE) {
            throw new \InvalidArgumentException('Expired cursor');
        }

        $out = ['version' => 1, 'state' => 'pass', 'covered' => $this->covered() && ($b['covered'] ?? false), 'files' => []];

        foreach ($this->labels() as $label) {
            $out['files'][$label] = $this->delta($label, $b['files'][$label] ?? ['state' => 'unreadable']);
        }

        $states = array_column($out['files'], 'state');
        $out['state'] = in_array('issue', $states, true) ? 'issue' : (!$out['covered'] || in_array('unknown', $states, true) ? 'unknown' : 'pass');

        return $out;
    }

    /**
     * @param array<string, mixed> $old
     * @return array<string, mixed>
     */
    private function delta(string $label, array $old): array
    {
        $s = $this->open($label);
        $r = ['state' => 'unknown', 'reason' => 'unreadable', 'counts' => self::counts('')];

        if ($s['state'] === 'absent') {
            $r['reason'] = $old['state'] === 'absent' ? 'absent' : 'removed';
            $r['state'] = $old['state'] === 'absent' ? 'pass' : 'unknown';

            return $r;
        }

        if (!isset($s['f'])) {
            return $r;
        }

        $f = $s['f'];
        $start = $old['state'] === 'absent' ? 0 : (int) ($old['size'] ?? 0);
        $same = $old['state'] === 'absent' || ($old['state'] === 'readable' && $s['ino'] === $old['ino'] && $s['dev'] === $old['dev']
            && $s['size'] >= $start && hash_equals((string) $old['anchor'], $this->anchor($f, $start)));

        if (!$same) {
            $r['reason'] = 'rotated_or_truncated';
        } elseif ($s['size'] - $start > self::LIMIT) {
            $r['reason'] = 'limit';
        } elseif ($s['size'] === $start) {
            $r['state'] = 'pass';
            $r['reason'] = 'unchanged';
        } else {
            fseek($f, $start);
            $delta = $s['size'] - $start;
            $data = (string) fread($f, $delta);
            $r['counts'] = self::counts($data);
            $fatal = $r['counts']['fatal'] > 0;
            $r['state'] = $fatal ? 'issue' : 'pass';
            $r['reason'] = 'appended';

            if (strlen($data) !== $delta || !($old['boundary'] ?? true) || !str_ends_with($data, "\n")) {
                $r['state'] = $fatal ? 'issue' : 'unknown';
                $r['reason'] = 'partial';
            }
        }

        fclose($f);

        return $r;
    }
}
