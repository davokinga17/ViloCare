// Serves a folder (default: dist/) over http://127.0.0.1:<port> so the updater can be tested without GitHub.
// Supports Range requests, which differential downloads need.   Usage: node scripts/test-update-server.js [folder] [port]
// Use it with a DEV run only:  $env:VILOCARE_UPDATE_TEST_URL="http://127.0.0.1:8765"; npm start
// (a packaged/installed ViLoCare ignores that variable on purpose).
const http = require('http');
const fs = require('fs');
const path = require('path');

const folder = path.resolve(process.argv[2] || path.join(__dirname, '..', 'dist'));
const port = Number(process.argv[3] || 8765);

http
  .createServer((req, res) => {
    const name = path.basename(decodeURIComponent(new URL(req.url, 'http://x').pathname));
    const file = path.join(folder, name);
    if (!name || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
      res.writeHead(404).end();
      console.log(`404 ${req.url}`);
      return;
    }
    const size = fs.statSync(file).size;
    const range = /^bytes=(\d*)-(\d*)$/.exec(req.headers.range || '');
    if (range) {
      const start = range[1] ? Number(range[1]) : size - Number(range[2]);
      const end = range[1] && range[2] ? Math.min(Number(range[2]), size - 1) : size - 1;
      res.writeHead(206, { 'Content-Range': `bytes ${start}-${end}/${size}`, 'Accept-Ranges': 'bytes', 'Content-Length': end - start + 1 });
      fs.createReadStream(file, { start, end }).pipe(res);
    } else {
      res.writeHead(200, { 'Accept-Ranges': 'bytes', 'Content-Length': size });
      fs.createReadStream(file).pipe(res);
    }
    console.log(`${range ? '206' : '200'} ${req.url}`);
  })
  .listen(port, '127.0.0.1', () => console.log(`Serving ${folder} on http://127.0.0.1:${port}`));
