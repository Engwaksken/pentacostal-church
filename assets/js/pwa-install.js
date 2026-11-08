(function () {
  'use strict';

  const container = document.getElementById('loginInstall');
  const button = document.getElementById('installApp');
  const help = document.getElementById('installHelp');
  if (!container || !button || !help) return;

  const standalone = window.matchMedia('(display-mode: standalone)');
  let installed = standalone.matches || navigator.standalone === true;
  let installPrompt = null;

  function updateVisibility() {
    container.hidden = installed || standalone.matches;
  }

  function showHelp() {
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent)
      || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    help.textContent = isIOS
      ? 'On iPhone or iPad, open this site in Safari, tap Share, then Add to Home Screen and Add.'
      : 'Open your browser menu and choose Install app or Add to Home screen. On a computer, look for the install icon in the address bar. If unavailable, try Chrome or Edge over HTTPS; the app may already be installed.';
    help.hidden = false;
    button.setAttribute('aria-expanded', 'true');
  }

  window.addEventListener('beforeinstallprompt', function (event) {
    event.preventDefault();
    installPrompt = event;
    help.hidden = true;
    button.setAttribute('aria-expanded', 'false');
    updateVisibility();
  });

  button.addEventListener('click', async function () {
    if (!installPrompt) {
      if (!help.hidden) {
        help.hidden = true;
        button.setAttribute('aria-expanded', 'false');
      } else {
        showHelp();
      }
      return;
    }
    const prompt = installPrompt;
    installPrompt = null;
    button.disabled = true;
    try {
      await prompt.prompt();
      const choice = await prompt.userChoice;
      if (choice.outcome === 'accepted') {
        installed = true;
        updateVisibility();
      }
    } catch (error) {
      showHelp();
    } finally {
      button.disabled = false;
    }
  });

  window.addEventListener('appinstalled', function () {
    installed = true;
    installPrompt = null;
    updateVisibility();
  });
  if (standalone.addEventListener) standalone.addEventListener('change', updateVisibility);
  updateVisibility();

  if ('serviceWorker' in navigator && window.isSecureContext) {
    navigator.serviceWorker.register(button.dataset.serviceWorker).catch(function () {
      // Installation support must never interfere with signing in.
    });
  }
})();
