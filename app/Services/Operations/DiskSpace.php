<?php

namespace App\Services\Operations;

class DiskSpace
{
    public function freeBytes(string $path): ?float
    {
        $free = @disk_free_space($path);

        return $free === false ? null : (float) $free;
    }
}
