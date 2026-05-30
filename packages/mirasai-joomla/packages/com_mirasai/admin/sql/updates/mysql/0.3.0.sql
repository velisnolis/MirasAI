--
-- MirasAI 0.3.0 — Smart Sudo elevation tables
-- Update DDL (same as fresh install; uses IF NOT EXISTS for safety)
--

CREATE TABLE IF NOT EXISTS `#__mirasai_elevation_grants` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created_by` INT UNSIGNED NOT NULL,
    `reason` VARCHAR(500) NOT NULL,
    `scopes_json` TEXT NOT NULL,
    `issued_at` DATETIME NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `revoked_at` DATETIME DEFAULT NULL,
    `use_count` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    INDEX `idx_expires` (`expires_at`),
    INDEX `idx_active` (`revoked_at`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__mirasai_elevation_audit` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `grant_id` INT UNSIGNED NOT NULL,
    `tool_name` VARCHAR(100) NOT NULL,
    `arguments_summary` TEXT NOT NULL,
    `result_summary` VARCHAR(50) NOT NULL DEFAULT 'pending',
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_grant` (`grant_id`),
    CONSTRAINT `fk_audit_grant` FOREIGN KEY (`grant_id`)
        REFERENCES `#__mirasai_elevation_grants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
