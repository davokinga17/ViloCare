// Stages a clean, secret-free copy of app_backend into resources/app for packaging.
// Excluded: .env*, SQLite files, storage contents, tests, node_modules, clinic/ scripts, VCS data.
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const src = path.join(__dirname, '..', '..', 'app_backend');
const dest = path.join(__dirname, '..', 'resources', 'app');

if (!fs.existsSync(path.join(src, 'public', 'build', 'manifest.json'))) {
  console.error('app_backend/public/build is missing. Run "npm ci && npm run build" in app_backend first.');
  process.exit(1);
}

const excludedTop = new Set(['.env', '.git', 'node_modules', 'tests', 'storage', 'clinic', 'vendor', '.phpunit.cache']);
const excludedPublic = new Set(['storage', 'hot']);

function keep(from) {
  const rel = path.relative(src, from).split(path.sep);
  const name = rel[rel.length - 1];
  if (rel.length === 1 && (excludedTop.has(name) || name.startsWith('.env'))) return false;
  if (rel[0] === 'public' && rel.length === 2 && excludedPublic.has(name)) return false;
  // Any SQLite file or copy of one (database.sqlite, .sqlite-wal, .sqlite.bak, ...): clinic or dev data must never ship.
  if (rel[0] === 'database' && /\.(sqlite|db)($|[-.])/.test(name)) return false;
  if (rel[0] === 'database' && rel[1] === 'backups') return false;
  if (rel[0] === 'bootstrap' && rel[1] === 'cache' && name.endsWith('.php')) return false;
  if (name.endsWith('.log')) return false;
  return true;
}

fs.rmSync(dest, { recursive: true, force: true });
fs.cpSync(src, dest, { recursive: true, filter: keep });

// Empty storage skeleton; the real one lives in the data folder (LARAVEL_STORAGE_PATH).
fs.mkdirSync(path.join(dest, 'storage'), { recursive: true });
fs.mkdirSync(path.join(dest, 'bootstrap', 'cache'), { recursive: true });

console.log('Installing production Composer dependencies...');
execFileSync('composer', ['install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-scripts', '--prefer-dist'], {
  cwd: dest,
  stdio: 'inherit',
  shell: true,
});

console.log('App staged in resources/app');
