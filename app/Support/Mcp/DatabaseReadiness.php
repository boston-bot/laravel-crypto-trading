<?php

namespace App\Support\Mcp;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DatabaseReadiness
{
    public function inspect(): array
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('PostgreSQL is required.');
        }
        $row = $connection->selectOne('SELECT current_database() AS database, current_schema() AS schema, current_user AS role, pg_get_userbyid(nspowner) AS owner FROM pg_namespace WHERE nspname = current_schema()');
        $expected = $connection->getConfig('search_path');
        if (! $row || $expected !== 'mcp_trading' || $row->schema !== $expected) {
            throw new RuntimeException('Application schema is unavailable or search_path is invalid.');
        }
        $migration = $connection->selectOne("SELECT schemaname FROM pg_tables WHERE tablename = 'migrations' AND schemaname = current_schema()");

        return ['database' => $row->database, 'schema' => $row->schema, 'role' => $row->role, 'owner' => $row->owner, 'migrations_present' => $migration !== null];
    }
}
