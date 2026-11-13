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
  quit: () => ipcRenderer.invoke('app:quit'),
  fullscreen: on => ipcRenderer.invoke('win:full', on),
  isFullscreen: () => ipcRenderer.invoke('win:isFull')
});
