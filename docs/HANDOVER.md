# ViLoCare handover

For the new owner (GitHub: `davokinga17`). This file contains no secrets. Every secret is created by the owner and typed only into Render, the clinic's Sync Connection page, or a local shell. Never put a token or password in git, chat or a screenshot.

## 1. Accounts the owner must create

| Account | Used for |
|---|---|
| GitHub (`davokinga17`) | Source repository and the **public** releases repository `vilocare-releases` |
| Render | Central server: one web service and one PostgreSQL database |
| Africa's Talking or Twilio | SMS reminders (pick one, `SMS_PROVIDER`) |
| Google AI Studio | Gemini key for AI insights (optional) |
| Code-signing certificate | Optional, see `electron/README.md` "Code signing" |

Render's free PostgreSQL has **no backups** and expires. Decide before go-live whether to pay for a database with backups, or schedule your own `pg_dump` (see `docs/DATA-MIGRATION-RUNBOOK.md`).

## 2. Ordered cutover

The previous server stays running until step 9. Do not reorder.

1. **Source repository.** Create an empty repository under `davokinga17` and push this project (the previous maintainer does the push; they are then added as a collaborator by the owner).
2. **Releases repository.** Create a **public** repository `vilocare-releases` with a README (an empty repo cannot hold releases). Do not push source code to it. `electron/electron-builder.yml` already names `davokinga17/vilocare-releases`.
3. **Render Blueprint.** Connect the repository, create the Blueprint from `render.yaml` (it creates the database `vilocare-db` and the web service), enter every variable listed in section 3 by hand, and let the first deploy finish. It migrates the new, empty database.
4. **Migrate the data** with `docs/DATA-MIGRATION-RUNBOOK.md`: suspend the new web service, restore the old dump into the new database, resume. The dump is taken with `--clean --if-exists --no-owner`, so the restore works on a database that already has tables. If the data is test-only, use the "start empty" path in the runbook instead, and give the pilot clinic a fresh data folder.
5. **Verify the new server:** `/up` and `/api/v1/health` answer, and the row counts match the old database (runbook, step 7).
6. **Get a real administrator and retire the demo accounts. No server shell is needed (Render's free plan has none).** Users are synced records: an administrator created on a clinic install (first-run setup) uploads to the central server on that clinic's first sync, and the same email and password then work on the central web app. If you restored the old data, sign in with an existing real administrator instead. Then open *Users* in the web app and deactivate every demo account (`admin@`, `clinician@`, `labtech@`, `dataclerk@`, `newstaff@` at `vilocare.org`); deactivation syncs to every clinic. `php artisan vilocare:disable-demo-accounts` does the same on a computer that has a shell; it is a dry run unless `--apply` is given and refuses to run unless another active administrator exists.
7. **Release 1.1.1** with the owner's own token (section 5).
8. **Switch every clinic.** Install 1.1.1 by hand once (older installs follow the previous releases repository and cannot find it). Then Settings, Sync Connection: new URL, new token, *Test connection*, *Sync now*.
9. **Retire the old Render** only after every clinic has synced to the new server, the counts match, and the owner has confirmed. Keep the dump offline for at least 30 days.

## 3. Render variables (names only)

**Set by `render.yaml`:** `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `DB_CONNECTION`, `DATABASE_URL`, `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION`, `VILOCARE_ROLE`, `RUN_MIGRATIONS`, `RUN_SEEDING`, `SMS_PROVIDER`, `AFRICASTALKING_USERNAME`, `AFRICASTALKING_SANDBOX`.

**Entered by hand (`sync:false`):**

| Variable | Purpose |
|---|---|
| `APP_KEY` | Laravel key in the form `base64:...` (`php artisan key:generate --show`) |
| `APP_URL` | Public HTTPS address of the service |
| `VILOCARE_SYNC_API_TOKEN` | Shared secret clinics send as `X-Sync-Token`; **required**, with no value the sync API answers 503 |
| `VILOCARE_MIN_CLIENT_VERSION` | Oldest app version allowed to sync (empty = no limit) |
| `AFRICASTALKING_API_KEY`, `AFRICASTALKING_SENDER_ID` | Africa's Talking credentials |
| `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_SENDER` | Twilio credentials |
| `AI_PROVIDER`, `GEMINI_API_KEY`, `GEMINI_MODEL` | AI insights (optional) |

**Per-installation defaults (optional, Render and clinic `.env`):** `VILOCARE_DEFAULT_FACILITY_CODE` is the facility code the dashboard opens on (built-in default is the pilot clinic's code; empty means the first facility). `VILOCARE_CLINIC_NAME` is the name printed on the report verification page (empty means the default facility's name). Set both to your own clinic's values.

No database column is encrypted with `APP_KEY`, so the new server does not need the old key; a new key only signs everyone out.

## 4. Rotating the sync token

1. Make a long random value, for example `php -r "echo bin2hex(random_bytes(32));"`.
2. Render, Environment: set `VILOCARE_SYNC_API_TOKEN`, save. The service restarts and clinics get 401; their records stay pending and nothing is lost.
3. On every clinic: Settings, Sync Connection, paste the new token, *Test connection*, *Sync now*.
4. Do not reuse the previous value.

## 5. Publishing a release with a short-lived token

The owner publishes releases. The token exists only for the minutes a release takes: created by the owner, used once, then deleted. Nobody else needs it, and it is never stored.

1. **Create it.** GitHub, Settings, Developer settings, Personal access tokens, *Fine-grained tokens*, Generate new token:
   - *Resource owner*: `davokinga17`.
   - *Expiration*: the shortest offered (1 day).
   - *Repository access*: **Only select repositories**, `vilocare-releases` only (not the source repository).
   - *Permissions*: Repository, **Contents: Read and write**. Nothing else.
2. **Prepare.** Raise the version in `electron/package.json` and its lockfile, commit, and keep the working tree clean. Build `app_backend/public/build`.
3. **Use it once**, in one PowerShell window. Reading it with a prompt keeps it out of the shell history:
   ```powershell
   $t = Read-Host "Paste the token" -AsSecureString
   $env:GH_TOKEN = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($t))
   cd electron
   npm run release
   ```
   This creates a **draft** release. Open it, check that `latest.yml`, the `.exe` and the `.blockmap` are attached, then press *Publish release*.
4. **Delete it right away.**
   ```powershell
   Remove-Item Env:GH_TOKEN
   ```
   Close the window. On GitHub, Developer settings, Personal access tokens, **Delete** the token (do not just let it expire). Create a new token for the next release.
5. Never put the token in a file, a chat, a screenshot, `.env`, `electron-builder.yml` or git.

## 6. What the previous maintainer must remove

- Their GitHub tokens (anything used as `GH_TOKEN` for the releases repository): revoke them.
- Local `.env` files, saved sync tokens and any database dump or backup copies on their own machines.
- The old Render web service and database, only after cutover step 9.
- They remain a collaborator on the source repository for as long as the owner wishes; nothing else to remove there.
