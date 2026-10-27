const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const phpMin = require('../scripts/php-min');

test('PHP below 8.3.36 is rejected, 8.3.36 and newer accepted', () => {
  assert.strictEqual(phpMin.MIN_PHP, '8.3.36');
  for (const v of ['8.3.35', '8.3.0', '8.2.99', '7.4.33']) assert.ok(phpMin.isBelow(v), v);
  for (const v of ['8.3.36', '8.3.37', '8.4.0', '9.0.0']) assert.ok(!phpMin.isBelow(v), v);
  assert.throws(() => phpMin.assertMin('8.3.35'), /below the required 8\.3\.36/);
  assert.doesNotThrow(() => phpMin.assertMin('8.3.36'));
});

test('the build scripts enforce the PHP floor', () => {
  const read = (f) => fs.readFileSync(path.join(__dirname, '..', 'scripts', f), 'utf8');
  assert.match(read('fetch-php.js'), /phpMin\.assertMin\(bundled/);
  assert.match(read('fetch-php.js'), /phpMin\.assertMin\(rel\.version/);
  assert.match(read('check-build.js'), /phpMin\.isBelow\(v\)/);
});

test('release.js: --allow-dirty is opt-in and refuses every publish step', () => {
  const src = fs.readFileSync(path.join(__dirname, '..', 'scripts', 'release.js'), 'utf8');
  assert.match(src, /process\.argv\.includes\('--allow-dirty'\)/);
  assert.match(src, /dirty && !allowDirty/, 'a dirty tree still stops the release unless the flag is given');
  const env = { ...process.env, GH_TOKEN: 'x'.repeat(30), VILOCARE_PUBLISH: '1' };
  for (const args of [['--allow-dirty', '--prerelease'], ['--allow-dirty']]) {
    const r = spawnSync(process.execPath, [path.join(__dirname, '..', 'scripts', 'release.js'), ...args], { env, encoding: 'utf8', cwd: path.join(__dirname, '..') });
    assert.strictEqual(r.status, 1);
    assert.match(r.stderr, /cannot be combined with a publish step/);
  }
  // Without VILOCARE_PUBLISH, a publish-looking argument is refused too.
  const r2 = spawnSync(process.execPath, [path.join(__dirname, '..', 'scripts', 'release.js'), '--allow-dirty', '--publish'], { env: { ...process.env, GH_TOKEN: 'x'.repeat(30), VILOCARE_PUBLISH: '' }, encoding: 'utf8', cwd: path.join(__dirname, '..') });
  assert.match(r2.stderr, /cannot be combined with a publish step/);
});
