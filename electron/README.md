# ViLoCare desktop shell (Electron)

Wraps the Laravel app in `../app_backend` as a Windows desktop app. On launch it starts a bundled PHP
(`php -S` on `127.0.0.1`, free port) and `php artisan schedule:work`, waits until the app answers, then opens the
window. It replaces `clinic/start-scheduler.bat` as the recommended way to run the scheduler (sync every minute,
SMS queue, due reminders). The `.bat` flow stays in `app_backend/clinic/` as a fallback — don't run both.

## Run in development

```powershell
cd electron
npm install
npm run fetch-php     # once: bundled PHP 8.3 (thread-safe x64) -> resources/php  (optional: dev falls back to php on PATH)
npm start
```

Dev uses `../app_backend` directly (needs its `vendor/` and `public/build/`) and keeps its data in
`electron/.devdata/`, so it never touches real clinic data. Set `VILOCARE_DATA_DIR` to use another folder. Note that
Laravel reads only the data folder's `.env` (via `VILOCARE_ENV_DIR`), never `app_backend/.env`.

## Build the installer

```powershell
cd app_backend; npm ci; npm run build; cd ..\electron   # once, so public/build exists
npm run build:installer      # builds only; never publishes (npm run dist is the same thing)
```

`build:installer` copies the icons from `app_backend/public/images`, downloads PHP and the VC++ redistributable, stages a
secret-free copy of the app (`composer install --no-dev`; no `.env`, no databases), runs electron-builder with
`--publish never`, then `scripts/check-build.js` verifies the files and scans for token-like strings.
Output in `electron/dist/`: `ViLoCare-Setup-<version>.exe` (per-machine NSIS installer, needs admin),
`ViLoCare-Setup-<version>.exe.blockmap` (lets updates download only the changed blocks) and `latest.yml` (the update
manifest: version, file name, size, SHA-512; a `1.1.1-beta.1` build writes `beta.yml` instead). The installer installs
the Visual C++ runtime if it is missing and creates the data folder.

The installer is unsigned. electron-builder's signing-tools archive cannot be extracted on Windows accounts without symlink rights, so `signAndEditExecutable` is off and `build/after-pack.js` sets the exe icon/version with `rcedit`. When you buy a code-signing certificate, turn it back on and delete the hook.

## Where data lives

`%PROGRAMDATA%\ViLoCare` (usually `C:\ProgramData\ViLoCare`), shared by every Windows account on the machine. The
installer gives the local *Users* group modify rights, so any local user can read the database — restrict the folder
with `icacls` if the PC is shared with non-clinic users.

| Item | Path |
| --- | --- |
| Settings and secrets (sync URL/token, API keys) | `.env` |
| SQLite database | `database.sqlite` |
| Logs | `storage\logs\` (`electron.log`, `server.log`, `scheduler.log`, `artisan.log`, `laravel.log`) |
| Uploaded files (avatars) | `storage\app\public\` |
| Database backups (last 10) | `backups\` |

Installing, updating or uninstalling never touches this folder. Per-Windows-user preferences (start with Windows,
last port) are in `%APPDATA%\ViLoCare`.

## Behaviour

- **First run** (all local, nothing is seeded and there are no default passwords):
  1. Generates the app key, creates the database and runs migrations.
  2. **Step 1 - sync details (optional).** A setup screen asks for the central sync URL and token and saves them to the
     local `.env` (never in the installer). "Skip for now" is allowed: the app then runs **offline**, records stay
     pending, and the Sync Center shows *"Sync is not set up. An administrator can add the connection in Settings."*
  3. If sync was set up, one full sync is attempted. If it pulled users, the next step is skipped completely.
  4. **Step 2 - create the first administrator (required).** Shown only while the users table is still empty (sync
     skipped or failed): name, email, password (at least 10 characters) and confirmation. **Installers must use the same
     email as their administrator account on the central server.** This runs through `php artisan vilocare:first-admin`
     (password passed on STDIN), which refuses to run if any user exists. There is no web route for it, so it cannot be
     used after setup.
- **Changing the sync connection later:** administrators use **Sync Connection** in the sidebar (URL, masked token,
  *Test connection*). It writes the same `.env`. PHP re-reads `.env` on every request and every scheduled run, so
  the change applies within about a minute with no restart. The tray's *Sync settings…* opens the same setup form.
- **What happens to the local administrator at the first sync:** the local account is created with an old
  `last_modified_at`, so it never beats a real record. If the central server already has an account with the **same
  email** (business-key match), the central record wins: the local name, role and **password are replaced by the central
  ones**, so after the first sync sign in with the *central* password. If the emails **differ**, the central server has no
  match, so the local account is *added* to central as a new administrator and keeps its local password. Check the email
  carefully; to fix a mismatch, have a central administrator disable or delete the wrongly added account and create the
  local user again with the correct email.- **Later launches:** the database is copied to `backups\` before pending migrations (or after an app update) and
  restored automatically if a migration fails. An older app version refuses to open data from a newer one.
- **Single instance.** A second launch focuses the running window. If another Windows user is already running
  ViLoCare on the same machine, it shows an error instead of starting a second copy against the same database.
- **Tray.** Closing the window hides it to the tray; only *Quit ViLoCare* (tray menu, with confirmation) stops it.
  PHP and the scheduler are also stopped on Windows shutdown / log-off, and leftovers from a crash are killed on the
  next launch. The tray menu has *Start with Windows* (on by default; starts hidden in the tray, per Windows user)
  and *Restart ViLoCare services* (rarely needed; `.env` changes apply without it).
- **Crashes.** The scheduler and the web server are restarted with back-off; after 5 quick crashes an error window
  with the log tail is shown.
- **HTTPS sync** uses the bundled `certs/cacert.pem` (`VILOCARE_CA_BUNDLE`).

## Camera and other permissions
`src/permissions.js` decides what the app window may use: **everything is denied except the camera (video only, never the
microphone), and only for the app's own `http://127.0.0.1:<port>` page** in the main window. Other origins, other windows,
sub-frames and every other permission (location, notifications, clipboard, USB, ...) are refused. The rules are unit-tested:
`cd electron; npm test`.

The "Take Photo" button (`app_backend/public/js/camera-capture.js`) opens a live-preview dialog. No installer text is needed on
Windows: Windows itself controls access under **Settings > Privacy & security > Camera** ("Let desktop apps access your
camera" must be on). If it is off, the dialog says so and offers "Choose a file".
## Auto-update

From 1.1.0 the app updates itself with `electron-updater`. Updates come from a **separate, public GitHub repository that
holds only release files** (no source code). The installed app reads it anonymously: **no token is ever shipped.**

### Behaviour

- **Checks** about a minute after launch and every 4 hours. Offline or any error is written to `electron.log`
  (`updater: ...` lines) and never shown to staff. Downloads start by themselves, differential (only changed blocks).
- **Installs on quit** by default. Before installing, the app stops new syncs (`php artisan vilocare:update-gate hold`),
  waits up to 2 minutes for a running sync to finish, and only then lets the installer run. If a sync cannot finish, the
  install is **skipped this time** and the downloaded update waits for the next quit. Nothing is installed during a
  Windows shutdown or log-off.
- **"Update ready" notice:** shown **only to ViLoCare administrators**, with *Restart and update now* and *Later*.
  Laravel renders it only for the administrator role, and the approval is a 60-second signed token that only
  `POST /app-update/approval` (administrators only) can issue; Electron checks the signature and that the call comes from
  the app window's top frame on `http://127.0.0.1:<port>`. Other staff see nothing about updates (except the red
  "out of date" banner below, if the server demands a newer version).
- **Restart and update now:** same sync wait, then the app stops PHP and the scheduler, runs the installer silently and
  starts again. The installer also stops any `php.exe` inside its own install folder (`build/installer.nsh`).
- **Data is never touched.** `%PROGRAMDATA%\ViLoCare` is outside the install folder; install, update and uninstall never
  delete it. After an update the usual start-up safeguards run unchanged (see "First start after an update" below).
- **Version display:** Sync Center page ("ViLoCare app version"), and the tray menu (version line, update status line,
  *Check for updates*). Tray status: *Up to date* / *Downloading update…* / *Update 1.2.0 ready* / *Update required – sync is paused*.
- **DevTools are disabled in installed builds**, so the update bridge (`window.viloUpdater`) cannot be called from a console.

### First-time setup (once)

1. **Create the releases repository** on GitHub, e.g. `vilocare-releases`. **Public.** Tick "Add a README" (an empty repo
   cannot hold releases). Do not push any source code to it.
2. **Put owner and repo in `electron-builder.yml`** (the only place; the installed app gets them from the baked-in
   `app-update.yml`):
   ```yaml
   publish:
     provider: github
     owner: davokinga17
     repo: vilocare-releases
   ```
3. **Create a fine-grained personal access token** (GitHub → Settings → Developer settings → Personal access tokens →
   Fine-grained tokens → Generate): *Resource owner* = the owner of the releases repo; *Repository access* = **Only select
   repositories → vilocare-releases**; *Permissions → Repository → **Contents: Read and write*** (Metadata read-only is added
   automatically; nothing else). Set an expiry (e.g. 90 days). It cannot touch the source repository.
4. **Give it to the release command for this shell session only** (PowerShell; nothing is saved to disk or history):
   ```powershell
   $sec = Read-Host "GitHub token" -AsSecureString
   $env:GH_TOKEN = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec))
   npm run release
   Remove-Item Env:GH_TOKEN      # or just close the window
   ```
   The token is never in the repo, the installer, `electron-builder.yml`, any `.env`, or the logs. `.gitignore` blocks
   `*.token`, `gh_token*`, `electron/.env*`, and `scripts/check-build.js` fails the build if a token-like string
   (`ghp_…`, `github_pat_…`, the value of `GH_TOKEN`) appears in `src/`, the packaged app or the update files.

### Release checklist

1. Bump `version` in `electron/package.json` **and** `electron/package-lock.json` (two places at the top) — `tests/Feature/DesktopPerformanceConfigTest.php` pins the number, update it too.
2. Commit everything (the release refuses a dirty working tree).
3. `cd app_backend; php artisan test` and `cd electron; npm test`.
4. `npm run build:installer`, then install it on a **spare PC/VM** over the previous version and click through.
5. Set `GH_TOKEN` (above) and run `npm run release`. It re-runs the tests, builds, scans, then creates a **DRAFT** release
   `v<version>` with the installer, its `.blockmap` and `latest.yml`, and verifies the upload. It refuses to run if tests
   fail, the tree is dirty, the version is not higher than every release in the repo, `GH_TOKEN` is missing or owner/repo are placeholders.
6. Open the draft link, check the three files are there, then press **Publish release**. Only then do clinics receive it
   (a draft is invisible to the updater). Watch one real machine pick it up.

### Testing without GitHub (dev only)

```powershell
npm run build:installer                   # produces dist/latest.yml etc. for a HIGHER version than the one you run
node scripts/test-update-server.js        # serves dist/ on http://127.0.0.1:8765 (Range requests supported)
$env:VILOCARE_UPDATE_TEST_URL = "http://127.0.0.1:8765"; npm start
```
`VILOCARE_UPDATE_TEST_URL` is honoured **only when the app is not packaged** (`src/updater.js`); an installed app logs that it
ignored it. So nobody can redirect updates of an installed copy by setting an environment variable.

### Test a real update with a pre-release (stable installs never see it)

1. Set the next version to e.g. `1.1.1-beta.1` (package.json + lockfile), commit.
2. `npm run release -- --prerelease` → builds and publishes a **non-draft GitHub pre-release** `v1.1.1-beta.1`
   with `beta.yml` (not `latest.yml`). Stable installs read `latest.yml` of the *latest non-pre-release*, so they do not see it.
3. On a test PC install a **beta build** (`1.1.1-beta.0`, built and installed by hand): beta builds follow the `beta` channel.
4. Open the app, wait for the check (about a minute) or tray → *Check for updates*. Tray shows *Downloading update…* then
   *Update 1.1.1-beta.1 ready*. Sign in as an administrator, press *Restart and update now* (or close ViLoCare to install on quit).
5. Confirm in `electron.log` the update lines and the start-up safeguards (below), and that the data is intact.
6. For the real release: bump to the final version (`1.1.1`), release as a draft, publish it. Delete the pre-release if you like.

### First start after an update (safeguards, unchanged)

On every start `electron.log` shows, in this order:
```
version guard: ok (app 1.1.0, data last used by 1.0.1)      <- or "version guard: BLOCKED ..." for an older app (downgrade refused)
migrate: version 1.0.1 -> 1.1.0, pending migrations: no|yes
backup: C:\ProgramData\ViLoCare\backups\database-<time>-pre-update.sqlite   <- WAL checkpointed first, then copied
migrate: ok                                                      <- or "migration failed; database restored from backup"
```

### Roll back a bad release

- **Still a draft, or just published and nobody updated yet:** delete the release on GitHub (or switch it back to draft).
  Clinics that have not downloaded it never get it.
- **Already installed somewhere:** you cannot go back. The version guard refuses to open data with an older app, on purpose
  (the newer version may have migrated the database). The fix is a **new, higher version** (`1.1.1`) with the repair.
  Machines that downloaded the bad version but did not install it will pick up the newer one instead.

### Minimum supported version (server side)

Clinics send `X-Client-Version` on every sync request. On the central server set `VILOCARE_MIN_CLIENT_VERSION` (empty =
off). Below the minimum — or with no header at all, which is how 1.0.x identifies itself — the sync endpoints answer
**HTTP 426**. The clinic app then shows a banner to everyone ("This version is out of date. Sync is paused until you
update."), keeps all records pending (nothing is lost) and tells administrators to approve the update if it is already downloaded.

On Render: **Dashboard → your Render web service → Environment → Add environment variable** →
`VILOCARE_MIN_CLIENT_VERSION` = `1.1.1` → Save (the service restarts). Clear the value to turn enforcement off. Raise it
only after the new version is published and clinics had time to receive it; 1.0.x installs cannot update themselves.

### Windows permissions (per-machine install)

`perMachine: true` installs into Program Files for all users, so **every update needs administrator rights**: the silent
installer shows a Windows **UAC prompt** even though it is "silent". A Windows *standard* user must be given an
administrator's password at that moment; a Windows administrator just clicks Yes. (This is the Windows account, not the
ViLoCare administrator role.)
- If the prompt is **cancelled or nobody can answer**, nothing is installed. After *Restart and update now* the app has
  already closed, so reopen it from the desktop icon; the downloaded update is kept and offered again at the next quit.
- If the PC is **shutting down or logging off**, the app skips the install on purpose, because no one can answer the prompt.
- It fails **silently** when no UAC prompt can appear (for example nobody is signed in at the console, or the prompt is on
  a hidden secure desktop in a remote session): the install simply does not happen; `electron.log` shows the
  `update: ...` lines up to the point it started the installer.

**Recommendation:** keep `perMachine: true`. The data lives in `%PROGRAMDATA%\ViLoCare`, several Windows accounts share
it, and the Visual C++ runtime needs admin anyway. A per-user install would put the app under each user's profile, give
each Windows user their own copy to update, and split from the shared data folder's assumptions. Plan for **one person with
Windows administrator rights at each clinic computer** to approve updates (typically the same person who approves the
ViLoCare notice). Clinic staff do not need to be Windows administrators.

### Code signing (not done yet)

The installer is **unsigned**. Without signing, the updater still checks that the downloaded file's **SHA-512 hash equals the one
in `latest.yml`** (fetched over HTTPS from GitHub) and verifies each downloaded block against the `.blockmap`. That catches
corrupted or truncated downloads and a tampered file on the way. It does **not** prove who built the update: anyone who can
publish to the releases repo (a stolen token or account) can publish a malicious installer *and* a matching `latest.yml`,
which would run with administrator approval on clinic PCs. Unsigned installers also trigger SmartScreen warnings.

Decide **before the first installer reaches clinics** (changing later is painful):
- The **exact publisher name** on the certificate (legal entity). Once signed builds set `publisherName` in
  `electron-builder.yml`, installed apps accept only updates signed with that name, so a later name change breaks the chain.
  Do not set `publisherName` until you actually sign.
- **OV vs EV certificate** (EV gets SmartScreen trust immediately) and where the key lives (certificates now require a
  hardware token or a cloud signing service; a `.pfx` file on a laptop is no longer issued).
- Who may hold the signing credential and the GitHub token, and how they are rotated.
- Turn `signAndEditExecutable` back on and delete `build/after-pack.js` when signing starts (see above).
Clinics that installed the unsigned 1.1.0 can still update to a signed version.

### Troubleshooting

| Symptom | Check |
| --- | --- |
| No update ever appears | `storage\logs\electron.log` → `updater:` lines. `disabled (development run…)` means you ran from source. `check failed (ignored)` shows the reason (offline, wrong owner/repo, release is still a draft). |
| Installer built but update not found | The release must be **published** (not draft), contain `latest.yml` + `.exe` + `.blockmap`, and be a higher version. `REPLACE_WITH_...` in `electron-builder.yml` at build time means the installer looks at nothing: fill it in and rebuild. |
| "Restart and update now" says a sync is running | It waited 2 minutes. Try again; or just close ViLoCare and it installs on quit. |
| Nothing happens after clicking it | The Windows UAC prompt was hidden or cancelled; see above. Check `electron.log` for `update: ... starting installer`. |
| Update installs but old version still runs | A second install exists (per-user vs per-machine). Uninstall all copies, reinstall once. |
| `426` banner after raising `VILOCARE_MIN_CLIENT_VERSION` | Expected on older apps. Approve the downloaded update or install the new version by hand. |
| `release` refuses to run | Read the one-line reason it prints (token, placeholders, dirty tree, version not higher, tests, build check). |

## Limits to know about

- PHP's built-in server handles **one request at a time**. A manual **Sync now** runs inside a request, so the screen
  is blocked until it finishes (the automatic per-minute sync runs in the scheduler and does not block). AI
  questions are capped at a 5 s connect / 30 s total timeout (`GEMINI_TIMEOUT`), and any request is stopped after 300 s.
- The installer is unsigned, so Windows SmartScreen / antivirus may warn about it until it is code-signed.
- There is no downgrade: an older app refuses to open data last used by a newer one (the version guard).
- Versions before 1.1.0 have no updater: install 1.1.0 over them by hand once. From 1.1.0 on, updates arrive by themselves.
- **1.1.1 is the first release from the new owner.** Installs of 1.1.0 look at the previous releases repository, so each clinic installs 1.1.1 by hand once; from then on updates come from `davokinga17/vilocare-releases`. See `docs/HANDOVER.md`.
