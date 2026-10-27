// Read-only check of the PUBLISHED release that installed apps will update from. Installs nothing, uses no token.
// Usage: node scripts/verify-update.js [expected-version]      (e.g. 1.1.2; the "v" prefix is optional)
// Checks: latest.yml is served (HTTP 200) and says the expected version; the latest release is neither a draft nor a
// pre-release; the installer in that release has the size and sha512 latest.yml promises; the .blockmap exists;
// electron-builder.yml publishes to davokinga17/vilocare-releases. Exits 1 with a plain message on any failure.
const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const yaml = require('js-yaml');

const OWNER = 'davokinga17';
const REPO = 'vilocare-releases';
const root = path.join(__dirname, '..');

class VerifyError extends Error {}
const must = (cond, msg) => {
  if (!cond) throw new VerifyError(msg);
};

// fetchFn and builderYmlPath are injectable so the tests can run without a network.
async function verify({ expectedVersion, fetchFn = fetch, builderYmlPath = path.join(root, 'electron-builder.yml'), log = console.log } = {}) {
  const base = `https://github.com/${OWNER}/${REPO}/releases/latest/download`;
  const wanted = expectedVersion ? String(expectedVersion).replace(/^v/i, '') : null;

  // 5. the app must look for updates in the right place
  const publish = (yaml.load(fs.readFileSync(builderYmlPath, 'utf8')) || {}).publish || {};
  must(publish.owner === OWNER && publish.repo === REPO, `electron-builder.yml publishes to "${publish.owner}/${publish.repo}", expected "${OWNER}/${REPO}". Installed apps would look in the wrong place.`);
  log(`electron-builder.yml: publish owner/repo ${publish.owner}/${publish.repo} OK`);

  // 1 + 2. latest.yml, no auth
  const ymlRes = await fetchFn(`${base}/latest.yml`, { redirect: 'follow' });
  must(ymlRes.status === 200, `latest.yml is not available (HTTP ${ymlRes.status}). Is the release published, and is it the newest non-draft release?`);
  const meta = yaml.load(await ymlRes.text()) || {};
  const entry = (meta.files || [])[0] || {};
  must(meta.version && entry.url && entry.sha512 && entry.size, 'latest.yml is missing the version, file name, size or sha512.');
  log(`latest.yml: version ${meta.version}, file ${entry.url}, size ${entry.size}, sha512 ${entry.sha512}`);
  if (wanted) must(meta.version === wanted, `latest.yml says version ${meta.version}, expected ${wanted}.`);

  // draft / pre-release flags and the asset list come from the public release API (no token needed for a published release)
  const relRes = await fetchFn(`https://api.github.com/repos/${OWNER}/${REPO}/releases/latest`, { headers: { Accept: 'application/vnd.github+json', 'User-Agent': 'vilocare-verify-update' } });
  must(relRes.status === 200, `could not read the latest release from GitHub (HTTP ${relRes.status}).`);
  const rel = await relRes.json();
  must(!rel.draft, `release ${rel.tag_name} is still a draft.`);
  must(!rel.prerelease, `release ${rel.tag_name} is a pre-release; stable installs would not receive it.`);
  must(String(rel.tag_name).replace(/^v/i, '') === meta.version, `the latest release is ${rel.tag_name} but latest.yml says ${meta.version}.`);
  log(`release ${rel.tag_name}: published, not a draft, not a pre-release`);

  // 4. blockmap
  const names = new Map((rel.assets || []).map((a) => [a.name, a]));
  must(names.has(`${entry.url}.blockmap`), `the release has no ${entry.url}.blockmap, so updates would download the whole installer every time.`);
  log(`${entry.url}.blockmap present`);
  must(names.has(entry.url), `the release has no ${entry.url}.`);

  // 3. download the installer to a temp folder, compare, delete
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'vilocare-verify-'));
  const file = path.join(dir, entry.url);
  try {
    const res = await fetchFn(`${base}/${encodeURIComponent(entry.url)}`, { redirect: 'follow' });
    must(res.status === 200, `the installer download failed (HTTP ${res.status}).`);
    const hash = crypto.createHash('sha512');
    let size = 0;
    const out = fs.createWriteStream(file);
    const reader = res.body.getReader();
    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      hash.update(value);
      size += value.length;
      if (!out.write(value)) await new Promise((r) => out.once('drain', r));
    }
    await new Promise((r) => out.end(r));
    const sha = hash.digest('base64');
    must(size === entry.size, `the downloaded installer is ${size} bytes, latest.yml says ${entry.size}.`);
    must(sha === entry.sha512, 'the downloaded installer sha512 does not match latest.yml.');
    log(`installer download: ${size} bytes, sha512 matches latest.yml`);
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }

  log(`\nOK: ${meta.version} is published and consistent.`);
  return { version: meta.version, file: entry.url, size: entry.size, sha512: entry.sha512 };
}

if (require.main === module) {
  verify({ expectedVersion: process.argv[2] }).catch((e) => {
    console.error(`\nverify-update FAILED: ${e instanceof VerifyError ? e.message : `unexpected error: ${e.message}`}`);
    process.exit(1);
  });
}

module.exports = { verify, VerifyError, OWNER, REPO };
