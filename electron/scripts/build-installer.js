// Runs electron-builder for the NSIS installer WITHOUT publishing. For a pre-release version (1.1.1-beta.1) it sets the
// update channel to the pre-release tag ("beta"), so the build writes beta.yml and the installed app looks for beta.yml.
// Stable versions use the default channel (latest.yml). Extra arguments are passed through to electron-builder.
const path = require('path');
const { spawnSync } = require('child_process');
const policy = require('../src/updater-policy');

const root = path.join(__dirname, '..');
const version = require('../package.json').version;
const tag = policy.parseVersion(version).pre[0];

const args = ['electron-builder', '--win', 'nsis', '--x64', '--publish', 'never', ...process.argv.slice(2)];
if (tag !== undefined) {
  if (!/^[a-z]+$/i.test(String(tag))) {
    console.error(`Pre-release tag "${tag}" must be letters only (e.g. 1.1.1-beta.1).`);
    process.exit(1);
  }
  args.push(`-c.publish.channel=${tag}`);
  console.log(`Pre-release ${version}: update channel "${tag}" (writes ${tag}.yml)`);
}

const res = spawnSync('npx', args, { cwd: root, stdio: 'inherit', shell: true });
process.exit(res.status === null ? 1 : res.status);
