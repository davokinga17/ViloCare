# Data migration runbook (old Render to new Render)

No secrets in this file. Connection strings are held only in shell variables you set yourself.

Needs the PostgreSQL client tools (`psql`, `pg_dump`), version equal to or newer than the server.

## How the new server is created

`render.yaml` defines the database (`vilocare-db`) **and** the web service, with `RUN_MIGRATIONS=true`. Creating the Blueprint therefore builds both, and the first deploy runs `php artisan migrate --force` against the new, empty database. So the restore happens **after the first deploy**, into a database that already has all tables. The dump is made with `--clean --if-exists` so the restore drops and recreates every table it contains, and it works whether or not the tables already exist.

Choose one path:

- **Path A, restore the real data** (sections 1 to 7).
- **Path B, start empty** with test-only data (section 8).

## Path A: restore the real data

### 1. Freeze the old server
Ask clinics to stop syncing (or put the old service in maintenance). Writes during the dump would be missed. Clinics keep working offline; records they create meanwhile stay pending and upload after the switch.

### 2. Dump the old database
Render dashboard, old database, Connections, **External Database URL**.

```powershell
$env:OLD_DB_URL = "<external URL of the OLD database>"
pg_dump --clean --if-exists --no-owner --no-privileges --format=plain --file=vilocare-old.sql $env:OLD_DB_URL
```

Keep `vilocare-old.sql` off GitHub, chat and shared drives: it contains patient data.
Do not exclude the `migrations` table, or the next deploy would try to create every table again.

### 3. Create the Blueprint and let the first deploy finish
Create the Blueprint from `render.yaml`, enter the hand-typed variables (see `docs/HANDOVER.md`), and wait until the service is live (`/up` answers). The database now holds the empty tables.

### 4. Suspend the new web service
Render dashboard, new web service, Settings, **Suspend**. This stops the app from holding connections or writing while you restore.

### 5. Restore
Render dashboard, new database, Connections, **External Database URL**.

```powershell
$env:NEW_DB_URL = "<external URL of the NEW database>"
psql -v ON_ERROR_STOP=1 --single-transaction --file=vilocare-old.sql $env:NEW_DB_URL
```

`--single-transaction` means a failure leaves the new database exactly as the first deploy left it. Warnings that a table "does not exist, skipping" are normal on the first line of each drop and are not errors.

### 6. Resume and let it finish migrating
**Resume** the web service. On start `php artisan migrate --force` runs; because the restored `migrations` table lists what is applied, only migrations newer than the dump run. Then open `/up`.

### 7. Verify row counts
Run on **both** databases and compare every line:

```sql
SELECT 'states' t, count(*) FROM states UNION ALL SELECT 'counties', count(*) FROM counties
UNION ALL SELECT 'facilities', count(*) FROM facilities UNION ALL SELECT 'testing_laboratories', count(*) FROM testing_laboratories
UNION ALL SELECT 'users', count(*) FROM users UNION ALL SELECT 'patients', count(*) FROM patients
UNION ALL SELECT 'regimen_histories', count(*) FROM regimen_histories UNION ALL SELECT 'viral_load_results', count(*) FROM viral_load_results
UNION ALL SELECT 'eac_episodes', count(*) FROM eac_episodes UNION ALL SELECT 'eac_sessions', count(*) FROM eac_sessions
UNION ALL SELECT 'sample_collections', count(*) FROM sample_collections UNION ALL SELECT 'sample_rejections', count(*) FROM sample_rejections
UNION ALL SELECT 'appointments', count(*) FROM appointments UNION ALL SELECT 'notification_logs', count(*) FROM notification_logs
UNION ALL SELECT 'audit_logs', count(*) FROM audit_logs UNION ALL SELECT 'migrations', count(*) FROM migrations;
```

Also check that `GET /api/v1/sync/counts` on the new server (new token in the `X-Sync-Token` header) agrees, and that one clinic's `php artisan vilocare:sync-compare` reports no differences. The new server's counts may exceed the old ones only if a newer migration added rows.

Then switch the clinics (Settings, Sync Connection: new URL and token, *Test connection*; run `php artisan vilocare:sync --full` once, because the stored pull cursor belonged to the old server).

### Rollback
The dump does not change the old server. If anything fails, point the clinics back at the old URL and token. The failed restore is discarded by `--single-transaction`; if the new service is already damaged, delete and recreate the Blueprint and repeat from step 3. **Do not delete the old service or database until** every clinic has synced to the new server, the counts match, and the owner has confirmed. Keep `vilocare-old.sql` offline for at least 30 days.

### The free database has no backups
Render's free PostgreSQL has no backups and expires after a limited period. This applies to the old database too, so the dump in step 2 may be the only copy. After cutover, schedule a `pg_dump` (weekly at least) stored offline, or move to a paid database with backups.

## 8. Path B: start empty (test-only data)

Use this only when nothing in the old database needs to be kept.

1. Create the Blueprint and let the first deploy finish. Migrations create the tables and the built-in locations and laboratories. There are no users and no patients.
2. Do **not** restore anything. Do not point any existing clinic at this server yet.
3. The first administrator comes from the first clinic: install 1.1.1 on a computer with a **fresh data folder**, create the administrator on the setup screen, enter the new URL and token, and sync. That administrator is added to the server as a new administrator.
4. **The pilot clinic must start with a fresh data folder.** Its current database is already marked as synced with the old server and holds a pull cursor from it, so it would never re-send those records to the empty server, and its location ids may not match. Mixing the two leaves the server and the clinic disagreeing silently. To reset it:
   1. Close ViLoCare completely.
   2. Keep the old data: copy the whole folder `%PROGRAMDATA%\ViLoCare` to a safe place (all of it, including any `-wal` and `-shm` files next to `database.sqlite`), then rename the original to `ViLoCare.old`.
   3. Start ViLoCare 1.1.1. It creates a new data folder and shows first-run setup.
   4. Enter the new URL and token, create the administrator, and sync.
5. Anything that existed only in the old data folder stays there; it is not on the new server and will not sync. That is the point of this path.
6. Verify with the row counts above: expect 0 patients until test data is entered.
