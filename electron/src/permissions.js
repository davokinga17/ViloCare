// Which web permissions the app window may use. Everything is denied except the CAMERA (video only), and only
// for the app's own page: http://127.0.0.1:<the port this run is using>, main frame, in the main window.
// Kept free of Electron imports so it can be unit-tested with plain Node (see test/permissions.test.js).

function isOwnOrigin(candidate, appOrigin) {
  if (!appOrigin || !candidate) return false;
  try {
    const url = new URL(candidate);
    const own = new URL(appOrigin);
    return url.protocol === 'http:' && url.hostname === '127.0.0.1' && own.hostname === '127.0.0.1' && url.port === own.port;
  } catch {
    return false;
  }
}

/**
 * @param {() => (string|null)} getOrigin         the app origin for this run, e.g. "http://127.0.0.1:8123" (null before start)
 * @param {() => (object|null)} getMainContents   the main window's webContents (null when there is none)
 */
function createPermissionHandlers(getOrigin, getMainContents) {
  // Asked when a page calls getUserMedia().
  const request = (webContents, permission, callback, details = {}) => {
    const main = getMainContents();
    const videoOnly = Array.isArray(details.mediaTypes) && details.mediaTypes.length > 0 && details.mediaTypes.every((t) => t === 'video');
    callback(
      permission === 'media' &&
        !!main &&
        webContents === main &&
        details.isMainFrame !== false &&
        isOwnOrigin(details.requestingUrl, getOrigin()) &&
        videoOnly
    );
  };

  // Asked synchronously (e.g. before listing devices). Never allow a microphone.
  const check = (_webContents, permission, requestingOrigin, details = {}) =>
    permission === 'media' &&
    details.isMainFrame !== false &&
    details.mediaType !== 'audio' &&
    isOwnOrigin(details.requestingUrl || requestingOrigin, getOrigin());

  return { request, check };
}

function install(session, getOrigin, getMainContents) {
  const { request, check } = createPermissionHandlers(getOrigin, getMainContents);
  session.setPermissionRequestHandler(request);
  session.setPermissionCheckHandler(check);
}

module.exports = { isOwnOrigin, createPermissionHandlers, install };
