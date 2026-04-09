<?php

declare(strict_types=1);

namespace App\Domain\Time;

/**
 * Produces a normalised snapshot array from a time_entries row.
 *
 * The snapshot is stored verbatim in time_entry_audit_log.old_json / new_json.
 */
final class TimeEntrySnapshot
{
    /**
     * Extract only the auditable fields from a time_entries DB row.
     *
     * @param  array<string, mixed> $row  A row fetched from time_entries.
     * @return array<string, mixed>
     */
    public static function fromRow(array $row): array
    {
        return [
            'user_id'              => $row['user_id']              ?? null,
            'date_local'           => $row['date_local']           ?? null,
            'start_at'             => $row['start_at']             ?? null,
            'end_at'               => $row['end_at']               ?? null,
            'break_minutes'        => $row['break_minutes']        ?? null,
            'net_minutes'          => $row['net_minutes']          ?? null,
            'entry_source'         => $row['entry_source']         ?? null,
            'status'               => $row['status']               ?? null,
            'approved_by_user_id'  => $row['approved_by_user_id']  ?? null,
            'approved_at'          => $row['approved_at']          ?? null,
            'cancelled_by_user_id' => $row['cancelled_by_user_id'] ?? null,
            'cancelled_at'         => $row['cancelled_at']         ?? null,
        ];
    }
}
