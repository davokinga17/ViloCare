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

**EAC schedule (optional, whole days):** the client-confirmed rules are the defaults. Session 1 is the day the high result is entered (`VILOCARE_EAC_FIRST_SESSION_DELAY_DAYS=0`); sessions 2 and 3 are 30 days after the previous session's actual date (`VILOCARE_EAC_SESSION_GAP_DAYS=30`); the repeat viral load is 30 days after session 3's actual date (`VILOCARE_EAC_REPEAT_VL_DAYS=30`). A counsellor-picked next-session date is always honoured. Changing a value affects only new sessions. To move dates already stored, run `php artisan vilocare:eac-reschedule-preview` (read-only: counts and ART numbers only), then `php artisan vilocare:eac-reschedule` (dry run) and, once reviewed, `php artisan vilocare:eac-reschedule --apply`. It changes only pending sessions and repeat-VL due dates still waiting for a result, and only dates strictly in the future (a date that is today or past is never moved, and nothing is moved into the past). It writes a row backup to `storage/app/backups/` first (git-ignored; in the installed app this is the per-user data folder) and audits each change.

No database column is encrypted with `APP_KEY`, so the new server does not need the old key; a new key only signs everyone out.

## 3a. Email for password reset (owner, one time)

Staff can reset a forgotten password themselves, and confirm a recovery email, only if the **central server** can send email. Clinic computers never send email. Until this is set up everything keeps working and the administrator "Reset Pass" button stays the way to help someone; the Users page shows "Email is not configured".

1. Create a **dedicated Gmail account** just for ViLoCare (not a personal one).
2. Turn on **2-Step Verification** for it (Google Account, Security).
3. Create an **app password** (Google Account, Security, 2-Step Verification, App passwords) named `ViLoCare Render`. Google shows 16 characters once. Do not send them to anyone.
4. In the Render service, open the **Environment** tab and add these by hand (they are `sync:false`, so none of them is in the repository):

   | Variable | Value |
   |---|---|
   | `MAIL_MAILER` | `smtp` |
   | `MAIL_HOST` | `smtp.gmail.com` |
   | `MAIL_PORT` | `587` (STARTTLS; leave `MAIL_SCHEME` unset. Port 587 upgrades to an encrypted connection by itself. Only for port 465 set `MAIL_SCHEME=smtps`.) |
   | `MAIL_USERNAME` | the full Gmail address |
   | `MAIL_PASSWORD` | the app password from step 3 |
   | `MAIL_FROM_ADDRESS` | the same Gmail address |
   | `MAIL_FROM_NAME` | `ViLoCare` |

   `APP_URL` must be the public HTTPS address of the service: links in the emails are built from it.
5. Save; Render restarts the service. To check, open the Render **Shell** if your plan has one and run `php artisan vilocare:mail-test your.own@address`. It prints only "Success" or the error class. If there is no shell, use "Forgot password?" on the website with an account whose recovery email is verified.
6. A normal Gmail account can send about **500 emails a day**. Each reset or confirmation is one email, which is plenty for a clinic network; if one day is exceeded, Google blocks sending until the next day (administrators can still use "Reset Pass").

**Two ways to add staff** (Users, Add New Staff, on the website once email works): *Invite by email* for someone with a real personal email (they get a link, valid 7 days, to choose their own password; no temporary password exists, and opening it confirms the address), or *No real email* (placeholder sign-in email and a temporary password as before; a recovery email can be added later).

How it works for staff: an administrator (or the user, in *My Profile* with their password) sets a **recovery email**; a confirmation link (24 hours, works once) is sent to it. A reset is only ever sent to a **confirmed** recovery email, never to the sign-in email (many sign-in emails are placeholders). The reset link lasts 60 minutes and works once. If someone asks for a reset and has no confirmed recovery email, nothing is sent and an audit entry `reset_requested_no_recovery_email` is written for the administrators.

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
6. **After pressing *Publish release*, check what clinics will actually receive.** No token needed, nothing is installed:
   ```powershell
   cd electron
   node scripts/verify-update.js 1.1.4
   ```
   It fetches the public `latest.yml`, confirms the version, that the release is neither a draft nor a pre-release, that the installer's size and sha512 match `latest.yml`, that the `.blockmap` exists, and that `electron-builder.yml` publishes to `davokinga17/vilocare-releases`. It deletes its temporary download and exits with an error and a plain message on any failure. (A draft is not public, so run it only after publishing.)

## 6. What the previous maintainer must remove

- Their GitHub tokens (anything used as `GH_TOKEN` for the releases repository): revoke them.
- Local `.env` files, saved sync tokens and any database dump or backup copies on their own machines.
- The old Render web service and database, only after cutover step 9.
- They remain a collaborator on the source repository for as long as the owner wishes; nothing else to remove there.
