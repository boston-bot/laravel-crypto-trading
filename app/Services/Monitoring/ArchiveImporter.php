<?php

namespace App\Services\Monitoring;

use App\Models\McpConnection;
use App\Models\Monitoring\ArchiveImport;
use App\Models\Monitoring\ArchiveRow;
use App\Support\Mcp\Decimal;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ArchiveImporter
{
    /** Bounded JSON export contract. Original bytes stay with operator; immutable hash/rows are retained on explicit commit. */
    public function preview(string $bytes): array
    {
        if (strlen($bytes) > 5 * 1024 * 1024) {
            throw new InvalidArgumentException('Archive exceeds 5 MiB.');
        }
        $rows = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 10000) {
            throw new InvalidArgumentException('Archive must be a JSON list of at most 10000 rows.');
        }
        $seen = [];
        $report = [];
        $mapping = ['Bot Control' => 'disabled_policy_draft', 'Check Log' => 'historical_run', 'Decision Log' => 'sourced_thesis_history',
            'Trade Ledger' => 'historical_order', 'Performance Log' => 'historical_snapshot', 'Opportunity Review' => 'historical_research', 'System Health' => 'historical_incident'];
        foreach ($rows as $index => $row) {
            $reason = null;
            $id = is_array($row) ? ($row['id'] ?? null) : null;
            $sheet = is_array($row) ? ($row['sheet'] ?? '') : '';
            if (! is_string($id) || trim($id) === '' || strtolower(trim($id)) === 'undefined' || strlen($id) > 128) {
                $reason = 'missing_or_invalid_original_id';
            } elseif (! isset($mapping[$sheet])) {
                $reason = 'unknown_sheet';
            } elseif (isset($seen[$sheet.':'.$id])) {
                $reason = 'duplicate_source_reference';
            } else {
                $seen[$sheet.':'.$id] = true;
                try {
                    if ($sheet === 'Trade Ledger') {
                        $q = Decimal::from($row['quantity'] ?? null);
                        $f = Decimal::from($row['filled_quantity'] ?? null);
                        if ($q->compareTo(Decimal::from('0')) <= 0 || $f->compareTo(Decimal::from('0')) < 0 || $f->compareTo($q) > 0 || (($row['status'] ?? '') === 'filled' && $f->compareTo($q) !== 0)) {
                            $reason = 'contradictory_fill';
                        }
                    }
                    if ($sheet === 'Performance Log' && ($row['flows_verified'] ?? false) !== true) {
                        $reason = 'unknown_flows';
                    }
                } catch (InvalidArgumentException) {
                    $reason = 'invalid_decimal';
                }
            }
            $report[] = ['source_row' => $index + 1, 'source_ref' => is_string($id) && strlen($id) <= 128 ? $id : null,
                'status' => $reason === null ? 'archived_only' : ($reason === 'duplicate_source_reference' ? 'duplicate' : 'quarantined'),
                'reason' => $reason, 'mapping' => $mapping[$sheet] ?? null, 'payload' => $row];
        }

        return ['source_hash' => hash('sha256', $bytes), 'rows' => $report, 'total' => count($report),
            'quarantined' => count(array_filter($report, fn ($r) => $r['status'] === 'quarantined')),
            'duplicates' => count(array_filter($report, fn ($r) => $r['status'] === 'duplicate')),
            'operational_import' => false];
    }

    public function commit(McpConnection $connection, int $actorId, string $bytes): ArchiveImport
    {
        $report = $this->preview($bytes);

        return DB::transaction(function () use ($connection, $actorId, $report) {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            if ($current->user_id !== $actorId || AccountScope::key($current) !== AccountScope::key($connection)) {
                throw new InvalidArgumentException('Account or actor mismatch.');
            }
            $identity = ['mcp_connection_id' => $current->id, 'scope' => AccountScope::key($current), 'source_hash' => $report['source_hash']];
            $existing = ArchiveImport::where($identity)->first();
            if ($existing) {
                return $existing;
            }
            $rows = $report['rows'];
            $previousIds = ArchiveImport::where('mcp_connection_id', $current->id)->where('scope', AccountScope::key($current))->pluck('id');
            $previous = ArchiveRow::whereIn('import_id', $previousIds)->where('status', 'archived_only')->get()
                ->mapWithKeys(fn ($row) => [($row->payload['sheet'] ?? '').':'.$row->source_ref => true])->all();
            foreach ($rows as &$row) {
                if ($row['status'] === 'archived_only' && isset($previous[($row['payload']['sheet'] ?? '').':'.$row['source_ref']])) {
                    $row['status'] = 'duplicate';
                    $row['reason'] = 'duplicate_existing_source_reference';
                    $report['duplicates']++;
                }
            }
            unset($row);
            unset($report['rows']);
            $import = ArchiveImport::create($identity + ['actor_id' => $actorId, 'report' => $report, 'committed_at' => now()]);
            foreach ($rows as $row) {
                unset($row['mapping']);
                ArchiveRow::create(['import_id' => $import->id] + $row);
            }

            return $import;
        });
    }
}
