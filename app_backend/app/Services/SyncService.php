<?php

namespace App\Services;

use App\Enums\SyncStatus;
use App\Exceptions\ClientVersionTooOldException;
use App\Exceptions\UnresolvedLocationException;
use App\Models\AuditLog;
use App\Models\NotificationLog;
use App\Models\SyncConflict;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SyncService
{
    protected SyncStateStore $state;
    protected string $remoteUrl;
    protected string $apiToken;
    protected string $deviceId;
    protected int $batchSize;
    protected array $syncedModels;
    protected array $pullOnlyTables;

    /**
     * Business-unique (natural) keys for models that define domain uniqueness
     * beyond primary key UUIDs. Used to reconcile independent creations or seeds.
     */
    protected array $businessUniqueKeys = [
        'users' => ['email'],
        'patients' => ['art_number'],
        'sample_collections' => ['sample_code'],
    ];

    /**
     * Map of table columns that reference user UUIDs, enabling seamless FK remapping.
     */
    protected array $userForeignKeyColumns = [
        'patients' => ['created_by'],
        'regimen_histories' => ['recorded_by'],
        'viral_load_results' => ['requesting_clinician_id', 'recorded_by'],
        'eac_episodes' => [],
        'eac_sessions' => ['counselor_id'],
        'sample_collections' => ['collector_id', 'received_by'],
        'sample_rejections' => ['rejected_by'],
        'appointments' => ['scheduled_by', 'attended_by'],
        'audit_logs' => ['user_id'],
        'sync_conflicts' => ['resolved_by'],
    ];

    public function __construct()
    {
        $this->state = new SyncStateStore();
        $this->remoteUrl = rtrim((string) config('sync.remote_url', ''), '/');
        $this->apiToken = (string) config('sync.api_token', '');
        $this->deviceId = config('sync.device_id', 'clinic-desktop-01');
        $this->batchSize = (int) config('sync.batch_size', 100);
        $this->syncedModels = config('sync.synced_models', [
            'states' => \App\Models\State::class,
            'counties' => \App\Models\County::class,
            'facilities' => \App\Models\Facility::class,
            'testing_laboratories' => \App\Models\TestingLaboratory::class,
            'users' => \App\Models\User::class,
            'patients' => \App\Models\Patient::class,
            'regimen_histories' => \App\Models\RegimenHistory::class,
            'viral_load_results' => \App\Models\ViralLoadResult::class,
            'eac_episodes' => \App\Models\EacEpisode::class,
            'eac_sessions' => \App\Models\EacSession::class,
            'sample_collections' => \App\Models\SampleCollection::class,
            'sample_rejections' => \App\Models\SampleRejection::class,
            'appointments' => \App\Models\Appointment::class,
            'notification_logs' => \App\Models\NotificationLog::class,
            'audit_logs' => \App\Models\AuditLog::class,
        ]);
        $this->pullOnlyTables = (array) config('sync.pull_only_tables', [...LocationReconciler::TABLES, 'testing_laboratories']);
    }

    /**
     * True when this installation is the central server (VILOCARE_ROLE=central), not a clinic computer.
     */
    public function isCentral(): bool
    {
        return config('sync.role', 'clinic') === 'central';
    }

    /**
     * Server side: remembers that a clinic just talked to this central server (shown read-only in Settings).
     */
    public function recordClinicContact(mixed $deviceId): void
    {
        $this->state->put('last_clinic_sync_at', now()->toIso8601String());
        if (is_string($deviceId) && $deviceId !== '') {
            $this->state->put('last_clinic_device', mb_substr($deviceId, 0, 120));
        }
    }

    /**
     * @return array{at: ?Carbon, device: ?string}
     */
    public function lastClinicContact(): array
    {
        return ['at' => $this->state->getTime('last_clinic_sync_at'), 'device' => $this->state->get('last_clinic_device')];
    }

    /**
     * True when this clinic has its own sync URL and token (see config/sync.php 'configured').
     */
    public function isConfigured(): bool
    {
        return (bool) config('sync.configured', false);
    }

    /**
     * Checks a URL + token against the central server without saving anything.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(string $url, string $token): array
    {
        $url = rtrim(trim($url), '/');
        try {
            $response = Http::withHeaders($this->clientHeaders($token))
                ->withOptions($this->getHttpOptions())
                ->connectTimeout(8)
                ->timeout(15)
                ->get("{$url}/counts");
        } catch (\Throwable $e) {
            $friendly = self::formatConnectionError($e->getMessage());

            return ['ok' => false, 'message' => $friendly['friendly_message'] . ' ' . $friendly['action_hint']];
        }

        if ($response->successful()) {
            return ['ok' => true, 'message' => 'Connected. The central server accepted the sync token.'];
        }

        return ['ok' => false, 'message' => match (true) {
            $response->status() === 426 => 'This version of ViLoCare is out of date. Update the app, then try again.',
            in_array($response->status(), [401, 403], true) => 'The server was reached but rejected the token. Check the token and try again.',
            $response->status() === 404 => 'The server was reached but this address is not a ViLoCare sync address. Check the URL (it normally ends in /api/v1/sync).',
            $response->serverError() => 'The central server reported a problem (HTTP ' . $response->status() . '). Try again in a few minutes.',
            default => 'The server answered with an unexpected response (HTTP ' . $response->status() . ').',
        }];
    }

    /**
     * Headers for every request to the central server. X-Client-Version lets the server refuse versions below
     * VILOCARE_MIN_CLIENT_VERSION; it is omitted when this is not the desktop app (no version known).
     */
    protected function clientHeaders(?string $token = null): array
    {
        $headers = ['X-Sync-Token' => $token ?? $this->apiToken, 'Accept' => 'application/json'];
        if ($version = config('sync.client_version')) {
            $headers['X-Client-Version'] = (string) $version;
        }

        return $headers;
    }

    /**
     * Whether an app update is required: the central server answered 426 and this app is still below the minimum.
     *
     * @return array{minimum: ?string, at: ?string}|null
     */
    public function updateRequired(): ?array
    {
        $info = $this->state->getJson('update_required');
        if ($info === null) {
            return null;
        }
        $minimum = $info['minimum'] ?? null;
        $mine = config('sync.client_version');
        if ($minimum && $mine && version_compare(ltrim((string) $mine, 'vV'), ltrim((string) $minimum, 'vV'), '>=')) {
            $this->state->forget('update_required'); // the app was updated since the 426

            return null;
        }

        return ['minimum' => $minimum, 'at' => $info['at'] ?? null];
    }

    /**
     * Desktop shell: keep new syncs from starting while an app update is being installed. The shell sets the hold,
     * waits until no sync is running, then quits; it releases the hold if the install is skipped. Expires on its own.
     */
    public function setUpdateHold(bool $on): void
    {
        $on
            ? $this->state->put('update_hold_until', now()->addMinutes((int) config('sync.update_hold_minutes', 15))->toIso8601String())
            : $this->state->forget('update_hold_until');
    }

    public function updateHoldActive(): bool
    {
        $until = $this->state->getTime('update_hold_until');

        return $until !== null && $until->isFuture();
    }

    public function isSyncRunning(): bool
    {
        return $this->runStatus()['running'];
    }

    protected function isPullOnly(string $tableName): bool
    {
        return in_array($tableName, $this->pullOnlyTables, true);
    }

    /**
     * Get SSL and cURL transport options for outbound sync HTTP requests.
     * Prevents Windows cURL error 60 without requiring system php.ini configuration.
     */
    public function getHttpOptions(): array
    {
        $sslVerify = (bool) config('sync.ssl_verify', true);
        if (!$sslVerify) {
            return ['verify' => false];
        }

        $caBundle = config('sync.ca_bundle', base_path('certs/cacert.pem'));
        if (!empty($caBundle) && file_exists($caBundle)) {
            return ['verify' => $caBundle];
        }

        $defaultBundle = base_path('certs/cacert.pem');
        if (file_exists($defaultBundle)) {
            return ['verify' => $defaultBundle];
        }

        return ['verify' => true];
    }

    /**
     * Translates low-level network and cURL transport errors into friendly, non-technical diagnostics.
     */
    public static function formatConnectionError(string $rawError): array
    {
        $lower = strtolower($rawError);

        // SSL / Certificate issues (e.g. cURL error 60, OpenSSL verify result 20)
        if (str_contains($lower, 'curl error 60') 
            || str_contains($lower, 'ssl certificate') 
            || str_contains($lower, 'local issuer certificate')
            || str_contains($lower, 'certificate verify failed')) {
            return [
                'type' => 'ssl_certificate',
                'title' => 'SSL Security Handshake Notice',
                'friendly_message' => 'Windows SSL certificate verification failed when connecting to Render (cURL Error 60). The bundled CA certificate has been applied to resolve this.',
                'action_hint' => 'Offline clinic data is 100% safe in local SQLite. Records will sync automatically once the secure handshake succeeds.',
                'raw_error' => $rawError,
            ];
        }

        // Host not found / DNS / Offline
        if (str_contains($lower, 'curl error 6') 
            || str_contains($lower, 'could not resolve host')
            || str_contains($lower, 'name or service not known')) {
            return [
                'type' => 'network_offline',
                'title' => 'Clinic Offline (No Internet / DNS)',
                'friendly_message' => 'The clinic workstation cannot resolve the cloud address. Internet connectivity may be down at the clinic.',
                'action_hint' => 'All patient records and clinical visits are safely stored offline and will automatically sync when connectivity returns.',
                'raw_error' => $rawError,
            ];
        }

        // Connection refused / Network unreachable
        if (str_contains($lower, 'curl error 7') 
            || str_contains($lower, 'failed to connect')
            || str_contains($lower, 'connection refused')
            || str_contains($lower, 'network is unreachable')) {
            return [
                'type' => 'network_offline',
                'title' => 'Central Cloud Server Unreachable',
                'friendly_message' => 'Unable to establish a connection to the central server on Render. The server may be restarting or the clinic network is offline.',
                'action_hint' => 'Local clinic operations continue uninterrupted. Your data is queued and will sync when the connection is restored.',
                'raw_error' => $rawError,
            ];
        }

        // Timeout
        if (str_contains($lower, 'curl error 28') 
            || str_contains($lower, 'timed out')
            || str_contains($lower, 'timeout')) {
            return [
                'type' => 'timeout',
                'title' => 'Cloud Sync Request Timed Out',
                'friendly_message' => 'The cloud server took longer than 20 seconds to respond, likely due to a slow or fluctuating mobile network connection.',
                'action_hint' => 'All pending local changes are preserved and will retry on the next sync cycle.',
                'raw_error' => $rawError,
            ];
        }

        // Auth token failure
        if (str_contains($lower, '401') || str_contains($lower, 'unauthorized')) {
            return [
                'type' => 'auth_error',
                'title' => 'Sync Authentication Required',
                'friendly_message' => 'The sync security token was rejected by the cloud server. Please check the clinic device credentials.',
                'action_hint' => 'Local records remain safely stored on this computer.',
                'raw_error' => $rawError,
            ];
        }

        // Unique violation / Duplicate key / Database constraint conflict
        if (str_contains($lower, 'unique violation') 
            || str_contains($lower, '23505') 
            || str_contains($lower, 'duplicate key') 
            || str_contains($lower, 'unique constraint')) {
            return [
                'type' => 'duplicate_key_conflict',
                'title' => 'Duplicate Record Conflict Detected',
                'friendly_message' => 'A record with matching unique information (such as an existing staff email or patient ART number) already exists in the central system. The sync engine has safeguarded your local records.',
                'action_hint' => 'Offline clinic data is safely stored in SQLite. Clinical staff can continue working normally; any discrepancies are logged in Conflict History.',
                'raw_error' => $rawError,
            ];
        }

        // Foreign key violation
        if (str_contains($lower, 'foreign key violation') 
            || str_contains($lower, '23503') 
            || str_contains($lower, 'violates foreign key constraint')) {
            return [
                'type' => 'foreign_key_conflict',
                'title' => 'Referential Dependency Notice',
                'friendly_message' => 'A record references related data that has not yet been synchronized to the central cloud. Your local records remain intact and will reconcile automatically.',
                'action_hint' => 'Local clinic data is preserved. The sync engine will retry dependency reconciliation on the next cycle.',
                'raw_error' => $rawError,
            ];
        }

        // HTTP 500 / General Server error / SQLSTATE
        if (str_contains($lower, '500') || str_contains($lower, 'server error') || str_contains($lower, 'sqlstate')) {
            return [
                'type' => 'server_error',
                'title' => 'Central Server Processing Notice',
                'friendly_message' => 'The central cloud server encountered a database processing issue while receiving data. Local clinic records are fully preserved and safe.',
                'action_hint' => 'Offline clinic data is safely stored in the local SQLite database. No records were lost.',
                'raw_error' => $rawError,
            ];
        }

        // General fallback - clean raw SQL / hostnames from user friendly message
        $displayMsg = 'The sync cycle could not be completed at this time.';
        if (!str_contains($lower, 'sqlstate') && !str_contains($lower, 'select') && !str_contains($lower, 'insert') && !str_contains($lower, 'update') && !str_contains($lower, 'dpg-')) {
            $displayMsg .= ' ' . rtrim($rawError, '.');
        }

        return [
            'type' => 'general_error',
            'title' => 'Sync Engine Notice',
            'friendly_message' => $displayMsg,
            'action_hint' => 'Local clinic data is safely stored in the offline SQLite database.',
            'raw_error' => $rawError,
        ];
    }


    /**
     * Gathers pending local records, one dependency-safe page at a time.
     *
     * Tables are listed in dependency order; as soon as a table fills a whole batch the remaining
     * (child) tables are left empty for this page, so a child is never sent before its parents.
     * Call repeatedly (with `$excludeIds` for rows the server refused) until total_records is 0.
     *
     * @param array<string, array<int, string>> $excludeIds ids to skip per table
     */
    public function getPendingLocalPayload(array $excludeIds = []): array
    {
        $payload = [];
        $totalPending = 0;
        $pageFull = false;

        foreach ($this->syncedModels as $tableName => $modelClass) {
            if (!class_exists($modelClass)) {
                continue;
            }

            /** @var Model $modelInstance */
            $modelInstance = new $modelClass;

            if (!Schema::hasColumn($modelInstance->getTable(), 'sync_status')) {
                continue;
            }

            $payload[$tableName] = [];

            // Reference data is pull-only: clinics never push locations to the cloud.
            if ($this->isPullOnly($tableName)) {
                continue;
            }

            if ($pageFull) {
                continue;
            }

            $query = $modelClass::where('sync_status', SyncStatus::PENDING->value);
            if (!empty($excludeIds[$tableName])) {
                $query->whereNotIn('id', $excludeIds[$tableName]);
            }

            $records = $query->orderBy('last_modified_at', 'asc')
                ->orderBy('id', 'asc')
                ->limit($this->batchSize)
                ->get();

            $payload[$tableName] = $records->map(fn ($record) => $record->getAttributes())->toArray();
            $totalPending += $records->count();

            if ($records->count() >= $this->batchSize) {
                $pageFull = true;
            }
        }

        return [
            'device_id' => $this->deviceId,
            'timestamp' => now()->toIso8601String(),
            'total_records' => $totalPending,
            'tables' => $payload,
        ];
    }

    /**
     * Find an existing record by natural business key (e.g. email for users, art_number for patients).
     */
    protected function findExistingByBusinessKey(string $tableName, string $modelClass, array $recordData): ?Model
    {
        if (!isset($this->businessUniqueKeys[$tableName])) {
            return null;
        }

        $query = $modelClass::query();
        $hasKey = false;
        foreach ($this->businessUniqueKeys[$tableName] as $key) {
            if (!empty($recordData[$key])) {
                $query->where($key, $recordData[$key]);
                $hasKey = true;
            }
        }

        return $hasKey ? $query->first() : null;
    }

    /**
     * Re-maps and sanitizes foreign keys (users, locations, patients) to ensure referential integrity.
     */
    protected function remapForeignKeys(string $tableName, array $recordData, array $idMappings): array
    {
        $data = $recordData;

        // 1. Remap user foreign keys
        $userCols = $this->userForeignKeyColumns[$tableName] ?? [];
        foreach ($userCols as $col) {
            if (!empty($data[$col])) {
                $rawVal = $data[$col];
                if (isset($idMappings['users'][$rawVal])) {
                    $data[$col] = $idMappings['users'][$rawVal];
                } elseif (!\App\Models\User::where('id', $rawVal)->exists()) {
                    // Referenced user does not exist on this database
                    // Set to null to prevent foreign key constraint violation
                    $data[$col] = null;
                }
            }
        }

        // 2. Remap patient foreign key in child clinical tables
        if (isset($data['patient_id']) && isset($idMappings['patients'][$data['patient_id']])) {
            $data['patient_id'] = $idMappings['patients'][$data['patient_id']];
        }

        // 3. Location foreign keys. A location is never guessed: if it cannot be resolved the
        //    record is rejected (and flagged for manual review by the caller), not moved elsewhere.
        if ($tableName === 'patients') {
            foreach (['state_id' => 'states', 'county_id' => 'counties', 'facility_id' => 'facilities'] as $column => $locationTable) {
                if (!empty($data[$column])) {
                    $data[$column] = $this->resolveLocationId($locationTable, (string) $data[$column], $idMappings)
                        ?? throw new UnresolvedLocationException(
                            "Patient references {$column} {$data[$column]} which does not exist in this database's location list. "
                            . 'The patient was NOT moved to another location. Run location reconciliation (vilocare:reconcile-locations), then sync again.'
                        );
                }
            }
        }

        if ($tableName === 'sample_collections') {
            $facilityId = !empty($data['facility_id'])
                ? $this->resolveLocationId('facilities', (string) $data['facility_id'], $idMappings)
                : null;

            if ($facilityId === null && !empty($data['health_facility_code'])) {
                $facilityId = DB::table('facilities')->where('code', $data['health_facility_code'])->value('id');
            }

            if ($facilityId === null && (!empty($data['facility_id']) || !empty($data['health_facility_code']))) {
                throw new UnresolvedLocationException(
                    'Sample references facility ' . ($data['health_facility_code'] ?? $data['facility_id'])
                    . " which does not exist in this database's location list. The sample was NOT moved to another facility. "
                    . 'Run location reconciliation (vilocare:reconcile-locations), then sync again.'
                );
            }

            $data['facility_id'] = $facilityId;
        }

        return $data;
    }

    /**
     * Returns the local id for a location id that may be stale, or null when it cannot be resolved.
     */
    protected function resolveLocationId(string $table, string $id, array $idMappings): ?string
    {
        $id = $idMappings[$table][$id] ?? $id;

        return DB::table($table)->where('id', $id)->exists() ? $id : null;
    }

    /**
     * Server-side processing of client pushed records with Last-Write-Wins (LWW) conflict resolution,
     * natural business-key matching, and graceful conflict logging.
     */
    public function processServerPush(array $payload, string $deviceId): array
    {
        $tables = $payload['tables'] ?? [];
        $syncedIds = [];
        $conflicts = [];
        $serverWinningRecords = [];
        $idMappings = ['users' => [], 'patients' => []];

        // Pre-build ID mappings from incoming records that match business keys
        foreach (['users', 'patients'] as $keyTable) {
            $modelClass = $this->syncedModels[$keyTable] ?? null;
            if ($modelClass && !empty($tables[$keyTable])) {
                foreach ($tables[$keyTable] as $rec) {
                    $incomingId = $rec['id'] ?? null;
                    if (!$incomingId) {
                        continue;
                    }
                    if (!$modelClass::find($incomingId)) {
                        $matched = $this->findExistingByBusinessKey($keyTable, $modelClass, $rec);
                        if ($matched) {
                            $idMappings[$keyTable][$incomingId] = $matched->id;
                        }
                    }
                }
            }
        }

        DB::beginTransaction();
        try {
            foreach ($this->syncedModels as $tableName => $modelClass) {
                $incomingRecords = $tables[$tableName] ?? [];
                $syncedIds[$tableName] = [];
                $conflicts[$tableName] = [];
                $serverWinningRecords[$tableName] = [];

                // Pull-only reference data is never accepted from a client.
                if (empty($incomingRecords) || $this->isPullOnly($tableName)) {
                    continue;
                }

                foreach ($incomingRecords as $recordData) {
                    $recordId = $recordData['id'] ?? null;
                    if (!$recordId) {
                        continue;
                    }

                    try {
                        // Savepoint per record: a bad row must not poison the whole batch
                        // (PostgreSQL fails every later statement after one error).
                        $serverWinner = DB::transaction(function () use ($tableName, $modelClass, $recordId, $recordData, &$idMappings) {
                            return $this->applyPushedRecord($tableName, $modelClass, (string) $recordId, $recordData, $idMappings);
                        });

                        $syncedIds[$tableName][] = $recordId;

                        if ($serverWinner !== null) {
                            $conflicts[$tableName][] = $recordId;
                            $serverWinningRecords[$tableName][] = $serverWinner;
                        }
                    } catch (\Throwable $recordEx) {
                        Log::warning("Sync push single record error for {$tableName} [{$recordId}]: " . $recordEx->getMessage());

                        $this->recordConflict(
                            $tableName,
                            (string) $recordId,
                            $recordData,
                            null,
                            'flagged_for_manual_review',
                            'Database constraint error during sync push: ' . $recordEx->getMessage(),
                            false
                        );

                        $conflicts[$tableName][] = $recordId;
                    }
                }
            }

            DB::commit();

            return [
                'success' => true,
                'status' => 'processed',
                'synced_ids' => $syncedIds,
                'conflicts' => $conflicts,
                'server_updates' => $serverWinningRecords,
                'server_time' => now()->toIso8601String(),
            ];
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Server Sync Push Error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            throw $e;
        }
    }

    /**
     * Applies one pushed record on the server. Returns null when the client copy was stored,
     * or the server's attributes when the server copy was newer and therefore wins.
     *
     * `updated_at` is always stamped with the server's receipt time. Pull cursors key on it, so a
     * record that arrives late (old last_modified_at, other device, clock skew) is still delivered.
     */
    protected function applyPushedRecord(string $tableName, string $modelClass, string $recordId, array $recordData, array &$idMappings): ?array
    {
        $incomingLastModified = isset($recordData['last_modified_at'])
            ? Carbon::parse($recordData['last_modified_at'])
            : now();

        $existingRecord = $modelClass::find($recordId);
        $matchedByBusinessKey = false;

        if (!$existingRecord) {
            $existingRecord = $this->findExistingByBusinessKey($tableName, $modelClass, $recordData);
            if ($existingRecord) {
                $matchedByBusinessKey = true;
                $idMappings[$tableName][$recordId] = $existingRecord->id;
            }
        }

        if (!$existingRecord) {
            // Brand new record created offline -> insert on server
            $insertData = $this->remapForeignKeys($tableName, $recordData, $idMappings);
            $insertData['sync_status'] = SyncStatus::SYNCED->value;
            $insertData['last_modified_at'] = $incomingLastModified;
            $insertData['updated_at'] = now();

            $newModel = new $modelClass;
            $newModel->forceFill($insertData);
            $newModel->saveQuietly();

            return null;
        }

        // Existing record (found either by ID or business key) -> Last-Write-Wins (LWW) resolution
        $existingLastModified = $existingRecord->last_modified_at
            ? Carbon::parse($existingRecord->last_modified_at)
            : Carbon::parse($existingRecord->updated_at);

        if ($incomingLastModified->greaterThanOrEqualTo($existingLastModified)) {
            // Incoming client record is newer or equal -> Client wins!
            $updateData = $this->remapForeignKeys($tableName, $recordData, $idMappings);
            unset($updateData['id']); // Preserve canonical server primary key!
            $updateData['sync_status'] = SyncStatus::SYNCED->value;
            $updateData['last_modified_at'] = $incomingLastModified;
            $updateData['updated_at'] = now();

            $existingRecord->forceFill($updateData);
            $existingRecord->saveQuietly();

            if ($matchedByBusinessKey) {
                $this->recordConflict(
                    $tableName,
                    $recordId,
                    $recordData,
                    $existingRecord->getAttributes(),
                    'business_key_reconciled',
                    "Natural business key matched: Client record ({$recordId}) reconciled with server record ({$existingRecord->id}). Client updates applied.",
                    true
                );
            }

            return null;
        }

        // Server record is newer -> Server wins! (acknowledged so the client adopts the server copy)
        $this->recordConflict(
            $tableName,
            $recordId,
            $recordData,
            $existingRecord->getAttributes(),
            'last_write_wins',
            "LWW Auto-Resolution: Server copy ({$existingLastModified->toIso8601String()}) was newer than incoming client copy ({$incomingLastModified->toIso8601String()}).",
            true
        );

        return $existingRecord->getAttributes();
    }

    /**
     * Server-side processing for pull requests.
     *
     * Records are ordered by (updated_at, id) - the server's own write clock - and paged with a
     * keyset cursor, so the client can keep pulling until `next` is null. Tables are emitted in
     * dependency order and a page stops at the first table that still has more rows, so a child
     * row is never delivered before the parents it references.
     *
     * Backward compatible: an older client that sends only `since` simply receives the first page.
     *
     * @param array|null $resume ['table' => ..., 'updated_at' => ..., 'id' => ...] continuation point
     */
    public function processServerPull(?string $since = null, ?array $resume = null): array
    {
        $sinceDateTime = $this->normalizeSince($since);

        $resumeTable = $resume['table'] ?? null;
        $started = $resumeTable === null || !isset($this->syncedModels[$resumeTable]);
        $stamp = 'COALESCE(updated_at, last_modified_at, created_at)';

        $tablesData = [];
        $totalRecords = 0;
        $next = null;

        foreach ($this->syncedModels as $tableName => $modelClass) {
            if (!class_exists($modelClass)) {
                continue;
            }

            if (!$started) {
                if ($tableName !== $resumeTable) {
                    continue;
                }
                $started = true;
            }

            $query = $modelClass::query();

            if ($sinceDateTime) {
                $query->whereRaw("{$stamp} > ?", [$sinceDateTime]);
            }

            if ($tableName === $resumeTable && !empty($resume['updated_at']) && !empty($resume['id'])) {
                $query->where(function ($q) use ($stamp, $resume) {
                    $q->whereRaw("{$stamp} > ?", [$resume['updated_at']])
                        ->orWhere(function ($q2) use ($stamp, $resume) {
                            $q2->whereRaw("{$stamp} = ?", [$resume['updated_at']])
                                ->where('id', '>', $resume['id']);
                        });
                });
            }

            $records = $query->orderByRaw($stamp)->orderBy('id')->limit($this->batchSize + 1)->get();
            $hasMore = $records->count() > $this->batchSize;
            if ($hasMore) {
                $records = $records->take($this->batchSize);
            }

            $tablesData[$tableName] = $records->map(fn ($r) => $r->getAttributes())->values()->toArray();
            $totalRecords += $records->count();

            if ($hasMore) {
                $last = $records->last();
                $next = [
                    'table' => $tableName,
                    'updated_at' => $last->getRawOriginal('updated_at')
                        ?? $last->getRawOriginal('last_modified_at')
                        ?? $last->getRawOriginal('created_at'),
                    'id' => $last->getKey(),
                ];
                break;
            }
        }

        return [
            'success' => true,
            'since' => $sinceDateTime,
            'server_time' => now()->toIso8601String(),
            'total_records' => $totalRecords,
            'tables' => $tablesData,
            'has_more' => $next !== null,
            'next' => $next,
        ];
    }

    /**
     * Normalizes a client-supplied cursor into the server's (UTC) database time.
     */
    protected function normalizeSince(?string $since): ?string
    {
        if (!$since) {
            return null;
        }

        // A "+" in a query string arrives as a space; restore the UTC offset sign.
        $cleaned = preg_replace('/(\d{2}:\d{2}:\d{2})\s(\d{2}:\d{2})$/', '$1+$2', trim($since));

        return Carbon::parse($cleaned)->setTimezone(config('app.timezone'))->toDateTimeString();
    }

    /**
     * Client-side: Apply remote pull data into the local database with natural key matching,
     * foreign key remapping and Last-Write-Wins. Idempotent: applying the same page twice
     * changes nothing the second time. A bad record is isolated in its own savepoint, flagged
     * for manual review and counted - it never aborts or silently drops the rest of the page.
     */
    public function applyRemotePull(array $tablesData): array
    {
        $appliedCount = 0;
        $unchangedCount = 0;
        $conflictsCount = 0;
        $failedCount = 0;
        $errors = [];
        $idMappings = $this->loadIdMappings();
        $mappingsBefore = $idMappings;

        DB::beginTransaction();
        try {
            foreach ($this->syncedModels as $tableName => $modelClass) {
                foreach ($tablesData[$tableName] ?? [] as $remoteData) {
                    $recordId = $remoteData['id'] ?? null;
                    if (!$recordId) {
                        continue;
                    }

                    try {
                        $outcome = DB::transaction(function () use ($tableName, $modelClass, $remoteData, &$idMappings) {
                            return $this->applyPulledRecord($tableName, $modelClass, $remoteData, $idMappings);
                        });

                        $this->resolveFlaggedConflicts($tableName, (string) $recordId);

                        if ($outcome === 'applied') {
                            $appliedCount++;
                        } elseif ($outcome === 'local_wins') {
                            $conflictsCount++;
                        } else {
                            $unchangedCount++;
                        }
                    } catch (\Throwable $pullEx) {
                        Log::warning("Apply remote pull single record error for {$tableName} [{$recordId}]: " . $pullEx->getMessage());

                        $failedCount++;
                        if (count($errors) < 10) {
                            $errors[] = ['table' => $tableName, 'message' => Str::limit($pullEx->getMessage(), 240)];
                        }

                        $this->recordConflict(
                            $tableName,
                            (string) $recordId,
                            null,
                            $remoteData,
                            'flagged_for_manual_review',
                            'Constraint error while applying pulled remote record: ' . $pullEx->getMessage(),
                            false
                        );
                    }
                }
            }

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Apply Remote Pull Error: ' . $e->getMessage());
            throw $e;
        }

        if ($idMappings !== $mappingsBefore) {
            $this->state->putJson('id_mappings', $idMappings);
        }

        return [
            'applied' => $appliedCount,
            'unchanged' => $unchangedCount,
            'conflicts' => $conflictsCount,
            'failed' => $failedCount,
            'errors' => $errors,
        ];
    }

    /**
     * Applies one pulled record. Returns 'applied', 'unchanged' or 'local_wins'.
     */
    protected function applyPulledRecord(string $tableName, string $modelClass, array $remoteData, array &$idMappings): string
    {
        $recordId = $remoteData['id'];

        // Reference data: the cloud copy always wins; a business-key match adopts the remote id.
        if ($this->isPullOnly($tableName)) {
            $result = app(LocationReconciler::class)->apply($tableName, $remoteData, $idMappings);
            if ($result['action'] === 'rekeyed') {
                $this->recordConflict(
                    $tableName,
                    (string) $recordId,
                    ['id' => $result['old_id']],
                    ['id' => $recordId],
                    'business_key_reconciled',
                    "Location matched on business key; local id {$result['old_id']} replaced by cloud id {$recordId}; child records followed.",
                    true
                );
            }

            return $result['action'] === 'unchanged' ? 'unchanged' : 'applied';
        }

        $remoteLastModified = isset($remoteData['last_modified_at'])
            ? Carbon::parse($remoteData['last_modified_at'])
            : now();

        $localRecord = $modelClass::find($recordId);
        $matchedByBusinessKey = false;

        if (!$localRecord) {
            $localRecord = $this->findExistingByBusinessKey($tableName, $modelClass, $remoteData);
            if ($localRecord) {
                $matchedByBusinessKey = true;
                $idMappings[$tableName][$recordId] = $localRecord->id;
            }
        }

        $data = $this->remapForeignKeys($tableName, $remoteData, $idMappings);

        if (!$localRecord) {
            // Brand new record from server -> insert locally
            $data['sync_status'] = SyncStatus::SYNCED->value;
            $data['last_modified_at'] = $remoteLastModified;

            $model = new $modelClass;
            $model->forceFill($data);
            $model->saveQuietly();

            return 'applied';
        }

        $localLastModified = $localRecord->last_modified_at
            ? Carbon::parse($localRecord->last_modified_at)
            : Carbon::parse($localRecord->updated_at);

        if ($remoteLastModified->equalTo($localLastModified)) {
            // Identical timestamps mean identical content, so a different patient location is
            // damage from an earlier silent reassignment: the cloud copy is restored.
            if ($tableName === 'patients') {
                $restore = array_intersect_key($data, array_flip(['state_id', 'county_id', 'facility_id']));
                $current = $localRecord->getAttributes();
                if (array_diff_assoc(array_map('strval', $restore), array_map('strval', array_intersect_key($current, $restore))) !== []) {
                    DB::table('patients')->where('id', $localRecord->id)->update($restore);

                    return 'applied';
                }
            }

            return 'unchanged';
        }

        if ($remoteLastModified->greaterThanOrEqualTo($localLastModified)) {
            // Remote is newer -> update local
            $localBefore = $localRecord->getAttributes();

            unset($data['id']); // Preserve local ID if matched by business key!
            $data['sync_status'] = SyncStatus::SYNCED->value;
            $data['last_modified_at'] = $remoteLastModified;

            $localRecord->forceFill($data);
            $localRecord->saveQuietly();

            if ($matchedByBusinessKey) {
                $this->recordConflict(
                    $tableName,
                    (string) $recordId,
                    $localBefore,
                    $remoteData,
                    'business_key_reconciled',
                    "Pulled remote matched local {$tableName} on unique business key. Updated attributes from remote.",
                    true
                );
            }

            return 'applied';
        }

        // Local write is newer -> Local wins!
        $this->recordConflict(
            $tableName,
            (string) $recordId,
            $localRecord->getAttributes(),
            $remoteData,
            'last_write_wins',
            "LWW Client-Side: Local edit was newer ({$localLastModified->toIso8601String()}) than pulled remote ({$remoteLastModified->toIso8601String()}). Retained local.",
            true
        );

        return 'local_wins';
    }

    /**
     * A record that has now been applied cleanly no longer needs its earlier "flagged for manual
     * review" conflicts: close them with a note.
     */
    protected function resolveFlaggedConflicts(string $tableName, string $recordId): int
    {
        try {
            $open = SyncConflict::where('table_name', $tableName)
                ->where('record_id', $recordId)
                ->where('resolution_strategy', 'flagged_for_manual_review')
                ->whereNull('resolved_at')
                ->get();

            foreach ($open as $conflict) {
                $conflict->resolution_notes = trim($conflict->resolution_notes . "\n[Auto-resolved " . now()->toDateTimeString()
                    . ': the record was applied successfully on a later sync, after its location data was reconciled.]');
                $conflict->resolved_at = now();
                $conflict->save();
            }

            return $open->count();
        } catch (\Throwable $e) {
            Log::error("Could not auto-resolve conflicts for {$tableName} [{$recordId}]: " . $e->getMessage());

            return 0;
        }
    }

    /**
     * Re-applies the stored remote copy of every record still flagged for manual review. Records
     * that now apply cleanly have their conflicts closed automatically (see resolveFlaggedConflicts).
     *
     * @return array{retried: int, resolved: int, still_failing: int}
     */
    public function retryFlaggedConflicts(): array
    {
        $retried = 0;
        $resolved = 0;
        $failing = 0;

        $open = SyncConflict::where('resolution_strategy', 'flagged_for_manual_review')
            ->whereNull('resolved_at')
            ->get();

        foreach ($open as $conflict) {
            $payload = $conflict->remote_payload;
            if (empty($payload['id']) || !isset($this->syncedModels[$conflict->table_name])) {
                continue;
            }

            $retried++;
            $beforeOpen = SyncConflict::whereKey($conflict->getKey())->whereNull('resolved_at')->exists();
            $result = $this->applyRemotePull([$conflict->table_name => [$payload]]);
            $stillOpen = SyncConflict::whereKey($conflict->getKey())->whereNull('resolved_at')->exists();

            if ($result['failed'] === 0 && $beforeOpen && !$stillOpen) {
                $resolved++;
            } else {
                $failing++;
            }
        }

        return ['retried' => $retried, 'resolved' => $resolved, 'still_failing' => $failing];
    }

    /**
     * Writes a SyncConflict row once per distinct situation (re-pulling never piles up duplicates)
     * and never throws: payload columns are NOT NULL, so absent payloads are stored as empty arrays.
     */
    protected function recordConflict(string $tableName, string $recordId, ?array $local, ?array $remote, string $strategy, string $notes, bool $resolved): void
    {
        try {
            SyncConflict::firstOrCreate(
                [
                    'table_name' => $tableName,
                    'record_id' => $recordId,
                    'resolution_strategy' => $strategy,
                    'resolution_notes' => $notes,
                ],
                [
                    'local_payload' => $local ?? [],
                    'remote_payload' => $remote ?? [],
                    'resolved_at' => $resolved ? now() : null,
                ]
            );
        } catch (\Throwable $e) {
            Log::error("Could not record sync conflict for {$tableName} [{$recordId}]: " . $e->getMessage());
        }
    }

    protected function loadIdMappings(): array
    {
        $mappings = $this->state->getJson('id_mappings') ?? [];

        return ['users' => $mappings['users'] ?? [], 'patients' => $mappings['patients'] ?? []]
            + array_diff_key($mappings, ['users' => 1, 'patients' => 1]);
    }

    /**
     * Executes a full synchronization cycle (Push pending -> Pull remote updates -> Flush SMS queue).
     *
     * @param bool $full Ignore the stored pull cursor and re-pull everything (safe: pulling is idempotent).
     */
    public function runFullSync(?string $overrideRemoteUrl = null, bool $full = false): array
    {
        // The central server is what clinics sync to; it never runs the outbound sync itself.
        if ($this->isCentral()) {
            return [
                'success' => true,
                'skipped' => true,
                'pushed' => 0,
                'pulled' => 0,
                'conflicts' => 0,
                'failed' => 0,
                'sms_processed' => 0,
                'timestamp' => null,
                'mode' => 'central_server',
            ];
        }

        // Heartbeat: proof that the scheduler / browser timer / manual trigger really ran.
        $this->state->touchHeartbeat();

        // An app update is about to be installed: do not start a sync the installer would have to wait for.
        if ($overrideRemoteUrl === null && $this->updateHoldActive()) {
            return [
                'success' => true,
                'skipped' => true,
                'pushed' => 0,
                'pulled' => 0,
                'conflicts' => 0,
                'failed' => 0,
                'sms_processed' => 0,
                'timestamp' => $this->lastSuccessAt()?->toIso8601String(),
                'mode' => 'update_pending',
            ];
        }

        // Offline mode: the clinic has not entered a sync URL and token. Nothing is sent and every record
        // stays pending, so everything uploads once a connection is added in Settings.
        if ($overrideRemoteUrl === null && !(bool) config('sync.standalone', false) && !$this->isConfigured()) {
            return [
                'success' => true,
                'skipped' => true,
                'pushed' => 0,
                'pulled' => 0,
                'conflicts' => 0,
                'failed' => 0,
                'sms_processed' => $this->flushOfflineSmsQueue(),
                'timestamp' => $this->lastSuccessAt()?->toIso8601String(),
                'mode' => 'not_configured',
            ];
        }
        $lock = Cache::lock('vilocare:sync-run', (int) config('sync.lock_seconds', 600));
        if (!$lock->get()) {
            return [
                'success' => true,
                'skipped' => true,
                'pushed' => 0,
                'pulled' => 0,
                'conflicts' => 0,
                'failed' => 0,
                'sms_processed' => 0,
                'timestamp' => $this->lastSuccessAt()?->toIso8601String(),
                'mode' => 'already_running',
            ];
        }

        $this->state->put('run_started_at', now()->toIso8601String());
        $this->state->forget('run_finished_at');

        try {
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }

            $result = $this->executeSync(rtrim((string) ($overrideRemoteUrl ?? $this->remoteUrl), '/'), $full);
            $this->state->putJson('last_result', [
                'at' => now()->toIso8601String(),
                'success' => (bool) ($result['success'] ?? false),
                'mode' => $result['mode'] ?? null,
                'pushed' => $result['pushed'] ?? 0,
                'pulled' => $result['pulled'] ?? 0,
                'conflicts' => $result['conflicts'] ?? 0,
                'failed' => $result['failed'] ?? 0,
                'sms_processed' => $result['sms_processed'] ?? 0,
            ]);

            return $result;
        } finally {
            $this->state->put('run_finished_at', now()->toIso8601String());
            $this->state->forget('run_progress');
            $lock->release();
        }
    }

    /**
     * Records which stage a running sync is in, so the "Sync now" progress display has something to show.
     */
    protected function progress(string $phase, int $step = 0): void
    {
        $this->state->putJson('run_progress', ['phase' => $phase, 'step' => $step]);
    }

    /**
     * Marks that a background run was requested (by the "Sync now" button) and is about to start.
     */
    public function markRunRequested(): void
    {
        $this->state->put('run_requested_at', now()->toIso8601String());
    }

    /**
     * Whether a sync is running (or was requested a moment ago and is still starting), plus its stage
     * and the outcome of the last finished run. Reads only a few small state rows.
     *
     * @return array{running: bool, starting: bool, phase: ?string, step: int, started_at: ?string, last_result: ?array}
     */
    public function runStatus(): array
    {
        $started = $this->state->getTime('run_started_at');
        $finished = $this->state->getTime('run_finished_at');
        $requested = $this->state->getTime('run_requested_at');

        $inProgress = $started !== null
            && ($finished === null || $finished->lt($started))
            && $started->diffInSeconds(now(), true) < (int) config('sync.lock_seconds', 600);
        $starting = ! $inProgress && $requested !== null
            && ($started === null || $started->lt($requested))
            && $requested->diffInSeconds(now(), true) < 30;
        $progress = $inProgress ? ($this->state->getJson('run_progress') ?? []) : [];

        return [
            'running' => $inProgress || $starting,
            'starting' => $starting,
            'phase' => $progress['phase'] ?? null,
            'step' => (int) ($progress['step'] ?? 0),
            'started_at' => $started?->toIso8601String(),
            'last_result' => $this->state->getJson('last_result'),
        ];
    }

    /**
     * One-line status of the scheduler-driven sync for the header badge (no record counts, so it is cheap).
     *
     * @return array{state: string, label: string, title: string}
     */
    public function statusSummary(): array
    {
        if ($this->isCentral()) {
            return ['state' => 'neutral', 'label' => 'Central server', 'title' => 'This is the central server. Clinic computers sync to it.'];
        }
        if ((bool) config('sync.standalone', false)) {
            return ['state' => 'warning', 'label' => 'Standalone (cloud sync off)', 'title' => 'VILOCARE_SYNC_STANDALONE is on: records are not sent to the cloud.'];
        }
        if (! $this->isConfigured()) {
            return ['state' => 'offline', 'label' => 'Offline mode (sync not set up)', 'title' => 'Records are kept on this computer and upload once a sync connection is added in Settings.'];
        }

        if ($required = $this->updateRequired()) {
            return ['state' => 'warning', 'label' => 'Update required – sync paused', 'title' => 'This version is out of date'.($required['minimum'] ? " (needs {$required['minimum']} or newer)" : '').'. Records stay on this computer until the app is updated.'];
        }

        $run = $this->runStatus();
        if ($run['running']) {
            return ['state' => 'syncing', 'label' => 'Syncing…', 'title' => 'A sync is running now.'];
        }

        $heartbeat = $this->state->getTime('heartbeat_at');
        $heartbeatAge = $heartbeat ? (int) $heartbeat->diffInSeconds(now(), true) : null;
        $lastSuccess = $this->lastSuccessAt();
        $ago = fn (Carbon $t) => $t->diffInSeconds(now(), true) < 45 ? 'just now' : $t->diffForHumans(null, true).' ago';

        if ($heartbeatAge === null || $heartbeatAge > (int) config('sync.stalled_after_seconds', 180)) {
            return ['state' => 'warning', 'label' => $heartbeat ? 'Sync not running, last run '.$ago($heartbeat) : 'Waiting for first sync', 'title' => 'The background sync runs every minute. If this stays, restart ViLoCare.'];
        }

        $status = $this->state->get('last_run_status');
        $error = $this->state->getJson('last_error');
        if ($status === 'failed') {
            return ['state' => 'warning', 'label' => $error['title'] ?? 'Sync problem', 'title' => trim(($error['friendly_message'] ?? '').' '.($error['action_hint'] ?? ''))];
        }
        if ($status === 'partial') {
            return ['state' => 'warning', 'label' => 'Synced, some records need review', 'title' => $error['friendly_message'] ?? 'Open the Sync Center for details.'];
        }

        return [
            'state' => 'online',
            'label' => $lastSuccess ? 'Synced '.$ago($lastSuccess) : 'Cloud connected',
            'title' => 'Last successful sync: '.($lastSuccess?->format('Y-m-d H:i') ?? 'never'),
        ];
    }

    protected function executeSync(string $endpoint, bool $full): array
    {
        // Standalone mode must be switched on explicitly (VILOCARE_SYNC_STANDALONE=true).
        if ((bool) config('sync.standalone', false) || $endpoint === '') {
            $pushed = $this->markAllPendingAsSynced();
            $now = now()->toIso8601String();
            $this->state->put('last_success_at', $now);
            $this->state->put('last_run_status', 'ok');
            $this->state->forget('last_error');

            return [
                'success' => true,
                'pushed' => $pushed,
                'pulled' => 0,
                'conflicts' => 0,
                'failed' => 0,
                'sms_processed' => $this->flushOfflineSmsQueue(),
                'timestamp' => $now,
                'mode' => 'local_standalone',
            ];
        }

        // An address that points at this very computer would never reach the cloud. Report it
        // instead of silently pretending the sync worked.
        if ($this->isLoopbackEndpoint($endpoint)) {
            return $this->failedResult(
                'The sync address points to this computer, so nothing can reach the cloud. '
                . 'Set VILOCARE_REMOTE_SYNC_URL to the cloud address, or set VILOCARE_SYNC_STANDALONE=true to deliberately run without cloud sync.',
                [
                    'type' => 'config_error',
                    'title' => 'Sync Address Needs Attention',
                    'friendly_message' => 'The cloud sync address in this clinic\'s settings points to this computer, so records are not being sent to or received from the cloud.',
                    'action_hint' => 'Ask your system administrator to correct the sync address. Local clinic data is safe and nothing was lost.',
                    'raw_error' => 'Sync endpoint resolves to a loopback address.',
                ]
            );
        }

        try {
            $httpOptions = $this->getHttpOptions();

            $push = $this->pushAllPending($endpoint, $httpOptions);
            $pull = $this->pullAll($endpoint, $httpOptions, $full);
            $this->progress('messages');
            $smsProcessed = $this->flushOfflineSmsQueue();

            $now = now()->toIso8601String();
            $failed = $push['failed'] + $pull['failed'];

            $this->state->put('last_success_at', $now);
            $this->state->forget('update_required');
            if ($failed > 0) {
                $this->state->put('last_run_status', 'partial');
                $this->state->putJson('last_error', $this->describeApplyFailures($failed, array_merge($push['errors'], $pull['errors'])));
            } else {
                $this->state->put('last_run_status', 'ok');
                $this->state->forget('last_error');
            }

            return [
                'success' => true,
                'partial' => $failed > 0,
                'in_progress' => !$pull['complete'],
                'pushed' => $push['acked'],
                'pulled' => $pull['applied'],
                'conflicts' => $pull['conflicts'],
                'failed' => $failed,
                'pages' => $pull['pages'],
                'sms_processed' => $smsProcessed,
                'timestamp' => $now,
                'mode' => 'cloud_connected',
            ];
        } catch (ClientVersionTooOldException $e) {
            // Sync pauses; nothing is lost, every record stays pending until the app is updated.
            Log::warning('Sync paused: central server requires app version ' . ($e->minimumVersion ?? '(unknown)') . ' or newer.');
            $this->state->putJson('update_required', ['minimum' => $e->minimumVersion, 'at' => now()->toIso8601String()]);

            return $this->failedResult($e->getMessage(), [
                'type' => 'update_required',
                'title' => 'App Update Required',
                'friendly_message' => 'This version of ViLoCare is out of date. Sync is paused until you update.',
                'action_hint' => 'Ask an administrator to approve the update. Your records are safe and stay on this computer until then.',
            ]);
        } catch (Exception $e) {
            Log::warning('Sync Cycle Failed (Offline or Network Error): ' . $e->getMessage());

            return $this->failedResult($e->getMessage(), self::formatConnectionError($e->getMessage()));
        }
    }

    protected function failedResult(string $rawError, array $friendly): array
    {
        $this->state->put('last_run_status', 'failed');
        $this->state->putJson('last_error', [
            'at' => now()->toIso8601String(),
            'type' => $friendly['type'],
            'title' => $friendly['title'],
            'friendly_message' => $friendly['friendly_message'],
            'action_hint' => $friendly['action_hint'],
            'count' => 0,
        ]);

        return [
            'success' => false,
            'error' => $rawError,
            'error_type' => $friendly['type'],
            'friendly_error' => $friendly,
            'pushed' => 0,
            'pulled' => 0,
            'conflicts' => 0,
            'failed' => 0,
            'sms_processed' => 0,
            'timestamp' => $this->lastSuccessAt()?->toIso8601String(),
            'mode' => 'offline_failed',
        ];
    }

    protected function describeApplyFailures(int $failed, array $errors): array
    {
        $first = $errors[0] ?? ['table' => 'unknown', 'message' => 'Unknown error'];
        $friendly = self::formatConnectionError($first['message']);
        $tables = implode(', ', array_unique(array_column($errors, 'table')));

        return [
            'at' => now()->toIso8601String(),
            'type' => $friendly['type'],
            'title' => $friendly['title'],
            'friendly_message' => "{$failed} record(s) could not be applied during the last sync (tables: {$tables}). " . $friendly['friendly_message'],
            'action_hint' => 'They were logged in Conflict History for review. All other records synchronized normally.',
            'count' => $failed,
        ];
    }

    protected function isLoopbackEndpoint(string $endpoint): bool
    {
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));

        return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true) || str_ends_with($host, '.localhost');
    }

    /**
     * Pushes every pending local record, one dependency-safe page at a time.
     */
    protected function pushAllPending(string $endpoint, array $httpOptions): array
    {
        $sent = 0;
        $acked = 0;
        $exclude = [];
        $pages = 0;
        $maxPages = max(1, (int) config('sync.max_pages_per_run', 200));

        while ($pages < $maxPages) {
            $payload = $this->getPendingLocalPayload($exclude);
            if ($payload['total_records'] === 0) {
                break;
            }

            $this->state->touchHeartbeat();
            $this->progress('push', $pages + 1);

            $result = $this->remotePost($endpoint, 'push', $payload, $httpOptions);
            $syncedIds = $result['synced_ids'] ?? [];

            $this->markRecordsAsSynced($syncedIds);

            if (!empty($result['server_updates'])) {
                $this->applyRemotePull($result['server_updates']);
            }

            foreach ($payload['tables'] as $tableName => $records) {
                $sentIds = array_column($records, 'id');
                $ackedIds = $syncedIds[$tableName] ?? [];
                $sent += count($sentIds);
                $acked += count($ackedIds);

                // Records the server refused stay pending but are not retried within this run.
                $rejected = array_values(array_diff($sentIds, $ackedIds));
                if ($rejected) {
                    $exclude[$tableName] = array_merge($exclude[$tableName] ?? [], $rejected);
                }
            }

            $pages++;
        }

        $failed = $sent - $acked;

        return [
            'sent' => $sent,
            'acked' => $acked,
            'failed' => max(0, $failed),
            'pages' => $pages,
            'errors' => $failed > 0
                ? [['table' => implode(',', array_keys($exclude)), 'message' => "The central server did not accept {$failed} pushed record(s)."]]
                : [],
        ];
    }

    /**
     * Pulls remote changes page by page using the server-assigned cursor, persisting progress so an
     * interrupted run resumes instead of restarting, and only advancing the cursor once every
     * table has been drained.
     */
    protected function pullAll(string $endpoint, array $httpOptions, bool $full): array
    {
        if ($full) {
            $this->state->forget('pull_cursor');
            $this->state->forget('pull_progress');
        }

        $progress = $this->state->getJson('pull_progress') ?? [
            'since' => $this->state->get('pull_cursor'),
            'server_time' => null,
            'next' => null,
        ];

        $totals = ['applied' => 0, 'conflicts' => 0, 'failed' => 0, 'errors' => [], 'pages' => 0, 'complete' => false];
        $maxPages = max(1, (int) config('sync.max_pages_per_run', 200));

        do {
            $this->state->touchHeartbeat();
            $this->progress('pull', $totals['pages'] + 1);

            $result = $this->remoteGet($endpoint, 'pull', $this->pullQuery($progress['since'], $progress['next']), $httpOptions);

            if (!array_key_exists('next', $result)) {
                throw new Exception('The central server is running an older sync version that cannot page results. Deploy the updated server first.');
            }

            $progress['server_time'] = $progress['server_time'] ?? ($result['server_time'] ?? now()->toIso8601String());

            $applyResult = $this->applyRemotePull($result['tables'] ?? []);
            $totals['applied'] += $applyResult['applied'];
            $totals['conflicts'] += $applyResult['conflicts'];
            $totals['failed'] += $applyResult['failed'];
            $totals['errors'] = array_slice(array_merge($totals['errors'], $applyResult['errors']), 0, 10);
            $totals['pages']++;

            $progress['next'] = $result['next'];
            if ($progress['next']) {
                $this->state->putJson('pull_progress', $progress);
            }
        } while ($progress['next'] && $totals['pages'] < $maxPages);

        if (!$progress['next']) {
            // Server-assigned cursor, minus a small overlap for rows committed in the same second.
            $cursor = Carbon::parse($progress['server_time'])
                ->subSeconds((int) config('sync.cursor_overlap_seconds', 5))
                ->toIso8601String();

            $this->state->put('pull_cursor', $cursor);
            $this->state->forget('pull_progress');
            $totals['complete'] = true;
        }

        return $totals;
    }

    protected function pullQuery(?string $since, ?array $next): array
    {
        return array_filter([
            'since' => $since,
            'resume_table' => $next['table'] ?? null,
            'resume_updated_at' => $next['updated_at'] ?? null,
            'resume_id' => $next['id'] ?? null,
        ], fn ($v) => $v !== null);
    }

    protected function remotePost(string $endpoint, string $path, array $payload, array $httpOptions): array
    {
        $response = Http::withHeaders($this->clientHeaders())
          ->withOptions($httpOptions)
          ->timeout(30)
          ->post("{$endpoint}/{$path}", $payload);

        return $this->decodeRemoteResponse($response, $path);
    }

    protected function remoteGet(string $endpoint, string $path, array $query, array $httpOptions): array
    {
        $response = Http::withHeaders($this->clientHeaders())
          ->withOptions($httpOptions)
          ->timeout(30)
          ->get("{$endpoint}/{$path}", $query);

        return $this->decodeRemoteResponse($response, $path);
    }

    protected function decodeRemoteResponse($response, string $path): array
    {
        if ($response->status() === 426) {
            $minimum = $response->json('minimum_version');

            throw new ClientVersionTooOldException(is_string($minimum) ? $minimum : null);
        }

        if (!$response->successful()) {
            throw new Exception('Central Sync ' . ucfirst($path) . " failed with HTTP {$response->status()}: " . Str::limit(strip_tags((string) $response->body()), 300));
        }

        $json = $response->json();
        if (!is_array($json)) {
            throw new Exception('Central Sync ' . ucfirst($path) . ' returned an unreadable response.');
        }

        return $json;
    }

    /**
     * Read-only comparison with the central server: per table local count, remote count and how
     * many remote ids are absent locally. Never applies anything and never exposes record contents.
     */
    public function compareWithRemote(?string $endpoint = null): array
    {
        $endpoint = rtrim((string) ($endpoint ?? $this->remoteUrl), '/');
        $options = $this->getHttpOptions();

        $remoteCounts = $this->remoteGet($endpoint, 'counts', [], $options)['tables'] ?? [];

        $missing = array_fill_keys(array_keys($this->syncedModels), 0);
        $next = null;
        $pages = 0;

        do {
            $page = $this->remoteGet($endpoint, 'pull', $this->pullQuery(null, $next), $options);

            if (!array_key_exists('next', $page)) {
                throw new Exception('The central server is running an older sync version that cannot page results. Deploy the updated server first.');
            }

            foreach ($page['tables'] ?? [] as $tableName => $records) {
                $modelClass = $this->syncedModels[$tableName] ?? null;
                if (!$modelClass) {
                    continue;
                }

                foreach (array_chunk(array_column($records, 'id'), 500) as $chunk) {
                    $present = $modelClass::whereIn('id', $chunk)->count();
                    $missing[$tableName] += count($chunk) - $present;
                }
            }

            $next = $page['next'];
        } while ($next && ++$pages < 10000);

        $rows = [];
        foreach ($this->syncedModels as $tableName => $modelClass) {
            $rows[$tableName] = [
                'local' => $modelClass::count(),
                'remote' => $remoteCounts[$tableName] ?? null,
                'missing_locally' => $missing[$tableName],
            ];
        }

        return $rows;
    }

    /**
     * Server-side: the full location reference list (states, counties, facilities).
     * Reference data only - no patient information.
     */
    public function processServerLocations(): array
    {
        $tables = [];
        foreach (LocationReconciler::TABLES as $table) {
            $tables[$table] = DB::table($table)->orderBy('code')->orderBy('name')->get()
                ->map(fn ($row) => (array) $row)->all();
        }

        return [
            'success' => true,
            'server_time' => now()->toIso8601String(),
            'tables' => $tables,
        ];
    }

    /**
     * Client-side, read-only: the cloud's location list.
     *
     * @return array<string, array<int, array>>
     */
    public function fetchRemoteLocations(?string $endpoint = null): array
    {
        $endpoint = rtrim((string) ($endpoint ?? $this->remoteUrl), '/');
        $response = $this->remoteGet($endpoint, 'locations', [], $this->getHttpOptions());

        if (!isset($response['tables']['facilities'])) {
            throw new Exception('The central server does not provide the locations list yet. Deploy the updated server first.');
        }

        return $response['tables'];
    }

    /**
     * Client-side, read-only: pages through the cloud's pull feed and keeps only the requested
     * columns of the requested tables (id => columns). Used to compare location references
     * without holding or printing patient details.
     *
     * @param array<string, array<int, string>> $columns table => columns to keep
     * @return array<string, array<string, array>>
     */
    public function fetchRemoteReferences(array $columns, ?string $endpoint = null): array
    {
        $endpoint = rtrim((string) ($endpoint ?? $this->remoteUrl), '/');
        $options = $this->getHttpOptions();
        $found = array_fill_keys(array_keys($columns), []);
        $next = null;
        $pages = 0;

        do {
            $page = $this->remoteGet($endpoint, 'pull', $this->pullQuery(null, $next), $options);

            if (!array_key_exists('next', $page)) {
                throw new Exception('The central server is running an older sync version that cannot page results. Deploy the updated server first.');
            }

            foreach ($columns as $table => $keep) {
                foreach ($page['tables'][$table] ?? [] as $record) {
                    $found[$table][$record['id']] = array_intersect_key($record, array_flip($keep));
                }
            }

            $next = $page['next'];
        } while ($next && ++$pages < 10000);

        return $found;
    }

    /**
     * Server-side: row counts per synced table (lightweight; used by the compare diagnostic).
     */
    public function processServerCounts(): array
    {
        $counts = [];
        foreach ($this->syncedModels as $tableName => $modelClass) {
            if (class_exists($modelClass)) {
                $counts[$tableName] = $modelClass::count();
            }
        }

        return [
            'success' => true,
            'server_time' => now()->toIso8601String(),
            'tables' => $counts,
        ];
    }

    public function lastSuccessAt(): ?Carbon
    {
        $stored = $this->state->getTime('last_success_at');
        if ($stored) {
            return $stored;
        }

        // Legacy cursor kept in cache by older versions.
        $legacy = Cache::get('vilocare_last_synced_at');

        return $legacy ? Carbon::parse($legacy) : null;
    }

    /**
     * Marks specific record IDs as synced in the local database.
     */
    public function markRecordsAsSynced(array $syncedIds): void
    {
        foreach ($this->syncedModels as $tableName => $modelClass) {
            $ids = $syncedIds[$tableName] ?? [];
            if (!empty($ids)) {
                $modelClass::whereIn('id', $ids)->update([
                    'sync_status' => SyncStatus::SYNCED->value,
                ]);
            }
        }
    }

    /**
     * Marks all currently pending records as synced.
     */
    public function markAllPendingAsSynced(): int
    {
        $updated = 0;
        foreach ($this->syncedModels as $tableName => $modelClass) {
            $count = $modelClass::where('sync_status', SyncStatus::PENDING->value)->update([
                'sync_status' => SyncStatus::SYNCED->value,
            ]);
            $updated += $count;
        }
        return $updated;
    }

    /**
     * Dispatches any queued offline SMS messages when connectivity returns.
     */
    public function flushOfflineSmsQueue(): int
    {
        $queuedLogs = NotificationLog::where('status', 'queued')->get();
        if ($queuedLogs->isEmpty()) {
            return 0;
        }

        $smsService = app(SmsService::class);
        $count = 0;

        foreach ($queuedLogs as $log) {
            $smsService->dispatchToProvider($log);
            $count++;
        }

        return $count;
    }

    /**
     * Fetches real-time synchronization dashboard metrics.
     */
    public function getSyncStats(): array
    {
        $tableCounts = [];
        $totalPending = 0;
        $totalSynced = 0;

        foreach ($this->syncedModels as $tableName => $modelClass) {
            if (!class_exists($modelClass)) {
                continue;
            }

            // Pull-only reference data is never pushed, so it is never "pending upload".
            $pending = $this->isPullOnly($tableName)
                ? 0
                : $modelClass::where('sync_status', SyncStatus::PENDING->value)->count();
            $synced = $this->isPullOnly($tableName)
                ? $modelClass::count()
                : $modelClass::where('sync_status', SyncStatus::SYNCED->value)->count();

            $tableCounts[$tableName] = [
                'pending' => $pending,
                'synced' => $synced,
                'total' => $pending + $synced,
                'label' => ucwords(str_replace('_', ' ', $tableName)),
            ];

            $totalPending += $pending;
            $totalSynced += $synced;
        }

        $totalConflicts = SyncConflict::count();
        $unresolvedConflicts = SyncConflict::whereNull('resolved_at')->count();
        $lastSyncedAt = $this->lastSuccessAt();
        $heartbeatAt = $this->state->getTime('heartbeat_at');
        $heartbeatAge = $heartbeatAt ? (int) $heartbeatAt->diffInSeconds(now(), true) : null;
        $lastSyncAge = $lastSyncedAt ? (int) $lastSyncedAt->diffInSeconds(now(), true) : null;
        $caBundle = config('sync.ca_bundle', base_path('certs/cacert.pem'));
        $caBundleExists = !empty($caBundle) && file_exists($caBundle);
        $sslVerify = (bool) config('sync.ssl_verify', true);

        return [
            'tables' => $tableCounts,
            'total_pending' => $totalPending,
            'total_synced' => $totalSynced,
            'total_conflicts' => $totalConflicts,
            'unresolved_conflicts' => $unresolvedConflicts,
            'last_synced_at' => $lastSyncedAt,
            'last_synced_age_seconds' => $lastSyncAge,
            'last_sync_stale' => $lastSyncAge === null || $lastSyncAge > (int) config('sync.stale_after_seconds', 600),
            'heartbeat_at' => $heartbeatAt,
            'heartbeat_age_seconds' => $heartbeatAge,
            'auto_sync_stalled' => $heartbeatAge === null || $heartbeatAge > (int) config('sync.stalled_after_seconds', 180),
            'last_run_status' => $this->state->get('last_run_status'),
            'last_error' => $this->state->getJson('last_error'),
            'standalone' => (bool) config('sync.standalone', false),
            'configured' => $this->isConfigured() || $this->isCentral(),
            'central' => $this->isCentral(),
            'app_version' => config('sync.client_version'),
            'update_required' => $this->updateRequired(),
            'device_id' => $this->deviceId,
            'remote_url' => $this->remoteUrl,
            'ssl_status' => [
                'ca_bundle_path' => $caBundle,
                'ca_bundle_exists' => $caBundleExists,
                'ssl_verify' => $sslVerify,
                'label' => $sslVerify 
                    ? ($caBundleExists ? 'Bundled CA Active' : 'System CA Store')
                    : 'SSL Verification Disabled (Dev)',
            ],
        ];
    }

    /**
     * Manually resolves a recorded sync conflict.
     */
    public function resolveConflict(
        SyncConflict $conflict,
        string $strategy,
        ?array $overrideData = null,
        ?string $userId = null
    ): bool {
        $tableName = $conflict->table_name;
        $modelClass = $this->syncedModels[$tableName] ?? null;

        if (!$modelClass || !class_exists($modelClass)) {
            return false;
        }

        $record = $modelClass::find($conflict->record_id);

        if ($strategy === 'keep_local') {
            if ($record) {
                $record->forceFill($conflict->local_payload);
                $record->sync_status = SyncStatus::PENDING->value;
                $record->last_modified_at = now();
                $record->saveQuietly();
            }
            $notes = 'Manual override: Clinician/Admin chose to keep local version.';
        } elseif ($strategy === 'keep_remote') {
            if ($record) {
                $record->forceFill($conflict->remote_payload);
                $record->sync_status = SyncStatus::SYNCED->value;
                $record->last_modified_at = now();
                $record->saveQuietly();
            }
            $notes = 'Manual override: Clinician/Admin chose to keep remote version.';
        } elseif ($strategy === 'manual_override' && !empty($overrideData)) {
            if ($record) {
                $record->forceFill($overrideData);
                $record->sync_status = SyncStatus::PENDING->value;
                $record->last_modified_at = now();
                $record->saveQuietly();
            }
            $notes = 'Manual override: Custom merged values applied.';
        } else {
            $notes = 'Conflict marked as reviewed and resolved.';
        }

        $conflict->update([
            'resolution_strategy' => $strategy,
            'resolution_notes' => $notes,
            'resolved_at' => now(),
            'resolved_by' => $userId ?? auth()->id(),
        ]);

        return true;
    }
}
