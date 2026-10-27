const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const p = require('./paths');

function readJson(file, fallback) {
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch {
    return fallback;
  }
}

function writeJson(file, value) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, JSON.stringify(value, null, 2));
}

// Machine-wide state (data folder) and per-Windows-user settings (Electron userData).
const state = {
  read: () => readJson(p.stateFile, {}),
  update(patch) {
    writeJson(p.stateFile, { ...state.read(), ...patch });
  },
};

function userSettingsFile(app) {
  return path.join(app.getPath('userData'), 'settings.json');
}
const userSettings = (app) => ({
  read: () => readJson(userSettingsFile(app), {}),
  update(patch) {
    writeJson(userSettingsFile(app), { ...readJson(userSettingsFile(app), {}), ...patch });
  },
});

function parseEnv(text) {
  const out = {};
  for (const line of text.split(/\r?\n/)) {
    const m = line.match(/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/);
    if (!m) continue;
    let v = m[2].trim();
    if (v.length >= 2 && v.startsWith('"') && v.endsWith('"')) v = v.slice(1, -1);
    else if (v.length >= 2 && v.startsWith("'") && v.endsWith("'")) v = v.slice(1, -1);
    else v = v.replace(/\s+#.*$/, '');
    out[m[1]] = v;
  }
  return out;
}

function readEnv() {
  try {
    return parseEnv(fs.readFileSync(p.envFile, 'utf8'));
  } catch {
    return {};
  }
}

// Creates the user .env from the template (with a fresh APP_KEY) if it does not exist yet.
function ensureEnvFile() {
  if (fs.existsSync(p.envFile)) return false;
  const template = fs.readFileSync(p.envTemplate, 'utf8');
  const key = 'base64:' + crypto.randomBytes(32).toString('base64');
  fs.writeFileSync(p.envFile, template.replace(/^APP_KEY=.*$/m, `APP_KEY=${key}`));
  return true;
}

// Updates or appends KEY="value" lines, preserving everything else in the file.
function setEnvValues(values) {
  let text = fs.existsSync(p.envFile) ? fs.readFileSync(p.envFile, 'utf8') : '';
  for (const [key, value] of Object.entries(values)) {
    if (/["\\\r\n]/.test(value)) throw new Error(`Invalid characters in ${key}`);
    const line = `${key}="${value}"`;
    const re = new RegExp(`^${key}=.*$`, 'm');
    text = re.test(text) ? text.replace(re, () => line) : text.replace(/\s*$/, '\n') + line + '\n';
  }
  fs.writeFileSync(p.envFile, text);
}

const DEFAULT_DEVICE_ID = 'clinic-desktop-01';

// Every installation needs its own ID (shown on the Sync Center and on the central server). Generated once at first-run
// setup; an existing install that still has the shared default (or none) gets one the first time it starts. A value that
// was set or edited in Settings is never touched. Returns the ID in use.
function ensureDeviceId() {
  const current = (readEnv().VILOCARE_DEVICE_ID || '').trim();
  if (current && current !== DEFAULT_DEVICE_ID) return current;
  const id = 'clinic-' + crypto.randomBytes(4).toString('hex');
  setEnvValues({ VILOCARE_DEVICE_ID: id });
  return id;
}

function needsSetup() {
  const env = readEnv();
  return !env.VILOCARE_REMOTE_SYNC_URL || !env.VILOCARE_SYNC_API_TOKEN;
}

module.exports = { state, userSettings, readEnv, ensureEnvFile, ensureDeviceId, DEFAULT_DEVICE_ID, setEnvValues, needsSetup };
