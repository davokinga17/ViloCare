// Checks a finished build in dist/: the update files exist and agree with each other, and no secret was packed.
// Run by `npm run build:installer` and again by `npm run release`; exits 1 on any problem.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const yaml = require('js-yaml');
const policy = require('../src/updater-policy');

const root = path.join(__dirname, '..');
const dist = path.join(root, 'dist');
const pkg = JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8'));
const version = pkg.version;

// ---- secret scan ----
const TOKEN_PATTERNS = [
  /gh[pousr]_[A-Za-z0-9]{30,}/,
  /github_pat_[A-Za-z0-9_]{22,}/,
  /GH_TOKEN\s*[=:]\s*["']?[A-Za-z0-9_\-]{20,}/,
  /GITHUB_TOKEN\s*[=:]\s*["']?[A-Za-z0-9_\-]{20,}/,
];
const SKIP_EXT = new Set(['.exe', '.dll', '.pdb', '.node', '.png', '.ico', '.jpg', '.jpeg', '.gif', '.woff', '.woff2', '.ttf', '.zip', '.7z', '.pak', '.bin', '.dat', '.blockmap', '.lib', '.obj', '.gz', '.asar']);
const MAX_SCAN_BYTES = 3 * 1024 * 1024;

function* walk(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) yield* walk(full);
    else yield full;
  }
}

function scanForSecrets(dirs, extraFiles = []) {
  const exact = (process.env.GH_TOKEN || '').trim();
  const hits = [];
  let scanned = 0;
  const files = function* () {
    for (const dir of dirs) if (fs.existsSync(dir)) yield* walk(dir);
    for (const f of extraFiles) if (fs.existsSync(f)) yield f;
  };
  {
    for (const file of files()) {
      if (SKIP_EXT.has(path.extname(file).toLowerCase())) continue;
      const size = fs.statSync(file).size;
      if (size === 0 || size > MAX_SCAN_BYTES) continue;
      const text = fs.readFileSync(file, 'latin1');
      scanned++;
      if (exact.length >= 8 && text.includes(exact)) hits.push(`${path.relative(root, file)}: contains the value of GH_TOKEN`);
      for (const re of TOKEN_PATTERNS) if (re.test(text)) hits.push(`${path.relative(root, file)}: matches ${re}`);
    }
  }
  return { hits, scanned };
}

// ---- main checks ----
function check() {
  const problems = [];
  const warnings = [];
  const note = (list, msg) => list.push(msg);

  const installerName = `ViLoCare-Setup-${version}.exe`;
  const installer = path.join(dist, installerName);
  const pre = policy.parseVersion(version).pre[0];
  const ymlName = pre ? `${pre}.yml` : 'latest.yml'; // a 1.1.1-beta.1 build is published as beta.yml, so stable installs never see it
  const ymlFile = path.join(dist, ymlName);

  for (const f of [installer, `${installer}.blockmap`, ymlFile]) {
    if (!fs.existsSync(f)) note(problems, `missing ${path.relative(root, f)}`);
  }

  if (fs.existsSync(installer) && fs.existsSync(ymlFile)) {
    const meta = yaml.load(fs.readFileSync(ymlFile, 'utf8'));
    const entry = (meta.files || [])[0] || {};
    const size = fs.statSync(installer).size;
    if (meta.version !== version) note(problems, `${ymlName} says version ${meta.version}, package.json says ${version}`);
    if (entry.url !== installerName) note(problems, `${ymlName} points at "${entry.url}", expected "${installerName}"`);
    if (entry.size !== size) note(problems, `${ymlName} lists ${entry.size} bytes, the installer has ${size}`);
    const sha = crypto.createHash('sha512').update(fs.readFileSync(installer)).digest('base64');
    if (entry.sha512 !== sha) note(problems, `${ymlName} sha512 does not match the installer file`);
  }

  const appUpdate = path.join(dist, 'win-unpacked', 'resources', 'app-update.yml');
  if (!fs.existsSync(appUpdate)) {
    note(problems, 'win-unpacked/resources/app-update.yml is missing: the installed app would not know where to look for updates');
  } else {
    const cfg = yaml.load(fs.readFileSync(appUpdate, 'utf8'));
    if (/REPLACE_WITH/.test(`${cfg.owner} ${cfg.repo}`)) {
      note(warnings, 'electron-builder.yml still has the REPLACE_WITH_... placeholders: this installer cannot find updates. Fill in owner/repo and rebuild before it goes to a clinic.');
    }
    if (cfg.token || cfg.private) note(problems, 'app-update.yml contains a token or a private-repo flag');
  }

  const { hits, scanned } = scanForSecrets([path.join(root, 'src'), path.join(dist, 'win-unpacked', 'resources')], [ymlFile, path.join(dist, 'builder-debug.yml')]);
  for (const h of hits) note(problems, `secret-like string: ${h}`);

  const resources = path.join(dist, 'win-unpacked', 'resources', 'app');
  if (fs.existsSync(resources)) {
    for (const file of walk(resources)) {
      const base = path.basename(file);
      if (/^\.env($|\.)/.test(base) && base !== '.env.example') problems.push(`packed env file: ${path.relative(root, file)}`);
      if (/\.(sqlite|db)($|[-.])/.test(base)) problems.push(`packed database file: ${path.relative(root, file)}`);
    }
  }

  return { problems, warnings, scanned, installerName, ymlName };
}

if (require.main === module) {
  const r = check();
  console.log(`Build check for ${version}: ${r.scanned} text files scanned for token-like strings.`);
  for (const w of r.warnings) console.warn(`WARNING: ${w}`);
  if (r.problems.length) {
    for (const p of r.problems) console.error(`FAIL: ${p}`);
    process.exit(1);
  }
  console.log(`OK: ${r.installerName}, ${r.installerName}.blockmap and ${r.ymlName} are present and consistent; no secrets found.`);
}

module.exports = { check, scanForSecrets };
