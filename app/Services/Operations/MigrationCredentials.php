<?php

namespace App\Services\Operations;

use RuntimeException;

final class MigrationCredentials
{
    /** Credentials are read only by the one-off deployment process, never Laravel config. */
    public static function load(string $path): array
    {
        $real = realpath($path);
        $stat = $real === false || is_link($path) ? false : stat($real);
        if (! $stat || ! is_file($real) || ($stat['mode'] & 0777) !== 0600
            || ! function_exists('posix_geteuid') || $stat['uid'] !== posix_geteuid()
            || str_starts_with($real, realpath(base_path()).DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Migration credentials must be an operator-owned 0600 file outside the checkout.');
        }
        $values = json_decode((string) file_get_contents($real), true);
        if (! is_array($values) || array_diff(array_keys($values), ['username', 'password']) !== []
            || ! is_string($values['username'] ?? null) || $values['username'] === ''
            || ! is_string($values['password'] ?? null)) {
            throw new RuntimeException('Migration credentials must contain only a username and password.');
        }

        return $values;
    }
}
