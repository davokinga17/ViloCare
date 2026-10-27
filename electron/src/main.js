const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { app, BrowserWindow, Tray, Menu, ipcMain, shell, dialog, nativeImage, session } = require('electron');
const p = require('./paths');
const cfg = require('./config');
const php = require('./php');
const permissions = require('./permissions');
const policy = require('./updater-policy');
const { Updater } = require('./updater');

const hiddenStart = process.argv.includes('--hidden');
const user = cfg.userSettings(app);

let startWin = null; // splash / setup / error screens (local files only)
let mainWin = null; // the ViLoCare app itself (http://127.0.0.1:<port>)
let tray = null;
let services = null;
let origin = null;
let quitting = false;
let startupRun = 0;
let pendingSetup = null;
let currentPort = null;
let sessionEnding = false; // Windows shutdown / log-off: never run an installer then
let quitGateDone = false; // the pre-quit sync wait has run
let quitGateWaiting = false;
let installingUpdate = false;
let updateStatusTimer = null;

const log = (msg) => {
  try {
    fs.appendFileSync(php.logFile('electron.log'), `${new Date().toISOString()} ${msg}\n`);
  } catch {
    /* logs dir may not exist yet */
  }
};

// ---------- auto-update (state + download here; install rules below) ----------
const updater = new Updater(log);
// Laravel shows the version, sends it to the central server, and signs administrator update approvals with this secret.
const updateSecret = crypto.randomBytes(32).toString('hex');
php.configureRuntime({ appVersion: app.getVersion(), updateSecret });

// ---------- single instance ----------
if (!app.requestSingleInstanceLock()) {
  app.quit();
} else {
  app.on('second-instance', () => showWindow());
  app.whenReady().then(init);
}

// Keep running in the tray when every window is closed.
app.on('window-all-closed', () => {});

// ---------- shutdown: Quit, tray Quit, Windows shutdown/log-off, crashes ----------
function stopEverything() {
  quitting = true;
  try {
    if (services) services.stopAll();
    releaseLock();
  } catch (e) {
    log(`stop error: ${e.message}`);
  }
}
app.on('before-quit', (e) => {
  // A downloaded update installs when the app quits (autoInstallOnAppQuit). Make sure no sync is running first;
  // if one cannot finish in time, skip the install this time and keep the download for the next quit.
  if (quitGateWaiting) {
    e.preventDefault(); // a second Quit while we wait must not skip the wait
    return;
  }
  if (!quitGateDone && !sessionEnding && !installingUpdate && updater.hasDownloaded()) {
    e.preventDefault();
    quitGateWaiting = true;
    prepareQuitForUpdate().finally(() => {
      quitGateWaiting = false;
      quitGateDone = true;
      app.quit();
    });
    return;
  }
  stopEverything();
});
process.on('exit', stopEverything);
process.on('SIGTERM', () => app.quit());
process.on('uncaughtException', (e) => {
  log(`uncaught: ${e.stack || e}`);
  stopEverything();
  app.exit(1);
});

function watchSessionEnd(win) {
  // Fires when Windows is shutting down or the user logs off.
  win.on('session-end', () => {
    sessionEnding = true;
    updater.skipInstallOnQuit();
    stopEverything();
  });
}

// ---------- windows ----------
// DevTools are off in installed builds, so nobody can call the preload bridges from a console.
const devTools = !p.packaged;
const webPrefsLocal = {
  devTools,
  contextIsolation: true,
  nodeIntegration: false,
  sandbox: true,
  backgroundThrottling: false,
  preload: path.join(__dirname, 'preload.js'),
};

function lockDownContents(wc, allowedOrigin) {
  wc.setWindowOpenHandler(({ url }) => {
    if (allowedOrigin && url.startsWith(allowedOrigin)) setImmediate(() => wc.loadURL(url));
    return { action: 'deny' };
  });
  const guard = (e, url) => {
    if (!allowedOrigin || !(url === allowedOrigin || url.startsWith(allowedOrigin + '/'))) {
      if (!url.startsWith('file://') || allowedOrigin) e.preventDefault();
    }
  };
  wc.on('will-navigate', guard);
  wc.on('will-redirect', guard);
}

function ensureStartWin() {
  if (startWin && !startWin.isDestroyed()) return startWin;
  startWin = new BrowserWindow({
    width: 480,
    height: 520,
    resizable: false,
    maximizable: false,
    show: false,
    title: 'ViLoCare',
    icon: p.asset('icon.png'),
    autoHideMenuBar: true,
    webPreferences: webPrefsLocal,
  });
  startWin.setMenu(null);
  lockDownContents(startWin.webContents, null);
  watchSessionEnd(startWin);
  startWin.on('close', (e) => {
    if (!quitting && mainWin) return; // normal window close handled below
    if (!quitting && !mainWin) {
      // Closing the startup screen before the app is ready quits the app.
      app.quit();
    }
  });
  return startWin;
}

function showStart(file, query = {}) {
  const w = ensureStartWin();
  w.loadFile(p.ui(file), { query });
  if (!hiddenStart || file !== 'splash.html') w.show();
  return w;
}

const status = (text) => {
  log(`status: ${text}`);
  if (startWin && !startWin.isDestroyed()) startWin.webContents.send('status', text);
};

function showError(message, detail = '') {
  log(`error: ${message}\n${detail}`);
  if (services) services.stopAll();
  const w = showStart('error.html', { message, detail: detail.slice(-1500) });
  w.show();
}

function createTray() {
  tray = new Tray(nativeImage.createFromPath(p.asset('tray.png')));
  tray.setToolTip(`ViLoCare ${app.getVersion()}`);
  tray.on('click', () => showWindow());
  const menu = () =>
    Menu.buildFromTemplate([
      { label: `ViLoCare ${app.getVersion()}`, enabled: false },
      { label: policy.statusLabel(updater.getState()), enabled: false },
      { label: 'Check for updates', click: () => updater.check() },
      { type: 'separator' },
      { label: 'Open ViLoCare', click: () => showWindow() },
      { label: 'Sync settings…', click: () => reopenSetup() },
      { label: 'Restart ViLoCare services', click: () => restartServices() },
      { label: 'Open logs folder', click: () => shell.openPath(p.logsDir) },
      {
        label: 'Start with Windows',
        type: 'checkbox',
        checked: user.read().autoStart !== false,
        click: (item) => setAutoStart(item.checked),
      },
      { type: 'separator' },
      { label: 'Quit ViLoCare', click: () => confirmQuit() },
    ]);
  tray.setContextMenu(menu());
  // The status line is rebuilt whenever the update state changes (and the app window is told, for the notice).
  updater.on('state', (state) => {
    if (tray && !tray.isDestroyed()) tray.setContextMenu(menu());
    if (mainWin && !mainWin.isDestroyed()) mainWin.webContents.send('updater:state', state);
  });
}

// ---------- update install rules ----------
const GATE_WAIT_MS = 2 * 60 * 1000; // longest we wait for a running sync before skipping the install this time

function gate(action, timeoutMs = 30000) {
  return php.artisan(['vilocare:update-gate', action], currentPort, timeoutMs);
}

// Stops new syncs from starting, then waits for a running one to finish. true = safe to install.
async function waitForQuietSync(maxMs = GATE_WAIT_MS) {
  if (!services || currentPort === null) return true; // PHP is not running, so nothing can be syncing
  const deadline = Date.now() + maxMs;
  for (;;) {
    const res = await gate('hold');
    if (res.code === 0) return true;
    if (res.code !== 20) {
      log(`update gate: could not check for a running sync (exit ${res.code}); treating as busy`);
      break;
    }
    if (Date.now() >= deadline) break;
    await new Promise((r) => setTimeout(r, 3000));
  }
  await gate('release').catch(() => {});
  return false;
}

async function prepareQuitForUpdate() {
  log('update: install on quit requested, waiting for any running sync');
  if (await waitForQuietSync()) {
    log('update: no sync running, installing on quit');
  } else {
    updater.skipInstallOnQuit();
    log('update: a sync is still running; install skipped this time, the downloaded update stays for the next quit');
  }
}

// "Restart and update now" (administrator approved): quiet sync, stop PHP and the scheduler, then run the installer.
async function installUpdateNow() {
  if (installingUpdate) return { error: 'The update is already being installed.' };
  if (!updater.hasDownloaded()) return { error: 'No update is ready yet.' };
  installingUpdate = true;
  log('update: administrator approved "restart and update now"');
  if (!(await waitForQuietSync())) {
    installingUpdate = false;
    log('update: a sync is still running; not installing now');
    return { error: 'A sync is still running. Try again in a minute, or the update installs when ViLoCare is next closed.' };
  }
  stopEverything(); // a running php.exe would block file replacement
  log('update: PHP server and scheduler stopped, starting installer');
  updater.quitAndInstall();
  return { ok: true };
}

// Asks the app whether the central server demanded a newer version, and what the sync state is.
async function refreshUpdateRequired() {
  if (!services || currentPort === null || installingUpdate) return;
  try {
    const res = await gate('status', 20000);
    const info = JSON.parse(res.output.trim().split(/\r?\n/).pop());
    updater.setUpdateRequired(!!info.update_required);
    if (info.update_required) updater.check(); // the server wants a newer version: look for it now
  } catch {
    /* not important: the tray line just keeps its previous text */
  }
}

function showWindow() {
  const w = mainWin && !mainWin.isDestroyed() ? mainWin : startWin;
  if (!w || w.isDestroyed()) return;
  if (w.isMinimized()) w.restore();
  w.show();
  w.focus();
}

async function confirmQuit() {
  const { response } = await dialog.showMessageBox({
    type: 'question',
    buttons: ['Quit ViLoCare', 'Cancel'],
    defaultId: 1,
    cancelId: 1,
    title: 'Quit ViLoCare',
    message: 'Quit ViLoCare?',
    detail: 'Syncing, SMS reminders and scheduled tasks will stop until ViLoCare is opened again.',
  });
  if (response === 0) app.quit();
}

function setAutoStart(enabled) {
  user.update({ autoStart: enabled });
  applyAutoStart();
}

function applyAutoStart() {
  if (!app.isPackaged) return;
  app.setLoginItemSettings({
    openAtLogin: user.read().autoStart !== false, // on by default
    path: process.execPath,
    args: ['--hidden'],
  });
}

// ---------- single-machine lock (several Windows accounts share the data folder) ----------
function acquireLock() {
  try {
    const held = JSON.parse(fs.readFileSync(p.lockFile, 'utf8'));
    if (held.pid !== process.pid && php.imageName(held.pid) === path.basename(process.execPath).toLowerCase()) {
      return `ViLoCare is already running for another Windows user (${held.user || 'unknown'}). Close it there first, or sign that user out.`;
    }
  } catch {
    /* no lock or unreadable: take it */
  }
  fs.writeFileSync(p.lockFile, JSON.stringify({ pid: process.pid, user: process.env.USERNAME || '' }));
  return null;
}

function releaseLock() {
  try {
    const held = JSON.parse(fs.readFileSync(p.lockFile, 'utf8'));
    if (held.pid === process.pid) fs.rmSync(p.lockFile, { force: true });
  } catch {
    /* ignore */
  }
}

// ---------- database safety ----------
const cmpVersion = policy.cmpVersion; // understands pre-releases such as 1.1.1-beta.1

function backupDatabase(reason) {
  if (!fs.existsSync(p.dbFile) || fs.statSync(p.dbFile).size === 0) return null;
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  const dest = path.join(p.backupsDir, `database-${stamp}-${reason}.sqlite`);
  php.checkpointDatabase(); // WAL mode: commit everything into the main file first, or the copy would miss recent records
  fs.copyFileSync(p.dbFile, dest);
  const all = fs.readdirSync(p.backupsDir).filter((f) => f.startsWith('database-')).sort();
  for (const old of all.slice(0, Math.max(0, all.length - 10))) fs.rmSync(path.join(p.backupsDir, old), { force: true });
  log(`backup: ${dest}`);
  return dest;
}

async function migrateSafely(port) {
  const prevVersion = cfg.state.read().appVersion;
  const pending = await php.artisan(['migrate:status', '--pending', '--no-interaction'], port, 60000);
  const hasPending = pending.code !== 0 || !/no pending|nothing to migrate/i.test(pending.output);
  const versionChanged = prevVersion !== app.getVersion();
  log(`migrate: version ${prevVersion || '(first run)'} -> ${app.getVersion()}, pending migrations: ${hasPending ? 'yes' : 'no'}`);
  const backup = hasPending || versionChanged ? backupDatabase(hasPending ? 'pre-migrate' : 'pre-update') : null;

  const res = await php.artisan(['migrate', '--force', '--no-interaction'], port);
  if (res.code === 0) log('migrate: ok');
  if (res.code !== 0) {
    if (backup) {
      php.removeWalFiles();
      fs.copyFileSync(backup, p.dbFile);
      log('migration failed; database restored from backup');
    }
    throw Object.assign(new Error('Updating the database failed. Your data was restored from the backup taken just before.'), { detail: res.output });
  }
  cfg.state.update({ appVersion: app.getVersion() });
}

// ---------- startup ----------
function setupPrompt() {
  showStart('setup.html').show();
  return new Promise((resolve) => {
    pendingSetup = resolve;
  });
}

// Exit code 0 = no users yet, 10 = users exist (see FirstAdminCommand). Anything else is a real failure.
async function isUsersTableEmpty(port) {
  const res = await php.artisan(['vilocare:first-admin', '--check'], port, 60000);
  if (res.code === 0) return true;
  if (res.code === 10) return false;
  throw Object.assign(new Error('Could not check whether this computer has any users.'), { detail: res.output });
}

let pendingAdmin = null;
function adminPrompt(port) {
  adminPort = port;
  showStart('admin.html').show();
  return new Promise((resolve) => {
    pendingAdmin = resolve;
  });
}
let adminPort = null;

ipcMain.handle('admin:create', async (_e, { name, email, password, confirmation }) => {
  if (!pendingAdmin) return { error: 'This step is only available during first-time setup.' };
  const res = await php.artisan(
    ['vilocare:first-admin'],
    adminPort,
    60000,
    JSON.stringify({ name, email, password, password_confirmation: confirmation })
  );
  let parsed = null;
  try {
    parsed = JSON.parse(res.output.trim().split(/\r?\n/).pop());
  } catch {
    /* fall through */
  }
  if (res.code === 0 && parsed && parsed.ok) {
    pendingAdmin();
    pendingAdmin = null;
    return { ok: true };
  }
  if (res.code === 10) {
    // Someone/something created a user in the meantime: nothing more to do here.
    pendingAdmin();
    pendingAdmin = null;
    return { ok: true };
  }
  const errors = parsed && parsed.errors ? parsed.errors.join(' ') : 'The administrator could not be created.';
  return { error: errors };
});

ipcMain.handle('setup:save', (_e, { url, token }) => {
  let parsed;
  try {
    parsed = new URL(url);
  } catch {
    return { error: 'Enter a valid URL, for example https://server.example.com/api/v1/sync' };
  }
  if (!/^https?:$/.test(parsed.protocol)) return { error: 'The URL must start with http:// or https://' };
  if (!token) return { error: 'Enter the sync token.' };
  try {
    cfg.setEnvValues({ VILOCARE_REMOTE_SYNC_URL: url.replace(/\/+$/, ''), VILOCARE_SYNC_API_TOKEN: token });
  } catch (e) {
    return { error: 'The URL or token contains characters that are not allowed (quotes, backslashes or line breaks).' };
  }
  cfg.state.update({ setupSkipped: false });
  if (pendingSetup) {
    pendingSetup('saved');
    pendingSetup = null;
  } else if (startWin && !startWin.isDestroyed()) {
    // Opened later from the tray: PHP re-reads .env on every request, so nothing needs restarting.
    startWin.hide();
  }
  return { ok: true };
});

ipcMain.handle('setup:skip', () => {
  cfg.state.update({ setupSkipped: true });
  if (pendingSetup) {
    pendingSetup('skipped');
    pendingSetup = null;
  } else if (mainWin) {
    startWin.hide();
  }
});
// ---- update bridge (preload-app.js). Every call must come from the top frame of the app window itself. ----
ipcMain.handle('updater:get-state', (e) => {
  if (!policy.isFromAppWindow(e, mainWin && !mainWin.isDestroyed() ? mainWin.webContents : null, origin)) return null;
  return updater.getState();
});
ipcMain.handle('updater:approve', async (e, token) => {
  if (!policy.isFromAppWindow(e, mainWin && !mainWin.isDestroyed() ? mainWin.webContents : null, origin)) {
    log('update: approval rejected (sender is not the app window)');
    return { error: 'Not allowed.' };
  }
  // The signature proves Laravel issued it to a logged-in ADMINISTRATOR within the last minute.
  if (!policy.verifyApprovalToken(token, updateSecret)) {
    log('update: approval rejected (missing, expired or invalid administrator approval)');
    return { error: 'The approval expired. Try again.' };
  }
  return installUpdateNow();
});
ipcMain.handle('app:retry', () => startup());
ipcMain.handle('app:open-logs', () => shell.openPath(p.logsDir));
ipcMain.handle('app:quit', () => app.quit());

function reopenSetup() {
  showStart('setup.html').show();
}

async function restartServices() {
  if (!services) return startup();
  await startup();
}

async function startup() {
  const run = ++startupRun;
  try {
    if (services) services.stopAll();
    if (!hiddenStart || startWin) showStart('splash.html', { status: 'Preparing…' });

    php.ensureDirs();
    const lockProblem = acquireLock();
    if (lockProblem) return showError(lockProblem);
    php.killStaleChildren();

    const stored = cfg.state.read().appVersion;
    if (stored && cmpVersion(app.getVersion(), stored) < 0) {
      log(`version guard: BLOCKED ${app.getVersion()} is older than the data's ${stored}`);
      return showError(`This is an older version of ViLoCare (${app.getVersion()}) than the one that last used this data (${stored}). Install the newer version.`);
    }

    log(`version guard: ok (app ${app.getVersion()}, data last used by ${stored || 'nothing yet'})`);
    const firstRun = cfg.ensureEnvFile() || !fs.existsSync(p.dbFile);
    log(`device id: ${cfg.ensureDeviceId()}`);
    if (cfg.needsSetup() && !cfg.state.read().setupSkipped) {
      await setupPrompt();
      showStart('splash.html', { status: 'Preparing…' });
    }

    if (!fs.existsSync(p.dbFile)) fs.writeFileSync(p.dbFile, '');

    const prefPort = user.read().lastPort;
    const port = await php.freePort(prefPort);
    user.update({ lastPort: port });
    origin = `http://127.0.0.1:${port}`;
    currentPort = port;

    status('Updating the database…');
    await migrateSafely(port);
    if (run !== startupRun) return;

    // Step 1 (above) is optional. If sync is configured, try one sync first: on a new computer it pulls
    // the existing users and locations, which makes step 2 unnecessary. Failure is not fatal.
    let usersEmpty = await isUsersTableEmpty(port);
    if (!cfg.needsSetup() && (firstRun || usersEmpty)) {
      status('Fetching users and locations from the central server…');
      await php.artisan(['vilocare:sync', '--full'], port, 120000);
      usersEmpty = await isUsersTableEmpty(port);
    }

    // Step 2 (required): only while the users table is still empty (sync skipped or failed).
    if (usersEmpty) {
      await adminPrompt(port);
      if (run !== startupRun) return;
      showStart('splash.html', { status: 'Starting ViLoCare…' });
    }

    status('Starting ViLoCare…');
    services = php.createServices(port, (name) => {
      showError(`The ${name} process keeps stopping. See the logs for details.`, tailLog(`${name}.log`));
    });
    services.server.start();
    await php.waitUntilReady(port, () => !!(services.server.child && services.server.child.exitCode === null));
    services.scheduler.start();
    // Logged to electron.log on every start, so a build that lost opcache is noticed at once. Does not delay startup.
    php.opcacheSelfCheck(port).then(log, () => {});
    // A hold left over from an update that did not go through must not keep syncs from starting.
    gate('release').then(() => refreshUpdateRequired(), () => {});
    if (!updateStatusTimer) {
      updateStatusTimer = setInterval(refreshUpdateRequired, 10 * 60 * 1000);
      updateStatusTimer.unref();
    }

    openMainWindow();
  } catch (e) {
    showError(e.message || String(e), e.detail || tailLog('server.log'));
  }
}

function tailLog(name) {
  try {
    return fs.readFileSync(php.logFile(name), 'utf8').slice(-1500);
  } catch {
    return '';
  }
}

function openMainWindow() {
  if (mainWin && !mainWin.isDestroyed()) {
    mainWin.loadURL(origin);
  } else {
    mainWin = new BrowserWindow({
      width: 1366,
      height: 860,
      show: false,
      title: 'ViLoCare',
      icon: p.asset('icon.png'),
      autoHideMenuBar: true,
      webPreferences: {
        devTools,
        contextIsolation: true,
        nodeIntegration: false,
        sandbox: true,
        backgroundThrottling: false, // keep timers (auto-sync, polling) running while hidden
        preload: path.join(__dirname, 'preload-app.js'), // update notice bridge only (see preload-app.js)
      },
    });
    mainWin.setMenu(null);
    lockDownContents(mainWin.webContents, origin);
    watchSessionEnd(mainWin);
    let noticeShown = user.read().trayNoticeShown;
    mainWin.on('close', (e) => {
      if (quitting) return;
      e.preventDefault();
      mainWin.hide();
      if (!noticeShown && tray) {
        noticeShown = true;
        user.update({ trayNoticeShown: true });
        tray.displayBalloon({ title: 'ViLoCare is still running', content: 'It keeps syncing in the background. Use the tray icon to open or quit it.' });
      }
    });
    mainWin.once('ready-to-show', () => {
      if (!hiddenStart || (startWin && !startWin.isDestroyed() && startWin.isVisible())) mainWin.show();
    });
    mainWin.loadURL(origin);
  }
  if (startWin && !startWin.isDestroyed()) startWin.hide();
}

function init() {
  // Deny every permission and origin, except the camera (video only) for our own http://127.0.0.1:<port> page.
  permissions.install(session.defaultSession, () => origin, () => (mainWin && !mainWin.isDestroyed() ? mainWin.webContents : null));
  app.setAppUserModelId('org.vilocare.desktop');
  createTray();
  applyAutoStart();
  updater.init();
  startup();
}
