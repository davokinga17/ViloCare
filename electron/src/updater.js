// Auto-update (electron-updater against a PUBLIC releases-only GitHub repo; the feed is baked into app-update.yml by
// electron-builder from the `publish` block in electron-builder.yml, so the installed app needs no token).
// This module only downloads and reports state. WHEN an install may run (no sync running, administrator approval,
// PHP stopped first) is decided in main.js.
const { EventEmitter } = require('events');
const { app } = require('electron');
const { autoUpdater } = require('electron-updater');
const policy = require('./updater-policy');

const FIRST_CHECK_DELAY_MS = 60 * 1000; // let the app finish starting first
const CHECK_EVERY_MS = 4 * 60 * 60 * 1000;

class Updater extends EventEmitter {
  constructor(log) {
    super();
    this.log = (msg) => log(`updater: ${msg}`);
    this.enabled = false;
    this.timers = [];
    this.s = { state: 'idle', version: null, percent: null, lastCheckAt: null, updateRequired: false };
  }

  getState() {
    return { ...this.s };
  }

  hasDownloaded() {
    return this.enabled && this.s.state === 'ready';
  }

  set(patch) {
    this.s = { ...this.s, ...patch };
    this.emit('state', this.getState());
  }

  init() {
    const testUrl = process.env.VILOCARE_UPDATE_TEST_URL;
    if (!app.isPackaged) {
      // Dev only: point at a local folder server (scripts/test-update-server.js). A packaged installer never reads
      // this variable, so setting it on a clinic PC cannot redirect updates.
      if (!testUrl) {
        this.log('disabled (development run, no VILOCARE_UPDATE_TEST_URL)');
        return;
      }
      autoUpdater.forceDevUpdateConfig = true;
      autoUpdater.setFeedURL({ provider: 'generic', url: testUrl });
      this.log(`DEV test feed ${testUrl}`);
    } else if (testUrl) {
      this.log('ignoring VILOCARE_UPDATE_TEST_URL: only honoured in unpackaged development runs');
    }

    autoUpdater.logger = {
      info: (m) => this.log(String(m)),
      warn: (m) => this.log(`warn: ${m}`),
      error: (m) => this.log(`error: ${m}`),
      debug: () => {},
    };
    autoUpdater.autoDownload = true;
    autoUpdater.autoInstallOnAppQuit = true; // main.js turns this off when an install must be skipped
    autoUpdater.autoRunAppAfterInstall = true;
    autoUpdater.allowDowngrade = false;
    autoUpdater.disableDifferentialDownload = false; // download only the changed blocks (.blockmap)
    autoUpdater.allowPrerelease = policy.isPrerelease(app.getVersion()); // beta installs follow betas; stable installs never see them

    autoUpdater.on('checking-for-update', () => this.set({ state: 'checking' }));
    autoUpdater.on('update-available', (info) => {
      this.log(`update available: ${info.version}`);
      this.set({ state: 'downloading', version: info.version, percent: 0 });
    });
    autoUpdater.on('update-not-available', () => this.set({ state: 'up-to-date', version: null, lastCheckAt: Date.now() }));
    autoUpdater.on('download-progress', (p) => this.set({ state: 'downloading', percent: p.percent }));
    autoUpdater.on('update-downloaded', (info) => {
      this.log(`update downloaded: ${info.version}`);
      this.set({ state: 'ready', version: info.version, percent: 100, lastCheckAt: Date.now() });
    });
    // Offline, DNS, rate limit, bad feed... Never shown to staff: log it and keep the previous state.
    autoUpdater.on('error', (err) => {
      this.log(`check failed (ignored): ${err && err.message ? err.message : err}`);
      if (this.s.state === 'checking' || this.s.state === 'downloading') {
        this.set({ state: this.s.lastCheckAt ? 'up-to-date' : 'idle', percent: null });
      }
    });

    this.enabled = true;
    this.timers.push(setTimeout(() => this.check(), FIRST_CHECK_DELAY_MS));
    this.timers.push(setInterval(() => this.check(), CHECK_EVERY_MS));
    for (const t of this.timers) t.unref();
  }

  async check() {
    if (!this.enabled || this.s.state === 'downloading' || this.s.state === 'ready') return;
    try {
      await autoUpdater.checkForUpdates();
    } catch (e) {
      this.log(`check failed (ignored): ${e && e.message ? e.message : e}`);
    }
  }

  setUpdateRequired(required) {
    if (this.s.updateRequired !== !!required) this.set({ updateRequired: !!required });
  }

  /** Called when an install must be skipped this time; the downloaded file stays for the next quit. */
  skipInstallOnQuit() {
    if (this.enabled) autoUpdater.autoInstallOnAppQuit = false;
  }

  /** Spawns the installer and quits. The caller has already stopped PHP and confirmed no sync is running. */
  quitAndInstall() {
    autoUpdater.quitAndInstall(true, true); // silent installer, start ViLoCare again afterwards
  }
}

module.exports = { Updater };
