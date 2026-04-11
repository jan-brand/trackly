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
    // Prepare a safe INFORMATION_SCHEMA lookup and fall back to false on error.
    try {
        $hasDateStmt = $pdo->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column"
        );
        $hasDateStmt->execute([':table' => 'holidays', ':column' => 'date']);
        $hasDate = $hasDateStmt->fetchColumn() !== false;
    } catch (\Throwable $e) {
        $hasDate = false;
    }

    if ($hasDate) {
        try {
            $pdo->exec("ALTER TABLE holidays CHANGE COLUMN `date` `date_local` DATE NOT NULL");
        } catch (\PDOException $e) {
            // Ignore "Unknown column" errors — migration should be idempotent.
            if ($e->getCode() !== '42S22') {
                throw $e;
            }
        }
    }

    try {
        $hasFlagStmt = $pdo->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column"
        );
        $hasFlagStmt->execute([':table' => 'holidays', ':column' => 'is_public_holiday']);
        $hasFlag = $hasFlagStmt->fetchColumn() !== false;
    } catch (\Throwable $e) {
        $hasFlag = false;
    }

    if (! $hasFlag) {
        try {
            $pdo->exec("ALTER TABLE holidays ADD COLUMN `is_public_holiday` TINYINT(1) NOT NULL DEFAULT 0");
        } catch (\PDOException $e) {
            // Ignore duplicate/unknown column errors to keep migration idempotent.
            if ($e->getCode() !== '42S21' && $e->getCode() !== '42S22') {
                throw $e;
            }
        }
    }
};
