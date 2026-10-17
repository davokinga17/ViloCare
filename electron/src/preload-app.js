// Bridge for the ViLoCare app window (http://127.0.0.1:<port>) only. It can read the update state and send an
// administrator's signed approval; every handler in main.js re-checks the sender and the signature.
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('viloUpdater', {
  getState: () => ipcRenderer.invoke('updater:get-state'),
  approve: (token) => ipcRenderer.invoke('updater:approve', String(token)),
  onChange: (cb) => ipcRenderer.on('updater:state', (_e, state) => cb(state)),
});
