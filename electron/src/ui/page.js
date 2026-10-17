const page = document.body.dataset.page;
const query = new URLSearchParams(location.search);
const $ = (id) => document.getElementById(id);

if (page === 'splash') {
  if (query.get('status')) $('status').textContent = query.get('status');
  window.vilo.onStatus((text) => ($('status').textContent = text));
}

if (page === 'setup') {
  $('form').addEventListener('submit', async (e) => {
    e.preventDefault();
    $('msg').textContent = '';
    const result = await window.vilo.saveSetup($('url').value.trim(), $('token').value.trim());
    if (result && result.error) $('msg').textContent = result.error;
  });
  $('skip').addEventListener('click', () => window.vilo.skipSetup());
}

if (page === 'admin') {
  $('form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const msg = $('msg');
    msg.textContent = '';
    const password = $('password').value;
    if (password.length < 10) return (msg.textContent = 'The password must be at least 10 characters.');
    if (password !== $('confirmation').value) return (msg.textContent = 'The two passwords do not match.');
    $('submit').disabled = true;
    const result = await window.vilo.createAdmin({
      name: $('name').value.trim(),
      email: $('email').value.trim(),
      password,
      confirmation: $('confirmation').value,
    });
    if (result && result.error) {
      msg.textContent = result.error;
      $('submit').disabled = false;
    }
  });
}

if (page === 'error') {
  $('message').textContent = query.get('message') || 'An unknown error occurred.';
  if (query.get('detail')) {
    $('detail').hidden = false;
    $('detail').textContent = query.get('detail');
  }
  $('retry').addEventListener('click', () => window.vilo.retry());
  $('logs').addEventListener('click', () => window.vilo.openLogs());
  $('quit').addEventListener('click', () => window.vilo.quit());
}
