/* Webkamera (Mac / PC) és próbamód: az élőképet és a fotót a felület adja (getUserMedia), itt csak a mappa készül. */
const fs = require('fs');
const path = require('path');
function make(opts) {
  return {
    kind: 'webcam',
    async init() { fs.mkdirSync(opts.folder, { recursive: true }); return true; },
    async liveStart() {}, async liveStop() {},
    async liveFrame() { return null; },   /* a felület a webkamerát / mintaképet mutatja */
    async capture() { return null; }      /* a felület rögzíti a képet, és a saveShot menti */
  };
}
module.exports = { make };
