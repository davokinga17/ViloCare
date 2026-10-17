const test = require('node:test');
const assert = require('node:assert/strict');
const { createPermissionHandlers, isOwnOrigin } = require('../src/permissions');

const ORIGIN = 'http://127.0.0.1:8123';
const main = { id: 'main' };
const other = { id: 'other' };

function handlers({ origin = ORIGIN, contents = main } = {}) {
  return createPermissionHandlers(() => origin, () => contents);
}

function ask(h, permission, wc, details) {
  let result;
  h.request(wc, permission, (ok) => (result = ok), details);
  return result;
}

const video = { requestingUrl: `${ORIGIN}/profile`, isMainFrame: true, mediaTypes: ['video'] };

test('camera is allowed for the main window on our own origin', () => {
  assert.equal(ask(handlers(), 'media', main, video), true);
  assert.equal(handlers().check(main, 'media', `${ORIGIN}/`, { mediaType: 'video', isMainFrame: true }), true);
});

test('microphone, or camera + microphone, is denied', () => {
  assert.equal(ask(handlers(), 'media', main, { ...video, mediaTypes: ['audio'] }), false);
  assert.equal(ask(handlers(), 'media', main, { ...video, mediaTypes: ['video', 'audio'] }), false);
  assert.equal(ask(handlers(), 'media', main, { ...video, mediaTypes: [] }), false);
  assert.equal(handlers().check(main, 'media', `${ORIGIN}/`, { mediaType: 'audio' }), false);
});

test('every other origin is denied: other port, other host, https, file, localhost, remote site', () => {
  for (const url of ['http://127.0.0.1:9999/', 'http://localhost:8123/', 'https://127.0.0.1:8123/', 'file:///C:/app/splash.html', 'https://example.com/', 'http://127.0.0.1.evil.com:8123/', 'not a url', '']) {
    assert.equal(ask(handlers(), 'media', main, { ...video, requestingUrl: url }), false, url);
    assert.equal(handlers().check(main, 'media', url, { mediaType: 'video' }), false, url);
  }
});

test('other windows and sub-frames are denied', () => {
  assert.equal(ask(handlers(), 'media', other, video), false);
  assert.equal(ask(handlers({ contents: null }), 'media', main, video), false);
  assert.equal(ask(handlers(), 'media', main, { ...video, isMainFrame: false }), false);
  assert.equal(handlers().check(main, 'media', `${ORIGIN}/`, { mediaType: 'video', isMainFrame: false }), false);
});

test('nothing is allowed before the app origin exists', () => {
  assert.equal(ask(handlers({ origin: null }), 'media', main, video), false);
  assert.equal(isOwnOrigin(ORIGIN, null), false);
});

test('every permission other than media is denied, even on our own origin', () => {
  for (const permission of ['geolocation', 'notifications', 'clipboard-read', 'midi', 'display-capture', 'fullscreen', 'openExternal', 'hid', 'usb', 'serial', 'pointerLock']) {
    assert.equal(ask(handlers(), permission, main, video), false, permission);
    assert.equal(handlers().check(main, permission, `${ORIGIN}/`, { requestingUrl: `${ORIGIN}/` }), false, permission);
  }
});
