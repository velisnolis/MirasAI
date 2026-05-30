<?php

declare(strict_types=1);

namespace Mirasai\Library\Sandbox;

/**
 * Value object representing an active elevation grant.
 *
 * Immutable — created from a DB row, never modified in-place.
 * All timestamps are UTC DateTimeImmutable.
 */
final class ElevationGrant
{
    public readonly int $id;
    public readonly int $createdBy;
    public readonly string $reason;
    /** @var list<string> */
    public readonly array $scopes;
    public readonly \DateTimeImmutable $issuedAt;
    public readonly \DateTimeImmutable $expiresAt;
    public readonly ?\DateTimeImmutable $revokedAt;
    public readonly int $useCount;

    /**
     * @param list<string> $scopes
     */
    public function __construct(
        int $id,
        int $createdBy,
        string $reason,
        array $scopes,
        \DateTimeImmutable $issuedAt,
        \DateTimeImmutable $expiresAt,
        ?\DateTimeImmutable $revokedAt,
        int $useCount,
    ) {
        $this->id = $id;
        $this->createdBy = $createdBy;
        $this->reason = $reason;
        $this->scopes = $scopes;
        $this->issuedAt = $issuedAt;
        $this->expiresAt = $expiresAt;
        $this->revokedAt = $revokedAt;
        $this->useCount = $useCount;
    }

    /**
     * Create from a database row (associative array).
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $utc = new \DateTimeZone('UTC');

        $revokedAt = null;
        if (!empty($row['revoked_at'])) {
            $revokedAt = new \DateTimeImmutable($row['revoked_at'], $utc);
        }

        $scopes = json_decode((string) $row['scopes_json'], true);
        if (!\is_array($scopes)) {
            $scopes = [];
        }

        return new self(
            id: (int) $row['id'],
            createdBy: (int) $row['created_by'],
            reason: (string) $row['reason'],
            scopes: array_values($scopes),
            issuedAt: new \DateTimeImmutable((string) $row['issued_at'], $utc),
            expiresAt: new \DateTimeImmutable((string) $row['expires_at'], $utc),
            revokedAt: $revokedAt,
            useCount: (int) $row['use_count'],
        );
    }

    /**
     * Is this grant currently active (not expired and not revoked)?
     */
    public function isActive(): bool
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if ($this->revokedAt !== null) {
            return false;
        }

        return $now < $this->expiresAt;
    }

    /**
     * Does this grant allow the given tool?
     */
    public function allowsTool(string $toolName): bool
    {
        return \in_array($toolName, $this->scopes, true);
    }

    /**
     * Seconds remaining until expiry, clamped to zero.
     */
    public function getRemainingSeconds(): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $diff = $this->expiresAt->getTimestamp() - $now->getTimestamp();

        return max(0, $diff);
    }
}
