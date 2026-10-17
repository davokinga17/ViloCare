// Publishes the installer in dist/ to the public releases repository as a DRAFT (default) or, with --prerelease,
// as a GitHub pre-release. Usage:  npm run release            (draft, for a stable version)
//                                  npm run release -- --prerelease   (for versions like 1.1.1-beta.1)
// Needs GH_TOKEN in the environment of this shell only. The token is sent to api.github.com and nowhere else, and is
// never written to disk or printed. Refuses to run unless: token present, owner/repo filled in, git tree clean,
// version higher than every existing release, tests pass, and the build check (files + secret scan) passes.
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const yaml = require('js-yaml');
const policy = require('../src/updater-policy');
const { check } = require('./check-build');

const root = path.join(__dirname, '..');
const pkg = JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8'));
const version = pkg.version;
const prerelease = process.argv.includes('--prerelease');
const token = (process.env.GH_TOKEN || '').trim();

const fail = (msg) => {
  console.error(`\nRelease stopped: ${msg}`);
  process.exit(1);
};
const run = (cmd, args, cwd = root) => spawnSync(cmd, args, { cwd, stdio: 'inherit', shell: true });

// 1. token present (never printed)
if (!token) fail('GH_TOKEN is not set in this shell. See README "Auto-update: first-time setup" (set it for this session only).');

// 2. owner / repo filled in
const cfg = yaml.load(fs.readFileSync(path.join(root, 'electron-builder.yml'), 'utf8')).publish || {};
if (cfg.provider !== 'github' || !cfg.owner || !cfg.repo || /REPLACE_WITH/.test(`${cfg.owner} ${cfg.repo}`)) {
  fail('electron-builder.yml `publish:` still has placeholder owner/repo. Fill them in and rebuild.');
}
const repoPath = `${cfg.owner}/${cfg.repo}`;

// 3. pre-release flag must match the version
if (prerelease !== policy.isPrerelease(version)) {
  fail(prerelease ? `--prerelease given but ${version} is not a pre-release version (e.g. 1.1.1-beta.1).` : `${version} is a pre-release version: run "npm run release -- --prerelease".`);
}

// 4. clean git tree
const status = spawnSync('git', ['status', '--porcelain'], { cwd: root, encoding: 'utf8' });
if (status.status !== 0) fail('git status failed (is this a git checkout?).');
if (status.stdout.trim()) fail('the git working tree has uncommitted changes. Commit (or stash) everything so the release matches a commit.');

const api = async (method, url, { headers = {}, body } = {}) => {
  const res = await fetch(url.startsWith('http') ? url : `https://api.github.com${url}`, {
    method,
    headers: { Accept: 'application/vnd.github+json', Authorization: `Bearer ${token}`, 'X-GitHub-Api-Version': '2022-11-28', 'User-Agent': 'vilocare-release', ...headers },
    body,
  });
  const text = await res.text();
  let json = null;
  try {
    json = text ? JSON.parse(text) : null;
  } catch {
    /* not json */
  }
  if (!res.ok) throw new Error(`${method} ${url.split('?')[0]} -> HTTP ${res.status}${json && json.message ? `: ${json.message}` : ''}`);
  return json;
};

(async () => {
  // 5. version higher than every release in the repository (drafts included, because the token can see them)
  let releases;
  try {
    releases = await api('GET', `/repos/${repoPath}/releases?per_page=100`);
  } catch (e) {
    fail(`could not read ${repoPath}: ${e.message} (check the repo name and that the token has Contents access to it).`);
  }
  const existing = releases.map((r) => String(r.tag_name).replace(/^v/i, ''));
  const newest = existing.sort(policy.cmpVersion).pop();
  if (newest && policy.cmpVersion(version, newest) <= 0) fail(`version ${version} is not higher than the existing release ${newest}. Bump "version" in electron/package.json.`);

  // 6. tests (Laravel + Electron)
  console.log('\n== Running tests ==');
  if (run('php', ['artisan', 'test'], path.join(root, '..', 'app_backend')).status !== 0) fail('the Laravel tests failed.');
  if (run('npm', ['test']).status !== 0) fail('the Electron tests failed.');

  // 7. build (publish never) + file and secret checks
  console.log('\n== Building the installer ==');
  if (run('npm', ['run', 'build:installer']).status !== 0) fail('the build failed.');
  const built = check();
  if (built.problems.length) fail(`build check failed:\n - ${built.problems.join('\n - ')}`);
  if (built.warnings.length) fail(built.warnings.join('\n'));

  // 8. create the release and upload the three files
  const tag = `v${version}`;
  console.log(`\n== Creating ${prerelease ? 'pre-release' : 'DRAFT release'} ${tag} in ${repoPath} ==`);
  const release = await api('POST', `/repos/${repoPath}/releases`, {
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ tag_name: tag, name: `ViLoCare ${version}`, draft: !prerelease, prerelease, generate_release_notes: false, body: `ViLoCare desktop ${version}` }),
  });

  const files = [built.installerName, `${built.installerName}.blockmap`, built.ymlName];
  for (const name of files) {
    const data = fs.readFileSync(path.join(root, 'dist', name));
    const type = name.endsWith('.yml') ? 'text/yaml' : 'application/octet-stream';
    console.log(`Uploading ${name} (${(data.length / 1048576).toFixed(1)} MB)...`);
    await api('POST', `${release.upload_url.replace(/\{.*$/, '')}?name=${encodeURIComponent(name)}`, { headers: { 'Content-Type': type }, body: data });
  }

  // 9. verify what GitHub holds
  const after = await api('GET', `/repos/${repoPath}/releases/${release.id}`);
  const have = new Map(after.assets.map((a) => [a.name, a.size]));
  for (const name of files) {
    const size = fs.statSync(path.join(root, 'dist', name)).size;
    if (have.get(name) !== size) fail(`${name} is missing or has the wrong size on GitHub. Delete the release ${tag} on GitHub and run again.`);
  }
  console.log(`\nDone. ${prerelease ? 'Pre-release' : 'Draft'} ${tag}: ${after.html_url}`);
  console.log(prerelease ? 'It is live for beta installs only (stable installs never see pre-releases).' : 'It is a DRAFT: nobody receives it until you open the link and press "Publish release".');
})().catch((e) => fail(e.message));
