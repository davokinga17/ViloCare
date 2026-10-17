const fs = require('fs');
const path = require('path');
const { app } = require('electron');

const packaged = app.isPackaged;
const electronRoot = path.join(__dirname, '..');
const resRoot = packaged ? process.resourcesPath : path.join(electronRoot, 'resources');

// Machine-wide data folder: survives updates/uninstalls and works with several Windows accounts.
// Dev builds use electron/.devdata so they never touch real clinic data.
function dataDir() {
  if (process.env.VILOCARE_DATA_DIR) return path.resolve(process.env.VILOCARE_DATA_DIR);
  if (!packaged) return path.join(electronRoot, '.devdata');
  return path.join(process.env.ProgramData || 'C:\\ProgramData', 'ViLoCare');
}

const data = dataDir();
const bundledPhp = path.join(resRoot, 'php', 'php.exe');

module.exports = {
  packaged,
  dataDir: data,
  appDir: packaged ? path.join(resRoot, 'app') : path.join(electronRoot, '..', 'app_backend'),
  phpExe: fs.existsSync(bundledPhp) ? bundledPhp : 'php', // dev falls back to PHP on PATH
  phpIni: fs.existsSync(bundledPhp) ? path.join(resRoot, 'php', 'php.ini') : null,
  phpExtDir: fs.existsSync(bundledPhp) ? path.join(resRoot, 'php', 'ext') : null,
  routerFile: path.join(resRoot, 'router.php'),
  envTemplate: path.join(resRoot, 'env.template'),
  envFile: path.join(data, '.env'),
  dbFile: path.join(data, 'database.sqlite'),
  storageDir: path.join(data, 'storage'),
  logsDir: path.join(data, 'storage', 'logs'),
  backupsDir: path.join(data, 'backups'),
  bootstrapCacheDir: path.join(data, 'bootstrap-cache'),
  stateFile: path.join(data, 'electron-state.json'),
  pidsFile: path.join(data, 'pids.json'),
  lockFile: path.join(data, 'ViLoCare.lock'),
  ui: (name) => path.join(__dirname, 'ui', name),
  asset: (name) => path.join(electronRoot, 'assets', name),
};
