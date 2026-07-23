<?php

namespace App\Enums;

enum BackupScope: string
{
    case Database = 'database';
    case Files = 'files';
    case Full = 'full';

    public function includesDatabase(): bool
    {
        return $this === self::Database || $this === self::Full;
    }

    public function includesFiles(): bool
    {
        return $this === self::Files || $this === self::Full;
    }
}
