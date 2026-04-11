<?php

declare(strict_types=1);

/**
 * Alters the `holidays` table created by 2026-04-07_0002_holidays.php:
 *   - Renames column `date` → `date_local`
 *   - Adds column `is_public_holiday`
 *
 * Safe to re-run via MigrationRunner (tracked in schema_migrations).
 */
return function (PDO $pdo): void {
    $pdo->exec(
        'ALTER TABLE holidays
             CHANGE COLUMN `date` `date_local` DATE NOT NULL,
             ADD COLUMN `is_public_holiday` TINYINT(1) NOT NULL DEFAULT 0'
    );
};
