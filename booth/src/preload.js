const { contextBridge, ipcRenderer } = require('electron');
contextBridge.exposeInMainWorld('booth', {
  config: () => ipcRenderer.invoke('cfg:get'),
  saveConfig: c => ipcRenderer.invoke('cfg:set', c),
  camInit: () => ipcRenderer.invoke('cam:init'),
  liveStart: () => ipcRenderer.invoke('cam:liveStart'),
  liveStop: () => ipcRenderer.invoke('cam:liveStop'),
  liveFrame: () => ipcRenderer.invoke('cam:liveFrame'),     /* data:image/jpeg URL vagy null */
  capture: () => ipcRenderer.invoke('cam:capture'),         /* data:image/jpeg URL (a teljes felbontású kép) vagy null (próbamód) */
  saveShot: dataUrl => ipcRenderer.invoke('shot:save', dataUrl),
  printers: () => ipcRenderer.invoke('print:list'),
  print: (dataUrl, o) => ipcRenderer.invoke('print:do', dataUrl, o),
  log: (m) => ipcRenderer.invoke('log', m),
  events: d => ipcRenderer.invoke('cloud:events', d),
  template: deal => ipcRenderer.invoke('cloud:template', deal),
  keep: (ev, deal, date, photo, dataUrl, meta) => ipcRenderer.invoke('cloud:keep', ev, deal, date, photo, dataUrl, meta),
  list: ev => ipcRenderer.invoke('cloud:list', ev),
  printq: (method, p, body) => ipcRenderer.invoke('cloud:printq', method, p, body),
  readImage: f => ipcRenderer.invoke('cloud:read', f),
  qr: url => ipcRenderer.invoke('cloud:qr', url),
  cloudStatus: () => ipcRenderer.invoke('cloud:status'),
  newTok: n => ipcRenderer.invoke('cloud:tok', n),
  urls: (ev, ph) => ipcRenderer.invoke('cloud:urls', ev, ph),
  quit: () => ipcRenderer.invoke('app:quit'),
  fullscreen: on => ipcRenderer.invoke('win:full', on),
  isFullscreen: () => ipcRenderer.invoke('win:isFull'),
  reloadUi: () => ipcRenderer.invoke('ui:reload'),
  uiInfo: () => ipcRenderer.invoke('ui:info'),
  onUiReady: cb => ipcRenderer.on('ui:ready', () => cb())
});
