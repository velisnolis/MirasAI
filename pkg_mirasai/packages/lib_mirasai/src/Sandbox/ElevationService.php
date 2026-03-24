<?php

declare(strict_types=1);

namespace Mirasai\Library\Sandbox;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;

/**
 * Service for time-limited elevation of destructive MCP tools on production.
 *
 * "Smart Sudo" — authenticate once, get elevated privileges for N minutes,
 * then they auto-expire. Every destructive action is logged.
 *
 * Fail-closed: any DB exception during elevation check returns null (blocks tool).
 * Never fail-open.
 */
class ElevationService
{
    private DatabaseInterface $db;

    /** @var ElevationGrant|null|false  null = not loaded, false = loaded but none active */
    private static ElevationGrant|null|false $cachedGrant = null;

    public function __construct()
    {
        $this->db = Factory::getContainer()->get(DatabaseInterface::class);
    }

    /**
     * Activate a new elevation grant.
     *
     * @param list<string> $scopes  Tool names to elevate (e.g. ['file/write', 'file/edit'])
     * @throws \RuntimeException If an active grant already exists
     */
    public function activate(int $userId, array $scopes, int $durationMinutes, string $reason): ElevationGrant
    {
        // Check for existing active grant — only one allowed at a time
        $existing = $this->getActiveGrant();
        if ($existing !== null) {
            throw new \RuntimeException(
                'An elevation is already active (expires in '
                . $existing->getRemainingSeconds() . ' seconds). Revoke it first.'
            );
        }

        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $utc);
        $expires = $now->modify("+{$durationMinutes} minutes");

        $scopesJson = json_encode(array_values($scopes), JSON_UNESCAPED_SLASHES);

        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__mirasai_elevation_grants'))
            ->columns([
                $this->db->quoteName('created_by'),
                $this->db->quoteName('reason'),
                $this->db->quoteName('scopes_json'),
                $this->db->quoteName('issued_at'),
                $this->db->quoteName('expires_at'),
            ])
            ->values(implode(',', [
                (int) $userId,
                $this->db->quote($reason),
                $this->db->quote($scopesJson),
                $this->db->quote($now->format('Y-m-d H:i:s')),
                $this->db->quote($expires->format('Y-m-d H:i:s')),
            ]));

        $this->db->setQuery($query)->execute();

        $grantId = (int) $this->db->insertid();

        // Invalidate cache so next check picks up the new grant
        self::$cachedGrant = null;

        $grant = $this->loadGrant($grantId);

        if ($grant === null) {
            throw new \RuntimeException('Failed to load newly created elevation grant.');
        }

        return $grant;
    }

    /**
     * Revoke an active grant immediately.
     */
    public function revoke(int $grantId): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__mirasai_elevation_grants'))
            ->set($this->db->quoteName('revoked_at') . ' = ' . $this->db->quote($now->format('Y-m-d H:i:s')))
            ->where($this->db->quoteName('id') . ' = ' . (int) $grantId)
            ->where($this->db->quoteName('revoked_at') . ' IS NULL');

        $this->db->setQuery($query)->execute();

        // Invalidate cache
        self::$cachedGrant = null;
    }

    /**
     * Get the currently active elevation grant, if any.
     *
     * Cached per PHP request — at most one DB query per request.
     * Fail-closed: returns null on any DB exception.
     */
    public function getActiveGrant(): ?ElevationGrant
    {
        if (self::$cachedGrant !== null) {
            // false means we already checked and found nothing
            return self::$cachedGrant === false ? null : self::$cachedGrant;
        }

        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            $query = $this->db->getQuery(true)
                ->select('*')
                ->from($this->db->quoteName('#__mirasai_elevation_grants'))
                ->where($this->db->quoteName('revoked_at') . ' IS NULL')
                ->where($this->db->quoteName('expires_at') . ' > ' . $this->db->quote($now->format('Y-m-d H:i:s')))
                ->order($this->db->quoteName('issued_at') . ' DESC')
                ->setLimit(1);

            $row = $this->db->setQuery($query)->loadAssoc();

            if ($row === null) {
                self::$cachedGrant = false;
                return null;
            }

            $grant = ElevationGrant::fromRow($row);
            self::$cachedGrant = $grant;

            return $grant;
        } catch (\Throwable $e) {
            // Fail-closed: DB error = no elevation = tools blocked
            Log::add(
                'ElevationService::getActiveGrant() failed (fail-closed): ' . $e->getMessage(),
                Log::ERROR,
                'mirasai'
            );
            self::$cachedGrant = false;
            return null;
        }
    }

    /**
     * Is elevation active, optionally for a specific tool?
     */
    public function isElevated(?string $toolName = null): bool
    {
        $grant = $this->getActiveGrant();

        if ($grant === null || !$grant->isActive()) {
            return false;
        }

        if ($toolName !== null) {
            return $grant->allowsTool($toolName);
        }

        return true;
    }

    /**
     * Log a destructive tool usage to the audit table.
     *
     * Called BEFORE execution (result_summary = 'pending').
     *
     * @return int The audit entry ID
     */
    public function logUsage(int $grantId, string $toolName, string $argumentsSummary): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__mirasai_elevation_audit'))
            ->columns([
                $this->db->quoteName('grant_id'),
                $this->db->quoteName('tool_name'),
                $this->db->quoteName('arguments_summary'),
                $this->db->quoteName('result_summary'),
                $this->db->quoteName('created_at'),
            ])
            ->values(implode(',', [
                (int) $grantId,
                $this->db->quote($toolName),
                $this->db->quote($argumentsSummary),
                $this->db->quote('pending'),
                $this->db->quote($now->format('Y-m-d H:i:s')),
            ]));

        $this->db->setQuery($query)->execute();

        $auditId = (int) $this->db->insertid();

        // Increment use_count on the grant
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__mirasai_elevation_grants'))
            ->set($this->db->quoteName('use_count') . ' = ' . $this->db->quoteName('use_count') . ' + 1')
            ->where($this->db->quoteName('id') . ' = ' . (int) $grantId);

        $this->db->setQuery($query)->execute();

        return $auditId;
    }

    /**
     * Update the result of an audit entry after tool execution.
     *
     * If this fails, the audit row stays 'pending' — acceptable for diagnostics.
     * The original tool exception is always re-thrown.
     */
    public function finalizeAuditEntry(int $auditId, string $result): void
    {
        try {
            $validResults = ['success', 'error'];
            if (!\in_array($result, $validResults, true)) {
                $result = 'error';
            }

            $query = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__mirasai_elevation_audit'))
                ->set($this->db->quoteName('result_summary') . ' = ' . $this->db->quote($result))
                ->where($this->db->quoteName('id') . ' = ' . (int) $auditId);

            $this->db->setQuery($query)->execute();
        } catch (\Throwable $e) {
            // Log but don't throw — the original tool result matters more
            Log::add(
                'ElevationService::finalizeAuditEntry() failed for audit #' . $auditId . ': ' . $e->getMessage(),
                Log::ERROR,
                'mirasai'
            );
        }
    }

    /**
     * Get the audit log for a specific grant.
     *
     * @return list<array<string, mixed>>
     */
    public function getAuditLog(int $grantId): array
    {
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__mirasai_elevation_audit'))
            ->where($this->db->quoteName('grant_id') . ' = ' . (int) $grantId)
            ->order($this->db->quoteName('created_at') . ' DESC');

        return $this->db->setQuery($query)->loadAssocList() ?: [];
    }

    /**
     * Reset the static cache. Useful for testing.
     */
    public static function resetCache(): void
    {
        self::$cachedGrant = null;
    }

    /**
     * Load a specific grant by ID.
     */
    private function loadGrant(int $grantId): ?ElevationGrant
    {
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__mirasai_elevation_grants'))
            ->where($this->db->quoteName('id') . ' = ' . (int) $grantId);

        $row = $this->db->setQuery($query)->loadAssoc();

        return $row !== null ? ElevationGrant::fromRow($row) : null;
    }
}
