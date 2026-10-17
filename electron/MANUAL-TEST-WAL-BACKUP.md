# Manual test: WAL backup and restore (ViLoCare 1.0.1)

What this proves:

1. A backup taken at startup contains **every** record, even when the last run ended in a crash and the newest records
   were still only in `database.sqlite-wal` (the app now runs a WAL checkpoint before copying).
2. When a migration fails, the database is **restored from that backup** and the app says so.
3. After you fix the cause, the app starts normally with all data intact.

The whole test uses a **throwaway data folder** (`VILOCARE_DATA_DIR`). It never touches `C:\ProgramData\ViLoCare`.
Do not run it against a real clinic's data folder.

Time: about 20 minutes. Needs: this repository, Node, and the PHP that `npm run fetch-php` downloads.

---

## 0. Set up a throwaway copy (once per shell)

Open PowerShell in the repository root.

```powershell
$repo = (Get-Location).Path
$data = "$env:TEMP\vilocare-waltest"
$env:VILOCARE_DATA_DIR = $data          # makes the app use this folder instead of ProgramData
Remove-Item $data -Recurse -Force -ErrorAction SilentlyContinue

cd "$repo\electron"
npm install
npm run fetch-php                        # no-op if PHP with opcache is already there
```

A small helper that prints record counts and the journal mode of a database file (uses the bundled PHP):

```powershell
$php = "$repo\electron\resources\php\php.exe"
Set-Content "$env:TEMP\count.php" '<?php $p=new PDO("sqlite:".$argv[1]); foreach (["users","patients"] as $t) echo "$t: ".$p->query("select count(*) from $t")->fetchColumn().PHP_EOL; echo "journal_mode: ".$p->query("pragma journal_mode")->fetchColumn().PHP_EOL;'
function Count-Db($file) { & $php -c "$data\php" "$env:TEMP\count.php" $file }
```

(`$data\php` is the runtime ini the app writes on first start, so run `Count-Db` only after step 1.)

## 1. First start and some data

```powershell
cd "$repo\electron"; npm start
```

1. Skip the sync connection (offline mode).
2. Create the first administrator (any email, a password of at least 10 characters).
3. Sign in and add **5 patients** (any test data). Write the number down: **N = 5** (plus however many already exist).
4. Open `%TEMP%\vilocare-waltest\storage\logs\electron.log` and find the line  
   `opcache self-check: ENABLED (PHP 8.3.x, ... scripts cached ...)`.  
   If it says `WARNING DISABLED` or `could not query`, stop: opcache is not working in this build.
5. In another PowerShell window (re-run the `$data`, `$php`, `Count-Db` lines there), check:
   ```powershell
   Get-ChildItem $data | Select Name,Length        # database.sqlite AND database.sqlite-wal / -shm should exist
   Count-Db "$data\database.sqlite"                # journal_mode: wal
   ```
   **Expected:** `-wal` and `-shm` files exist while the app runs, `journal_mode: wal`.

## 2. Simulate a crash with recent records only in the WAL

1. In the app add **2 more patients** (so N = 7 in total, counting the 5 above).
2. Kill everything at once, without a normal quit (this is the crash):
   ```powershell
   Get-Process electron,php -ErrorAction SilentlyContinue | Stop-Process -Force
   Start-Sleep 2
   Get-ChildItem $data | Select Name,Length
   ```
   **Expected:** `database.sqlite-wal` is still present and larger than 0 bytes. This is the situation in which
   copying `database.sqlite` alone would lose the newest patients.

## 3. Force a failing migration (safe)

The migration below is a **temporary file**; you will delete it in step 6. It first deletes every patient (committed),
then fails, so the restore is something you can see. It is harmless because the data folder is a throwaway.

```powershell
$bad = "$repo\app_backend\database\migrations\2099_01_01_000000_zz_force_failure.php"
@'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;   // so the delete below is really committed before the failure

    public function up(): void
    {
        DB::table('patients')->delete();
        throw new RuntimeException('Forced failure for the WAL backup/restore test');
    }
};
'@ | Set-Content $bad -Encoding ascii
```

## 4. Start the app: backup, failure, restore

```powershell
cd "$repo\electron"; npm start
```

**Expected on screen:** an error page: *"Updating the database failed. Your data was restored from the backup taken just
before."* (the details show the forced failure). The app does not open.

Close the error window, then check, in the other PowerShell window:

```powershell
Get-ChildItem "$data\backups" | Select Name,Length
Count-Db (Get-ChildItem "$data\backups" -Filter *pre-migrate* | Sort-Object Name | Select -Last 1).FullName
Count-Db "$data\database.sqlite"
Get-Content "$data\storage\logs\electron.log" -Tail 12
```

**Expected:**

| Check | Result |
|---|---|
| A file `database-<time>-pre-migrate.sqlite` exists in `backups` | yes |
| Patient count in that backup | **7** (all of them, including the 2 that were only in the WAL) |
| Patient count in `database.sqlite` now | **7** (restored, not 0) |
| `database.sqlite-wal` / `-shm` | gone or empty right after the restore (they are removed so they cannot be replayed onto the restored file) |
| `electron.log` | `backup: ...pre-migrate.sqlite` then `migration failed; database restored from backup` |

If the backup shows fewer than 7 patients, the WAL checkpoint did not work: **the test failed**, keep the folder and
report it with `electron.log`.

## 5. Check that the failed migration changed nothing

```powershell
Count-Db "$data\database.sqlite"          # patients must still be 7
```

## 6. Remove the failure and start normally

```powershell
Remove-Item $bad
cd "$repo\electron"; npm start
```

**Expected:** the app opens, you sign in with the account from step 1, and the Patients page lists all **7** patients.
Quit the app normally (tray icon, Exit) and run:

```powershell
Get-ChildItem $data | Select Name,Length   # -wal is now 0 bytes or gone after a clean exit
```

## 7. Clean up

```powershell
Remove-Item $bad -ErrorAction SilentlyContinue      # must not remain in app_backend\database\migrations
git -C $repo status --short app_backend/database    # must not list zz_force_failure
Remove-Item $data -Recurse -Force
Remove-Item Env:\VILOCARE_DATA_DIR
```

---

## Extra: upgrade check on a real installation (1.0.0 to 1.0.1)

Do this on a **copy of a clinic computer or a test machine**, after copying `C:\ProgramData\ViLoCare` somewhere safe.

1. Note the patient count and who is signed in.
2. Run `ViLoCare Setup 1.0.1.exe` over the existing install.
3. Start ViLoCare. **Expected:** you are asked to sign in once (sessions moved to files); this is normal.
4. `C:\ProgramData\ViLoCare\backups` has a new `pre-migrate` backup; the patient count in it equals the count from step 1.
5. `electron.log` contains `opcache self-check: ENABLED`.
6. Patients and Viral Load pages open in well under a second; the header badge says `Synced ... ago` or a clear reason.
7. Sign in as a **second Windows account** and start ViLoCare there. **Expected:** it opens (the data folder is writable
   by every account) and shows the same data.
8. Click **Sync now** on the Sync Center: the page stays responsive and shows progress.
