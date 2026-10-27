const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const { verify } = require('../scripts/verify-update');

const INSTALLER = Buffer.from('pretend this is an installer');
const NAME = 'ViLoCare-Setup-1.1.2.exe';
const sha = crypto.createHash('sha512').update(INSTALLER).digest('base64');
const latestYml = (over = {}) => `version: ${over.version || '1.1.2'}\nfiles:\n  - url: ${NAME}\n    sha512: ${over.sha512 || sha}\n    size: ${over.size || INSTALLER.length}\npath: ${NAME}\n`;

// A fake GitHub. `o` overrides pieces to simulate each failure.
function fakeFetch(o = {}) {
  const calls = [];
  const fn = async (url) => {
    calls.push(url);
    assert.ok(!/ghp_|github_pat_/.test(String(url)), 'no token in URLs');
    if (url.endsWith('/latest.yml')) return { status: o.ymlStatus || 200, text: async () => latestYml(o) };
    if (url.includes('api.github.com')) {
      return {
        status: o.apiStatus || 200,
        json: async () => ({
          tag_name: o.tag || 'v1.1.2',
          draft: !!o.draft,
          prerelease: !!o.prerelease,
          assets: [{ name: NAME }, ...(o.noBlockmap ? [] : [{ name: `${NAME}.blockmap` }]), { name: 'latest.yml' }],
        }),
      };
    }
    if (url.endsWith(encodeURIComponent(NAME))) {
      const body = o.corrupt ? Buffer.from('tampered installer bytes!!!!!!!!') : INSTALLER;
      return { status: o.dlStatus || 200, body: new Response(body).body };
    }
    throw new Error(`unexpected URL ${url}`);
  };
  fn.calls = calls;
  return fn;
}

function builderYml(owner = 'davokinga17', repo = 'vilocare-releases') {
  const f = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'vu-test-')), 'electron-builder.yml');
  fs.writeFileSync(f, `publish:\n  provider: github\n  owner: ${owner}\n  repo: ${repo}\n`);
  return f;
}

const run = (o = {}, expectedVersion = '1.1.2', yml = builderYml()) => verify({ expectedVersion, fetchFn: fakeFetch(o), builderYmlPath: yml, log: () => {} });

test('a published, consistent release passes', async () => {
  const r = await run();
  assert.strictEqual(r.version, '1.1.2');
  assert.strictEqual(r.sha512, sha);
  assert.strictEqual((await run({}, 'v1.1.2')).version, '1.1.2', 'a leading v is accepted');
  assert.strictEqual((await run({}, undefined)).version, '1.1.2', 'no expected version: just consistency');
});

test('the real electron-builder.yml points at the right repository', async () => {
  const r = await verify({ expectedVersion: '1.1.2', fetchFn: fakeFetch(), log: () => {} });
  assert.strictEqual(r.version, '1.1.2');
});

test('it fails, with a plain message, on each problem', async () => {
  await assert.rejects(run({ version: '1.1.1' }), /says version 1\.1\.1, expected 1\.1\.2/);
  await assert.rejects(run({ ymlStatus: 404 }), /latest\.yml is not available \(HTTP 404\)/);
  await assert.rejects(run({ draft: true }), /still a draft/);
  await assert.rejects(run({ prerelease: true }), /is a pre-release/);
  await assert.rejects(run({ noBlockmap: true }), /no ViLoCare-Setup-1\.1\.2\.exe\.blockmap/);
  await assert.rejects(run({ corrupt: true }), /downloaded installer is \d+ bytes|sha512 does not match/);
  await assert.rejects(run({ sha512: crypto.createHash('sha512').update('other').digest('base64') }), /sha512 does not match/);
  await assert.rejects(run({ dlStatus: 500 }), /download failed \(HTTP 500\)/);
  await assert.rejects(run({ tag: 'v1.1.3' }), /latest release is v1\.1\.3 but latest\.yml says 1\.1\.2/);
  await assert.rejects(run({}, '1.1.2', builderYml('someone-else', 'vilocare-releases')), /expected "davokinga17\/vilocare-releases"/);
  await assert.rejects(run({}, '1.1.2', builderYml('davokinga17', 'old-repo')), /publishes to "davokinga17\/old-repo"/);
});

test('the downloaded installer is deleted afterwards, and no auth header is ever sent', async () => {
  const before = fs.readdirSync(os.tmpdir()).filter((n) => n.startsWith('vilocare-verify-')).length;
  await run();
  await assert.rejects(run({ corrupt: true }));
  const after = fs.readdirSync(os.tmpdir()).filter((n) => n.startsWith('vilocare-verify-')).length;
  assert.strictEqual(after, before, 'temp folders are removed even when the check fails');
  const src = fs.readFileSync(path.join(__dirname, '..', 'scripts', 'verify-update.js'), 'utf8');
  assert.ok(!/GH_TOKEN|Authorization|Bearer/i.test(src), 'the script never reads or sends a token');
});
