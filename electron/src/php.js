const fs = require('fs');
const net = require('net');
const http = require('http');
const path = require('path');
const { spawn, execFileSync } = require('child_process');
const p = require('./paths');

const artisanFile = path.join(p.appDir, 'artisan');
const logFile = (name) => path.join(p.logsDir, name);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// php.exe's child processes (schedule:run) don't get our -d flags, so the extension_dir must live in a php.ini
// that PHP finds on its own: PHPRC points at this generated copy of the bundled ini.
const runtimeIniDir = path.join(p.dataDir, 'php');
function writeRuntimeIni() {
  if (!p.phpIni) return;
  fs.mkdirSync(runtimeIniDir, { recursive: true });
  const base = fs.readFileSync(p.phpIni, 'utf8');
  fs.writeFileSync(path.join(runtimeIniDir, 'php.ini'), `extension_dir = "${p.phpExtDir}"\r\n${base}`);
}

function ensureDirs() {
  for (const d of [
    p.dataDir,
    p.logsDir,
    p.backupsDir,
    p.bootstrapCacheDir,
    path.join(p.storageDir, 'app', 'public'),
    path.join(p.storageDir, 'framework', 'cache', 'data'),
    path.join(p.storageDir, 'framework', 'sessions'),
    path.join(p.storageDir, 'framework', 'views'),
  ]) {
    fs.mkdirSync(d, { recursive: true });
  }
  writeRuntimeIni();
}

// Environment handed to every PHP process. Real env vars win over any .env file, so the
// user .env in the data folder (plus the path overrides below) fully configures Laravel.
// Laravel only treats paths starting with / or \ as absolute for APP_*_CACHE, so drop the drive letter;
// it then resolves against the process cwd drive, which is the data folder's (cwd: p.dataDir below).
const noDrive = (x) => x.replace(/^[A-Za-z]:/, '');

// Set once by main.js: the app version (shown in the Sync Center and sent to the server as X-Client-Version) and a
// random per-run secret that signs administrator update approvals. Neither comes from, or is written to, the .env.
const runtime = { appVersion: null, updateSecret: null };
function configureRuntime(values) {
  Object.assign(runtime, values);
}

function phpEnv(port) {
  // The user .env is NOT copied into the environment: Laravel loads it itself from VILOCARE_ENV_DIR
  // (bootstrap/app.php) on every PHP process, so changes saved on the Sync connection page apply
  // to the next request / scheduled sync without restarting anything.
  const env = { ...process.env };
  Object.assign(env, {
    VILOCARE_ENV_DIR: p.dataDir,
    APP_URL: `http://127.0.0.1:${port}`,
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: p.dbFile,
    LARAVEL_STORAGE_PATH: p.storageDir,
    VIEW_COMPILED_PATH: path.join(p.storageDir, 'framework', 'views'),
    // Sessions and cache live in files in the data folder, not in SQLite: otherwise every page view is a database
    // write that queues behind the scheduled sync. (Changing this once logs everyone out.)
    SESSION_DRIVER: 'file',
    CACHE_STORE: 'file',
    // WAL lets pages read while a sync writes; the short busy timeout makes a stuck writer fail fast instead of
    // freezing the single-threaded server for a minute.
    DB_JOURNAL_MODE: 'WAL',
    DB_BUSY_TIMEOUT: '5000',
    DB_SYNCHRONOUS: 'NORMAL',
    APP_CONFIG_CACHE: noDrive(path.join(p.bootstrapCacheDir, 'config.php')),
    APP_ROUTES_CACHE: noDrive(path.join(p.bootstrapCacheDir, 'routes.php')),
    APP_EVENTS_CACHE: noDrive(path.join(p.bootstrapCacheDir, 'events.php')),
    APP_PACKAGES_CACHE: noDrive(path.join(p.bootstrapCacheDir, 'packages.php')),
    APP_SERVICES_CACHE: noDrive(path.join(p.bootstrapCacheDir, 'services.php')),
    ...(p.phpIni ? { PHPRC: runtimeIniDir } : {}),
    VILOCARE_CA_BUNDLE: path.join(p.appDir, 'certs', 'cacert.pem'),
    ...(runtime.appVersion ? { VILOCARE_APP_VERSION: runtime.appVersion } : {}),
    ...(runtime.updateSecret ? { VILOCARE_UPDATE_SECRET: runtime.updateSecret } : {}),
  });
  return env;
}

function phpArgs(extra = [], iniOverrides = []) {
  const args = [];
  for (const o of iniOverrides) args.push('-d', o);
  return [...args, ...extra];
}

function killTree(pid) {
  if (!pid) return;
  try {
    execFileSync('taskkill', ['/PID', String(pid), '/T', '/F'], { stdio: 'ignore', windowsHide: true });
  } catch {
    /* already gone */
  }
}

function imageName(pid) {
  try {
    const out = execFileSync('tasklist', ['/FI', `PID eq ${pid}`, '/FO', 'CSV', '/NH'], { windowsHide: true }).toString();
    const m = out.match(/^"([^"]+)"/);
    return m ? m[1].toLowerCase() : null;
  } catch {
    return null;
  }
}

// pid bookkeeping lets a later launch clean up PHP processes left behind by a crashed Electron.
function savePids(pids) {
  try {
    fs.writeFileSync(p.pidsFile, JSON.stringify(pids));
  } catch {
    /* non-fatal */
  }
}

function killStaleChildren() {
  try {
    const pids = JSON.parse(fs.readFileSync(p.pidsFile, 'utf8'));
    for (const pid of pids) if (imageName(pid) === 'php.exe') killTree(pid);
  } catch {
    /* none recorded */
  }
  savePids([]);
}

class Supervised {
  constructor(name, getArgs, getEnv, onFatal) {
    this.name = name;
    this.getArgs = getArgs;
    this.getEnv = getEnv;
    this.onFatal = onFatal;
    this.child = null;
    this.stopped = true;
    this.crashes = 0;
    this.timer = null;
  }

  start() {
    this.stopped = false;
    this.crashes = 0;
    this.spawnOnce();
  }

  spawnOnce() {
    const out = fs.openSync(logFile(`${this.name}.log`), 'a');
    const startedAt = Date.now();
    const child = spawn(p.phpExe, this.getArgs(), {
      cwd: p.dataDir,
      env: this.getEnv(),
      windowsHide: true,
      stdio: ['ignore', out, out],
    });
    fs.closeSync(out);
    this.child = child;
    registry.add(child.pid);

    child.on('error', (e) => {
      fs.appendFileSync(logFile(`${this.name}.log`), `\n[launcher] spawn error: ${e.message}\n`);
    });
    child.on('exit', (code) => {
      registry.delete(child.pid);
      if (this.stopped || this.child !== child) return;
      this.crashes = Date.now() - startedAt < 15000 ? this.crashes + 1 : 0;
      fs.appendFileSync(logFile(`${this.name}.log`), `\n[launcher] ${this.name} exited (code ${code}); restart #${this.crashes}\n`);
      if (this.crashes >= 5) {
        this.stopped = true;
        this.onFatal(this.name);
        return;
      }
      this.timer = setTimeout(() => !this.stopped && this.spawnOnce(), Math.min(30000, 2000 * 2 ** this.crashes));
    });
  }

  stop() {
    this.stopped = true;
    clearTimeout(this.timer);
    const pid = this.child && this.child.pid;
    this.child = null;
    killTree(pid);
    registry.delete(pid);
  }
}

const registry = {
  pids: new Set(),
  add(pid) {
    if (!pid) return;
    this.pids.add(pid);
    savePids([...this.pids]);
  },
  delete(pid) {
    this.pids.delete(pid);
    savePids([...this.pids]);
  },
};

function freePort(preferred) {
  const tryPort = (port) =>
    new Promise((resolve) => {
      const s = net.createServer();
      s.once('error', () => resolve(null));
      s.listen(port, '127.0.0.1', () => s.close(() => resolve(port)));
    });
  return (async () => {
    if (preferred && (await tryPort(preferred))) return preferred;
    return new Promise((resolve, reject) => {
      const s = net.createServer();
      s.once('error', reject);
      s.listen(0, '127.0.0.1', () => {
        const { port } = s.address();
        s.close(() => resolve(port));
      });
    });
  })();
}

function httpStatus(port, urlPath = '/up') {
  return new Promise((resolve) => {
    const req = http.get({ host: '127.0.0.1', port, path: urlPath, timeout: 3000 }, (res) => {
      res.resume();
      resolve(res.statusCode);
    });
    req.on('error', () => resolve(null));
    req.on('timeout', () => {
      req.destroy();
      resolve(null);
    });
  });
}

async function waitUntilReady(port, isAlive, timeoutMs = 60000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (!isAlive()) throw new Error('The PHP server stopped while starting.');
    const status = await httpStatus(port);
    if (status !== null) {
      if (status >= 500) throw new Error(`The app answered with HTTP ${status} while starting.`);
      return;
    }
    await sleep(400);
  }
  throw new Error('The app did not respond within 60 seconds.');
}

// Asks the running server whether opcache is active (see /__vilocare/opcache in resources/router.php) and returns
// one log line. Never throws: a failed check must not stop the app from starting.
async function opcacheSelfCheck(port) {
  const get = () =>
    new Promise((resolve) => {
      const req = http.get({ host: '127.0.0.1', port, path: '/__vilocare/opcache', timeout: 5000 }, (res) => {
        let body = '';
        res.on('data', (c) => (body += c));
        res.on('end', () => {
          try {
            resolve(res.statusCode === 200 ? JSON.parse(body) : null);
          } catch {
            resolve(null);
          }
        });
      });
      req.on('error', () => resolve(null));
      req.on('timeout', () => {
        req.destroy();
        resolve(null);
      });
    });

  const s = await get();
  if (!s) return 'opcache self-check: WARNING could not query the server (no answer from /__vilocare/opcache)';
  if (s.enabled) {
    return `opcache self-check: ENABLED (PHP ${s.php}, ${s.cached_scripts} scripts cached, ${s.memory_used_mb} MB, JIT ${s.jit_enabled ? 'ON (unexpected)' : 'off'}, timestamps ${s.validate_timestamps ? 'checked' : 'not checked'})`;
  }
  return `opcache self-check: WARNING DISABLED (PHP ${s.php}; extension ${s.loaded ? 'loaded but turned off' : 'NOT loaded: php_opcache.dll missing from the bundle?'}). Pages will be about 3x slower.`;
}

// Runs `php artisan <args>`; resolves { code, output }. Output is also appended to migrate.log.
// `input` (optional) is written to the command's STDIN, so secrets never appear on a command line.
function artisan(args, port, timeoutMs = 300000, input = null) {
  return new Promise((resolve) => {
    const child = spawn(p.phpExe, phpArgs([artisanFile, ...args]), {
      cwd: p.dataDir,
      env: phpEnv(port),
      windowsHide: true,
    });
    registry.add(child.pid);
    child.stdin.on('error', () => {});
    child.stdin.end(input === null ? undefined : input);
    let output = '';
    const grab = (d) => {
      output += d.toString();
      if (output.length > 20000) output = output.slice(-20000);
    };
    child.stdout.on('data', grab);
    child.stderr.on('data', grab);
    const timer = setTimeout(() => killTree(child.pid), timeoutMs);
    const finish = (code) => {
      clearTimeout(timer);
      registry.delete(child.pid);
      try {
        // Never log the output of the admin-creation command (it echoes validation details only, but be strict).
        const shown = args[0] === 'vilocare:first-admin' ? '[not logged]' : output;
        fs.appendFileSync(logFile('artisan.log'), `\n$ artisan ${args.join(' ')} -> ${code}\n${shown}\n`);
      } catch {
        /* non-fatal */
      }
      resolve({ code, output });
    };
    child.on('error', (e) => {
      output += e.message;
      finish(-1);
    });
    child.on('close', finish);
  });
}

// Folds the WAL file back into database.sqlite so the single file is complete (before copying it as a backup).
function checkpointDatabase() {
  if (!fs.existsSync(p.dbFile)) return;
  const code = `$db = new PDO('sqlite:' . ${JSON.stringify(p.dbFile)}); $db->exec('PRAGMA wal_checkpoint(TRUNCATE)');`;
  try {
    execFileSync(p.phpExe, phpArgs(['-r', code]), { env: phpEnv(0), cwd: p.dataDir, windowsHide: true, timeout: 30000, stdio: 'ignore' });
  } catch {
    /* a locked or missing database is handled by the caller's own checks */
  }
}

// Removes leftover WAL/SHM files; needed after replacing database.sqlite by a copy, which they would not match.
function removeWalFiles() {
  for (const ext of ['-wal', '-shm']) fs.rmSync(p.dbFile + ext, { force: true });
}

function createServices(port, onFatal) {
  // max_execution_time stops one stuck request from blocking PHP's single-threaded server forever.
  const server = new Supervised(
    'server',
    () => phpArgs(['-S', `127.0.0.1:${port}`, '-t', path.join(p.appDir, 'public'), p.routerFile], ['max_execution_time=300']),
    () => phpEnv(port),
    onFatal
  );
  const scheduler = new Supervised('scheduler', () => phpArgs([artisanFile, 'schedule:work']), () => phpEnv(port), onFatal);
  return {
    server,
    scheduler,
    stopAll() {
      scheduler.stop();
      server.stop();
      savePids([]);
    },
  };
}

module.exports = {
  configureRuntime,
  ensureDirs,
  killStaleChildren,
  killTree,
  imageName,
  freePort,
  waitUntilReady,
  artisan,
  checkpointDatabase,
  removeWalFiles,
  opcacheSelfCheck,
  createServices,
  logFile,
};
