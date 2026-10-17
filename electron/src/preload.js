// Minimal bridge for the local setup/splash/error pages only (the app window gets no preload).
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('vilo', {
  onStatus: (cb) => ipcRenderer.on('status', (_e, text) => cb(String(text))),
  saveSetup: (url, token) => ipcRenderer.invoke('setup:save', { url: String(url), token: String(token) }),
  createAdmin: (details) => ipcRenderer.invoke('admin:create', details),
  skipSetup: () => ipcRenderer.invoke('setup:skip'),
  retry: () => ipcRenderer.invoke('app:retry'),
  openLogs: () => ipcRenderer.invoke('app:open-logs'),
  quit: () => ipcRenderer.invoke('app:quit'),
});
