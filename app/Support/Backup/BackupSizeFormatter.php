<?php

namespace App\Support\Backup;

/**
 * Shared human-readable byte formatting for the backup management UI (stat
 * cards + table column) — kept in one place so both never drift.
 */
final class BackupSizeFormatter
{
    public static function format(?int $bytes): string
    {
        if ($bytes === null || $bytes < 0) {
            return '—';
        }

        if ($bytes === 0) {
            return '0 KB';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unitIndex = 0;

        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }

        $decimals = $unitIndex === 0 ? 0 : 2;

        return number_format($value, $decimals, '.', ',').' '.$units[$unitIndex];
    }
}
