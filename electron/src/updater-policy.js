// Pure rules for the auto-updater (no Electron imports, so they are unit-tested with plain Node: test/updater-policy.test.js).
const crypto = require('crypto');

const APPROVAL_PURPOSE = 'approve-update'; // must match AppUpdateController::PURPOSE in Laravel

/** "1.1.1-beta.1" -> { core: [1,1,1], pre: ['beta', 1] }. Build metadata (+x) is ignored. */
function parseVersion(v) {
  const [main, ...rest] = String(v).trim().replace(/^v/i, '').split('+')[0].split('-');
  const core = main.split('.').map((n) => Number(n) || 0);
  while (core.length < 3) core.push(0);
  const pre = rest.length ? rest.join('-').split('.').map((p) => (/^\d+$/.test(p) ? Number(p) : p)) : [];
  return { core, pre };
}

/** Semver order: a pre-release is lower than its release (1.1.1-beta.1 < 1.1.1). Returns <0, 0 or >0. */
function cmpVersion(a, b) {
  const x = parseVersion(a);
  const y = parseVersion(b);
  for (let i = 0; i < 3; i++) if (x.core[i] !== y.core[i]) return x.core[i] - y.core[i];
  if (!x.pre.length && !y.pre.length) return 0;
  if (!x.pre.length) return 1;
  if (!y.pre.length) return -1;
  for (let i = 0; i < Math.max(x.pre.length, y.pre.length); i++) {
    const p = x.pre[i];
    const q = y.pre[i];
    if (p === undefined) return -1;
    if (q === undefined) return 1;
    if (p === q) continue;
    if (typeof p === 'number' && typeof q === 'number') return p - q;
    if (typeof p === 'number') return -1; // numeric identifiers sort below text ones
    if (typeof q === 'number') return 1;
    return p < q ? -1 : 1;
  }
  return 0;
}

const isPrerelease = (v) => parseVersion(v).pre.length > 0;

/**
 * Checks the signed approval Laravel hands to administrators ("<expiresUnix>.<hmac>"). Only a PHP process that was
 * started by this app holds `secret`, and only an administrator session can obtain a token from it.
 */
function verifyApprovalToken(token, secret, nowMs = Date.now()) {
  if (!secret || typeof token !== 'string') return false;
  const m = /^(\d{9,12})\.([0-9a-f]{64})$/.exec(token);
  if (!m) return false;
  const expires = Number(m[1]);
  if (expires * 1000 < nowMs) return false;
  if (expires * 1000 > nowMs + 5 * 60 * 1000) return false; // far-future tokens are not something Laravel issues
  const expected = crypto.createHmac('sha256', secret).update(`${APPROVAL_PURPOSE}|${expires}`).digest();
  return crypto.timingSafeEqual(expected, Buffer.from(m[2], 'hex'));
}

function sameAppOrigin(url, appOrigin) {
  try {
    const u = new URL(url);
    const o = new URL(appOrigin);
    return u.protocol === 'http:' && u.hostname === '127.0.0.1' && o.hostname === '127.0.0.1' && u.port === o.port;
  } catch {
    return false;
  }
}

/** True only when an IPC message comes from the top frame of the app window, showing the app's own page. */
function isFromAppWindow(event, mainContents, appOrigin) {
  if (!event || !mainContents || !appOrigin) return false;
  if (event.sender !== mainContents) return false;
  const frame = event.senderFrame;
  if (!frame || frame.parent) return false; // sub-frames never qualify
  return sameAppOrigin(frame.url, appOrigin);
}

/** One line for the tray menu. Errors are never shown: a failed check just keeps the previous state. */
function statusLabel(s) {
  if (s.state === 'ready') return `Update ${s.version || ''} ready – installs when ViLoCare closes`.replace('  ', ' ');
  if (s.state === 'downloading') return `Downloading update${s.version ? ' ' + s.version : ''}${Number.isFinite(s.percent) ? ` (${Math.round(s.percent)}%)` : ''}…`;
  if (s.updateRequired) return 'Update required – sync is paused';
  if (s.state === 'checking') return 'Checking for updates…';
  if (s.state === 'up-to-date') return 'Up to date';
  return 'Updates: not checked yet';
}

module.exports = { APPROVAL_PURPOSE, parseVersion, cmpVersion, isPrerelease, verifyApprovalToken, isFromAppWindow, sameAppOrigin, statusLabel };
