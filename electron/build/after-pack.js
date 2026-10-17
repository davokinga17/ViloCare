// electron-builder's own exe editing needs a code-signing tools archive that cannot be extracted on
// Windows accounts without symlink rights, so signAndEditExecutable is off and the icon + version
// info are applied here with rcedit instead. Remove this hook when real code signing is set up.
const path = require('path');

module.exports = async function afterPack(context) {
  const { appOutDir, packager } = context;
  const exe = path.join(appOutDir, `${packager.appInfo.productFilename}.exe`);
  const mod = await import('rcedit');
  const rcedit = mod.rcedit || (mod.default && mod.default.rcedit) || mod.default;
  await rcedit(exe, {
    icon: path.join(__dirname, 'icon.ico'),
    'version-string': {
      ProductName: 'ViLoCare',
      FileDescription: 'ViLoCare',
      CompanyName: 'ViLoCare',
      OriginalFilename: `${packager.appInfo.productFilename}.exe`,
    },
    'file-version': packager.appInfo.version,
    'product-version': packager.appInfo.version,
  });
};
