// Security floor for the PHP that ships inside the installer: PHP2Shell (CVE-2026-109618, CVE-2026-109619) is fixed in 8.3.36.
// Used by fetch-php.js (refuses to bundle anything older) and check-build.js (refuses to accept an installer built with it).
const { execFileSync } = require('child_process');

const MIN_PHP = '8.3.36';

const parts = (v) => String(v).split('.').map((n) => parseInt(n, 10) || 0);
function isBelow(version, min = MIN_PHP) {
  const a = parts(version);
  const b = parts(min);
  for (let i = 0; i < 3; i++) {
    if ((a[i] || 0) !== (b[i] || 0)) return (a[i] || 0) < (b[i] || 0);
  }
  return false;
}

// Runs the binary itself (-n: no php.ini, so a broken extension_dir cannot hide the version) and reads "PHP x.y.z".
function binaryVersion(phpExe) {
  const out = execFileSync(phpExe, ['-n', '-r', 'echo PHP_VERSION;'], { encoding: 'utf8', windowsHide: true, stdio: ['ignore', 'pipe', 'ignore'] }).trim();
  if (!/^\d+\.\d+\.\d+/.test(out)) throw new Error(`Could not read the PHP version from ${phpExe} (got "${out}")`);
  return out;
}

function assertMin(version, label = 'PHP') {
  if (isBelow(version)) throw new Error(`${label} is ${version}, below the required ${MIN_PHP} (PHP2Shell fix). Refusing to continue.`);
}

module.exports = { MIN_PHP, isBelow, binaryVersion, assertMin };
