// Copies the project's existing favicon/logo into the places Electron and electron-builder expect.
const fs = require('fs');
const path = require('path');

const images = path.join(__dirname, '..', '..', 'app_backend', 'public', 'images');
const root = path.join(__dirname, '..');

const copies = [
  ['favicon-512.png', 'build/icon.png'], // installer + exe icon (electron-builder converts to .ico)
  ['favicon-512.png', 'assets/icon.png'], // window icon
  ['favicon-32.png', 'assets/tray.png'], // tray icon
  ['vilocare-logo.png', 'assets/logo.png'], // splash / setup screens
];

for (const [src, dest] of copies) {
  const from = path.join(images, src);
  if (!fs.existsSync(from)) throw new Error(`Missing ${from}`);
  fs.mkdirSync(path.dirname(path.join(root, dest)), { recursive: true });
  fs.copyFileSync(from, path.join(root, dest));
}
console.log('Icons ready.');

// Windows .ico for the exe/installer (built from the same 512px favicon via electron-builder's own converter).
try {
  const { execFileSync } = require('child_process');
  const bin = path.join(root, 'node_modules', 'app-builder-bin', 'win', 'x64', 'app-builder.exe');
  execFileSync(bin, ['icon', '--input', path.join(root, 'build', 'icon.png'), '--format', 'ico', '--out', path.join(root, 'build', '.ico-out')], { stdio: 'ignore' });
  fs.copyFileSync(path.join(root, 'build', '.ico-out', 'icon.ico'), path.join(root, 'build', 'icon.ico'));
  fs.rmSync(path.join(root, 'build', '.ico-out'), { recursive: true, force: true });
} catch {
  fs.copyFileSync(path.join(images, 'favicon.ico'), path.join(root, 'build', 'icon.ico'));
}
