const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const Module = require('module');

// src/paths.js needs Electron's `app`; give it a stub so config.js can be exercised outside Electron.
const realLoad = Module._load;
Module._load = function (request, ...rest) {
  if (request === 'electron') return { app: { isPackaged: false, getPath: () => os.tmpdir() } };
  return realLoad.call(this, request, ...rest);
};

function freshConfig(envText) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'vilocare-devid-'));
  process.env.VILOCARE_DATA_DIR = dir;
  for (const k of Object.keys(require.cache)) if (/electron[\\/]src[\\/](config|paths)\.js$/.test(k)) delete require.cache[k];
  const cfg = require('../src/config');
  const p = require('../src/paths');
  if (envText !== null) fs.writeFileSync(p.envFile, envText);
  return { cfg, p, dir };
}

test('first run: an ID is generated once and is not the shared default', () => {
  const { cfg, p } = freshConfig('APP_NAME=Test\n');
  const id = cfg.ensureDeviceId();
  assert.match(id, /^clinic-[0-9a-f]{8}$/);
  assert.notStrictEqual(id, cfg.DEFAULT_DEVICE_ID);
  assert.match(fs.readFileSync(p.envFile, 'utf8'), new RegExp(`VILOCARE_DEVICE_ID="${id}"`));
  assert.strictEqual(cfg.ensureDeviceId(), id, 'a second start keeps the same ID');
});

test('existing install still on the shared default gets one new ID, then keeps it', () => {
  const { cfg } = freshConfig('VILOCARE_DEVICE_ID="clinic-desktop-01"\n');
  const id = cfg.ensureDeviceId();
  assert.notStrictEqual(id, 'clinic-desktop-01');
  assert.strictEqual(cfg.ensureDeviceId(), id);
});

test('an ID edited in Settings is never overwritten', () => {
  const { cfg, p } = freshConfig('VILOCARE_DEVICE_ID="kapoeta-front-desk"\n');
  assert.strictEqual(cfg.ensureDeviceId(), 'kapoeta-front-desk');
  assert.match(fs.readFileSync(p.envFile, 'utf8'), /kapoeta-front-desk/);
});

test('two installations get different IDs', () => {
  const a = freshConfig('APP_NAME=A\n').cfg.ensureDeviceId();
  const b = freshConfig('APP_NAME=B\n').cfg.ensureDeviceId();
  assert.notStrictEqual(a, b);
});
