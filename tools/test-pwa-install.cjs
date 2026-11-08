// Run: node tools/test-pwa-install.cjs
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const script = fs.readFileSync(path.join(__dirname, '../assets/js/pwa-install.js'), 'utf8');

function setup(options = {}) {
  const events = {};
  const attributes = {};
  const elements = {
    loginInstall: { hidden: true },
    installHelp: { hidden: true, textContent: '' },
    installApp: {
      dataset: { serviceWorker: 'https://example.org/service-worker.js' },
      setAttribute: (key, value) => { attributes[key] = value; },
      addEventListener: (name, handler) => { events[name] = handler; },
    },
  };
  let registered;
  const media = { matches: !!options.standalone, addEventListener: () => {} };
  vm.runInNewContext(script, {
    document: { getElementById: id => elements[id] },
    navigator: {
      userAgent: options.ios ? 'iPhone' : 'Chrome',
      platform: '', maxTouchPoints: 0,
      serviceWorker: { register: url => { registered = url; return Promise.resolve(); } },
    },
    window: {
      isSecureContext: true,
      matchMedia: () => media,
      addEventListener: (name, handler) => { events[name] = handler; },
    },
  });
  return { events, elements, attributes, registered };
}

(async function () {
  let app = setup();
  assert.equal(app.elements.loginInstall.hidden, false);
  assert.equal(app.registered, 'https://example.org/service-worker.js');
  await app.events.click();
  assert.match(app.elements.installHelp.textContent, /browser menu/);
  assert.equal(app.attributes['aria-expanded'], 'true');
  await app.events.click();
  assert.equal(app.elements.installHelp.hidden, true);

  app = setup({ ios: true });
  await app.events.click();
  assert.match(app.elements.installHelp.textContent, /Safari.*Share.*Add to Home Screen/);
  assert.equal(setup({ standalone: true }).elements.loginInstall.hidden, true);

  for (const outcome of ['accepted', 'dismissed']) {
    app = setup();
    let prompted = 0;
    let prevented = false;
    app.events.beforeinstallprompt({
      preventDefault: () => { prevented = true; },
      prompt: async () => { prompted++; },
      userChoice: Promise.resolve({ outcome }),
    });
    assert.equal(prevented, true);
    await app.events.click();
    assert.equal(prompted, 1);
    assert.equal(app.elements.loginInstall.hidden, outcome === 'accepted');
    assert.equal(app.elements.installApp.disabled, false);
    await app.events.click();
    assert.equal(prompted, 1, 'Never reuse a consumed install prompt');
  }

  app = setup();
  app.events.beforeinstallprompt({ preventDefault() {}, prompt: async () => { throw new Error('Unavailable'); } });
  await app.events.click();
  assert.equal(app.elements.installHelp.hidden, false);
  assert.equal(app.elements.installApp.disabled, false);
  app.events.appinstalled();
  assert.equal(app.elements.loginInstall.hidden, true);
  console.log('Login PWA install interaction checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
