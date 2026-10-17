<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps location reference data (states, counties, facilities) identical to the cloud.
 *
 * The cloud is the source of truth. A remote row is matched to a local row by id first, then by
 * business key (state: code; county: state + code, or state + name when code is empty; facility:
 * code). When the business key matches but the ids differ, the local row ADOPTS the remote id and
 * every child foreign key follows, inside one transaction.
 *
 * The primary key is changed by copy -> repoint children -> delete, never by an in-place UPDATE:
 * that works with foreign keys enforced on SQLite and PostgreSQL alike (no PRAGMA / deferred
 * constraint needed) and never trips a unique(code) index or an ON DELETE CASCADE.
 * All writes go through the query builder so models' sync/audit hooks never mark rows pending.
 */
class LocationReconciler
{
    public const TABLES = ['states', 'counties', 'facilities'];

    /** child table => FK column, per location table (every table that references it). */
    public const CHILD_REFERENCES = [
        'states' => [['counties', 'state_id'], ['facilities', 'state_id'], ['patients', 'state_id']],
        'counties' => [['facilities', 'county_id'], ['patients', 'county_id']],
        'facilities' => [['patients', 'facility_id'], ['sample_collections', 'facility_id'], ['testing_laboratories', 'facility_id']],
        // Pull-only reference data that is not a location (never a child of anything but a facility).
        'testing_laboratories' => [],
    ];

    private const SYSTEM_COLUMNS = ['id', 'created_at', 'updated_at', 'last_modified_at', 'sync_status'];

    /**
     * Applies (or, with $dryRun, only plans) one remote location row.
     *
     * $idMappings follows the SyncService convention (id that may appear in data => id to use):
     * a rekeyed row records [$table][oldLocalId] = remoteId. In a dry run, planned matches are
     * recorded under ['__plan'][$table][remoteId] = existingLocalId so children can be matched.
     *
     * @return array{action: string, old_id: ?string, children: array<string,int>}
     */
    public function apply(string $table, array $remote, array &$idMappings = [], bool $dryRun = false): array
    {
        // A retired row (removed on purpose; deletion is not synced) is never brought back.
        if (in_array($remote['code'] ?? null, (array) config("sync.retired_locations.{$table}", []), true)) {
            return ['action' => 'unchanged', 'old_id' => null, 'children' => []];
        }

        $remoteId = (string) $remote['id'];
        $local = DB::table($table)->where('id', $remoteId)->first();
        $action = null;
        $oldId = null;
        $children = [];

        if (! $local) {
            $match = $this->findByBusinessKey($table, $remote, $idMappings);

            if (! $match) {
                if (! $dryRun) {
                    DB::table($table)->insert($this->insertRow($table, $remote));
                }

                return ['action' => 'inserted', 'old_id' => null, 'children' => []];
            }

            $oldId = (string) $match->id;
            $children = $this->rekey($table, $oldId, $remoteId, $dryRun);

            if ($dryRun) {
                $idMappings['__plan'][$table][$remoteId] = $oldId;

                return ['action' => 'rekeyed', 'old_id' => $oldId, 'children' => $children];
            }

            $idMappings[$table][$oldId] = $remoteId;
            $local = DB::table($table)->where('id', $remoteId)->first();
            $action = 'rekeyed';
        }

        $changes = $this->diff($table, (array) $local, $remote);
        if ($changes !== []) {
            if (! $dryRun) {
                DB::table($table)->where('id', $remoteId)->update($changes + $this->syncColumns($table, $remote));
            }
            $action ??= 'updated';
        }

        return ['action' => $action ?? 'unchanged', 'old_id' => $oldId, 'children' => $children];
    }

    /**
     * Changes a location's primary key and every child foreign key. Returns rows moved per child.
     *
     * @return array<string,int>
     */
    public function rekey(string $table, string $oldId, string $newId, bool $dryRun = false): array
    {
        $moved = [];
        $references = [];
        foreach (self::CHILD_REFERENCES[$table] as [$childTable, $column]) {
            if (Schema::hasTable($childTable) && Schema::hasColumn($childTable, $column)) {
                $references[] = [$childTable, $column];
            }
        }

        if ($dryRun) {
            foreach ($references as [$childTable, $column]) {
                $moved["{$childTable}.{$column}"] = DB::table($childTable)->where($column, $oldId)->count();
            }

            return $moved;
        }

        DB::transaction(function () use ($table, $oldId, $newId, $references, &$moved) {
            $row = (array) DB::table($table)->where('id', $oldId)->first();

            if (! DB::table($table)->where('id', $newId)->exists()) {
                // Park the old row's unique code so the copy can take it, then copy under the new id.
                if (array_key_exists('code', $row)) {
                    DB::table($table)->where('id', $oldId)->update(['code' => '~'.$oldId]);
                }
                DB::table($table)->insert(['id' => $newId] + $row);
            }

            foreach ($references as [$childTable, $column]) {
                $moved["{$childTable}.{$column}"] = DB::table($childTable)->where($column, $oldId)->update([$column => $newId]);
            }

            // Children no longer point at the old row, so nothing cascades.
            DB::table($table)->where('id', $oldId)->delete();
        });

        return $moved;
    }

    public function findByBusinessKey(string $table, array $remote, array $idMappings = []): ?object
    {
        $code = $remote['code'] ?? null;

        if ($table === 'counties') {
            $stateId = $idMappings['__plan']['states'][$remote['state_id'] ?? ''] ?? ($remote['state_id'] ?? null);
            if (! $stateId) {
                return null;
            }

            $query = DB::table('counties')->where('state_id', $stateId);
            if ($code !== null && $code !== '') {
                $query->where('code', $code);
            } else {
                $query->whereNull('code')->where('name', $remote['name'] ?? null);
            }

            return $query->first();
        }

        if ($code === null || $code === '') {
            return null;
        }

        return DB::table($table)->where('code', $code)->first();
    }

    /**
     * Read-only: every location by business key, with its local and remote id.
     *
     * @param  array<string, array<int, array>>  $remote  the cloud's locations (see SyncService::fetchRemoteLocations)
     * @return array<int, array{table: string, key: string, name: string, local_id: ?string, remote_id: ?string, status: string}>
     */
    public function compare(array $remote): array
    {
        $rows = [];
        $localKeys = $this->keyedRows($this->localTables());
        $remoteKeys = $this->keyedRows($remote);

        foreach (self::TABLES as $table) {
            $keys = array_unique(array_merge(array_keys($localKeys[$table]), array_keys($remoteKeys[$table])));
            sort($keys);

            foreach ($keys as $key) {
                $local = $localKeys[$table][$key] ?? null;
                $cloud = $remoteKeys[$table][$key] ?? null;

                $rows[] = [
                    'table' => $table,
                    'key' => $key,
                    'name' => (string) (($cloud ?? $local)['name'] ?? ''),
                    'local_id' => $local['id'] ?? null,
                    'remote_id' => $cloud['id'] ?? null,
                    'status' => match (true) {
                        $local === null => 'remote_only',
                        $cloud === null => 'local_only',
                        $local['id'] === $cloud['id'] => 'match',
                        default => 'id_differs',
                    },
                ];
            }
        }

        return $rows;
    }

    /**
     * Read-only: how many patients and samples point at a location id that exists on one side only,
     * and which patients sit in a different location than the cloud has them.
     *
     * @param  array<string, array<string, array>>  $remoteRefs  from SyncService::fetchRemoteReferences
     * @return array{counts: array<string,int>, moved_patients: array<int, array>}
     */
    public function impact(array $remote, array $remoteRefs): array
    {
        $remoteIds = [];
        foreach (self::TABLES as $table) {
            $remoteIds[$table] = array_flip(array_column($remote[$table] ?? [], 'id'));
        }

        $localIds = [];
        foreach (self::TABLES as $table) {
            $localIds[$table] = DB::table($table)->pluck('id')->flip()->all();
        }

        $counts = [
            'local_patients' => DB::table('patients')->count(),
            'local_patients_unknown_to_cloud' => 0,
            'remote_patients' => count($remoteRefs['patients'] ?? []),
            'remote_patients_unknown_locally' => 0,
            'local_samples' => DB::table('sample_collections')->count(),
            'local_samples_unknown_to_cloud' => 0,
            'remote_samples' => count($remoteRefs['sample_collections'] ?? []),
            'remote_samples_unknown_locally' => 0,
        ];

        $columns = ['state_id' => 'states', 'county_id' => 'counties', 'facility_id' => 'facilities'];

        foreach (DB::table('patients')->select('state_id', 'county_id', 'facility_id')->get() as $patient) {
            foreach ($columns as $column => $table) {
                if (! isset($remoteIds[$table][$patient->{$column}])) {
                    $counts['local_patients_unknown_to_cloud']++;
                    break;
                }
            }
        }
        foreach ($remoteRefs['patients'] ?? [] as $patient) {
            foreach ($columns as $column => $table) {
                if (! isset($localIds[$table][$patient[$column] ?? ''])) {
                    $counts['remote_patients_unknown_locally']++;
                    break;
                }
            }
        }
        foreach (DB::table('sample_collections')->whereNotNull('facility_id')->pluck('facility_id') as $facilityId) {
            if (! isset($remoteIds['facilities'][$facilityId])) {
                $counts['local_samples_unknown_to_cloud']++;
            }
        }
        foreach ($remoteRefs['sample_collections'] ?? [] as $sample) {
            if (! empty($sample['facility_id']) && ! isset($localIds['facilities'][$sample['facility_id']])) {
                $counts['remote_samples_unknown_locally']++;
            }
        }

        $moved = $this->patientRestorePlan($remote, $remoteRefs['patients'] ?? []);
        $counts['patients_in_different_location_than_cloud'] = count($moved);

        return ['counts' => $counts, 'moved_patients' => $moved];
    }

    /**
     * Read-only: local patients whose state/county/facility (compared by business key, so it works
     * before and after id adoption) differs from the cloud's copy of the same patient.
     *
     * @param  array<string, array>  $remotePatients  id => [state_id, county_id, facility_id]
     * @return array<int, array{id: string, from: array<string,?string>, to: array<string,?string>, to_ids: array<string,string>}>
     */
    public function patientRestorePlan(array $remote, array $remotePatients): array
    {
        $localKeyById = $this->idToKey($this->localTables());
        $remoteKeyById = $this->idToKey($remote);
        $columns = ['state_id' => 'states', 'county_id' => 'counties', 'facility_id' => 'facilities'];
        $plan = [];

        foreach (DB::table('patients')->select('id', 'state_id', 'county_id', 'facility_id')->get() as $patient) {
            $cloud = $remotePatients[$patient->id] ?? null;
            if ($cloud === null) {
                continue;
            }

            $from = [];
            $to = [];
            $toIds = [];
            $differs = false;
            foreach ($columns as $column => $table) {
                $from[$column] = $localKeyById[$table][$patient->{$column}] ?? null;
                $to[$column] = $remoteKeyById[$table][$cloud[$column] ?? ''] ?? null;
                $toIds[$column] = (string) ($cloud[$column] ?? '');
                $differs = $differs || $from[$column] !== $to[$column];
            }

            if ($differs && ! in_array(null, $to, true)) {
                $plan[] = ['id' => $patient->id, 'from' => $from, 'to' => $to, 'to_ids' => $toIds];
            }
        }

        return $plan;
    }

    /**
     * Restores the cloud's state/county/facility on the patients in a restore plan. Run it after
     * the location ids have been reconciled. Uses the query builder, so sync status and
     * timestamps stay as they are (the cloud already has these values).
     *
     * @return array{restored: int, unresolved: int}
     */
    public function restorePatientLocations(array $plan): array
    {
        $restored = 0;
        $unresolved = 0;
        $tables = ['state_id' => 'states', 'county_id' => 'counties', 'facility_id' => 'facilities'];

        foreach ($plan as $entry) {
            $present = true;
            foreach ($tables as $column => $table) {
                $present = $present && DB::table($table)->where('id', $entry['to_ids'][$column])->exists();
            }

            if (! $present) {
                $unresolved++;

                continue;
            }

            DB::table('patients')->where('id', $entry['id'])->update($entry['to_ids']);
            $restored++;
        }

        return ['restored' => $restored, 'unresolved' => $unresolved];
    }

    /** @return array<string, array<int, array>> */
    private function localTables(): array
    {
        $tables = [];
        foreach (self::TABLES as $table) {
            $tables[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }

        return $tables;
    }

    /** Business key of each row: state code; county "stateCode/code-or-name"; facility code. */
    private function keyedRows(array $tables): array
    {
        $stateCodes = array_column($tables['states'] ?? [], 'code', 'id');
        $keyed = ['states' => [], 'counties' => [], 'facilities' => []];

        foreach ($tables['states'] ?? [] as $row) {
            $keyed['states'][(string) $row['code']] = $row;
        }
        foreach ($tables['counties'] ?? [] as $row) {
            $keyed['counties'][($stateCodes[$row['state_id']] ?? '?').'/'.($row['code'] ?: $row['name'])] = $row;
        }
        foreach ($tables['facilities'] ?? [] as $row) {
            $keyed['facilities'][(string) $row['code']] = $row;
        }

        return $keyed;
    }

    /** @return array<string, array<string,string>> table => id => business key */
    private function idToKey(array $tables): array
    {
        $map = [];
        foreach ($this->keyedRows($tables) as $table => $rows) {
            foreach ($rows as $key => $row) {
                $map[$table][$row['id']] = $key;
            }
        }

        return $map + ['states' => [], 'counties' => [], 'facilities' => []];
    }

    /** Attributes to write when remote and local differ (remote wins). */
    private function diff(string $table, array $local, array $remote): array
    {
        $changes = [];
        foreach ($this->dataColumns($table, $remote) as $column) {
            if ($this->norm($local[$column] ?? null) !== $this->norm($remote[$column])) {
                $changes[$column] = $remote[$column];
            }
        }

        // A matching row that is still flagged pending is settled by the cloud copy.
        if ($changes === [] && ($local['sync_status'] ?? 'synced') !== 'synced') {
            $changes['sync_status'] = 'synced';
        }

        return $changes;
    }

    private function dataColumns(string $table, array $remote): array
    {
        $local = Schema::getColumnListing($table);

        return array_values(array_filter(
            array_keys($remote),
            fn ($column) => in_array($column, $local, true) && ! in_array($column, self::SYSTEM_COLUMNS, true)
        ));
    }

    private function syncColumns(string $table, array $remote): array
    {
        $cols = ['sync_status' => 'synced'];
        if (Schema::hasColumn($table, 'last_modified_at')) {
            $cols['last_modified_at'] = $remote['last_modified_at'] ?? now();
        }

        return $cols;
    }

    private function insertRow(string $table, array $remote): array
    {
        $row = ['id' => $remote['id']];
        foreach ($this->dataColumns($table, $remote) as $column) {
            $row[$column] = $remote[$column];
        }
        if (Schema::hasColumn($table, 'created_at')) {
            $row['created_at'] = $remote['created_at'] ?? now();
            $row['updated_at'] = $remote['updated_at'] ?? now();
        }

        return $row + $this->syncColumns($table, $remote);
    }

    private function norm(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_bool($value) ? (string) (int) $value : (string) $value;
    }
}
