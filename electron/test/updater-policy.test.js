const test = require('node:test');
const assert = require('node:assert');
const crypto = require('crypto');
const policy = require('../src/updater-policy');

const sign = (secret, expires) => `${expires}.${crypto.createHmac('sha256', secret).update(`approve-update|${expires}`).digest('hex')}`;

test('version order, including pre-releases', () => {
  assert.ok(policy.cmpVersion('1.1.0', '1.0.1') > 0);
  assert.ok(policy.cmpVersion('1.0.1', '1.1.0') < 0);
  assert.strictEqual(policy.cmpVersion('1.1.0', '1.1.0'), 0);
  assert.ok(policy.cmpVersion('1.1.1-beta.1', '1.1.1') < 0);
  assert.ok(policy.cmpVersion('1.1.1-beta.2', '1.1.1-beta.1') > 0);
  assert.ok(policy.cmpVersion('1.1.1-beta.1', '1.1.0') > 0);
  assert.ok(policy.cmpVersion('1.1.1-beta.10', '1.1.1-beta.9') > 0);
  assert.ok(!Number.isNaN(policy.cmpVersion('1.1.1-beta.1', '1.0.1')));
  assert.strictEqual(policy.isPrerelease('1.1.1-beta.1'), true);
  assert.strictEqual(policy.isPrerelease('1.1.0'), false);
});

test('approval token: valid, wrong secret, expired, malformed', () => {
  const now = Date.now();
  const exp = Math.floor(now / 1000) + 60;
  assert.strictEqual(policy.verifyApprovalToken(sign('s3cret', exp), 's3cret', now), true);
  assert.strictEqual(policy.verifyApprovalToken(sign('other', exp), 's3cret', now), false);
  assert.strictEqual(policy.verifyApprovalToken(sign('s3cret', exp - 120), 's3cret', now), false); // expired
  assert.strictEqual(policy.verifyApprovalToken(sign('s3cret', exp + 3600), 's3cret', now), false); // too far ahead
  assert.strictEqual(policy.verifyApprovalToken('nonsense', 's3cret', now), false);
  assert.strictEqual(policy.verifyApprovalToken(undefined, 's3cret', now), false);
  assert.strictEqual(policy.verifyApprovalToken(sign('s3cret', exp), '', now), false); // no secret = never valid
  assert.strictEqual(policy.verifyApprovalToken(`${exp}.${'0'.repeat(64)}`, 's3cret', now), false);
});

test('IPC sender must be the top frame of the app window on its own origin', () => {
  const wc = {};
  const origin = 'http://127.0.0.1:8123';
  const ok = { sender: wc, senderFrame: { parent: null, url: 'http://127.0.0.1:8123/sync' } };
  assert.strictEqual(policy.isFromAppWindow(ok, wc, origin), true);
  assert.strictEqual(policy.isFromAppWindow({ ...ok, sender: {} }, wc, origin), false); // another window
  assert.strictEqual(policy.isFromAppWindow({ ...ok, senderFrame: { parent: {}, url: ok.senderFrame.url } }, wc, origin), false); // iframe
  assert.strictEqual(policy.isFromAppWindow({ ...ok, senderFrame: { parent: null, url: 'http://127.0.0.1:9999/' } }, wc, origin), false);
  assert.strictEqual(policy.isFromAppWindow({ ...ok, senderFrame: { parent: null, url: 'https://evil.example/' } }, wc, origin), false);
  assert.strictEqual(policy.isFromAppWindow({ ...ok, senderFrame: { parent: null, url: 'file:///x/setup.html' } }, wc, origin), false);
  assert.strictEqual(policy.isFromAppWindow(ok, null, origin), false);
  assert.strictEqual(policy.isFromAppWindow(ok, wc, null), false);
});

test('tray status text', () => {
  assert.match(policy.statusLabel({ state: 'ready', version: '1.2.0' }), /Update 1\.2\.0 ready/);
  assert.match(policy.statusLabel({ state: 'downloading', version: '1.2.0', percent: 41.6 }), /42%/);
  assert.strictEqual(policy.statusLabel({ state: 'up-to-date' }), 'Up to date');
  assert.match(policy.statusLabel({ state: 'up-to-date', updateRequired: true }), /Update required/);
  assert.match(policy.statusLabel({ state: 'ready', updateRequired: true }), /ready/); // a downloaded update wins
  assert.strictEqual(policy.statusLabel({ state: 'idle' }), 'Updates: not checked yet');
});
