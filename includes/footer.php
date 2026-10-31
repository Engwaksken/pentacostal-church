    </div><!-- /.content-area -->
  </div><!-- /.main-content -->
</div><!-- /.cms-layout -->
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/statistics.css?v=1">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/form-tabs.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/form-tabs.css') ?>">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/bulk-delete.css?v=1">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/accessibility.css?v=2">
<div class="accessibility-widget" id="accessibilityWidget">
  <button type="button" class="accessibility-launcher" id="accessibilityToggle" aria-expanded="false" aria-controls="accessibilityPanel" aria-label="Open accessibility options"><i class="fas fa-universal-access" aria-hidden="true"></i><span>Accessibility</span></button>
  <section class="accessibility-panel" id="accessibilityPanel" aria-labelledby="accessibilityTitle" hidden>
    <div class="accessibility-panel-head"><h2 id="accessibilityTitle">Accessibility options</h2><button type="button" id="accessibilityClose" aria-label="Close accessibility options">&times;</button></div>
    <div class="accessibility-controls">
      <h3>Display</h3>
      <div class="accessibility-control-row"><span>Text size</span><div><button type="button" data-accessibility="font-down" aria-label="Decrease text size">A−</button><output id="accessibilityTextScale">100%</output><button type="button" data-accessibility="font-up" aria-label="Increase text size">A+</button></div></div>
      <label class="accessibility-select-label" for="accessibilityContrast">Color theme</label>
      <select id="accessibilityContrast"><option value="normal">Default</option><option value="high">High contrast</option><option value="dark">Dark mode</option><option value="yellow">Yellow on black</option></select>
      <button type="button" data-accessibility="grayscale" aria-pressed="false">Grayscale</button>
      <button type="button" data-accessibility="links" aria-pressed="false">Underline and highlight links</button>
      <button type="button" data-accessibility="readable" aria-pressed="false">Dyslexia-friendly spacing</button>
      <button type="button" data-accessibility="line-spacing" aria-pressed="false">Increase line spacing</button>
      <button type="button" data-accessibility="highlight-headings" aria-pressed="false">Highlight headings</button>
      <button type="button" data-accessibility="large-cursor" aria-pressed="false">Large cursor</button>
      <h3>Reading support</h3>
      <button type="button" data-accessibility="ruler" aria-pressed="false">Reading guide</button>
      <button type="button" data-accessibility="motion" aria-pressed="false">Reduce motion</button>
      <div class="accessibility-speech">
        <label class="accessibility-select-label" for="accessibilityRate">Speech speed <output id="accessibilityRateValue">1×</output></label>
        <input id="accessibilityRate" type="range" min="0.7" max="1.5" step="0.1" value="1">
        <div class="accessibility-speech-actions"><button type="button" id="speakSelection"><i class="fas fa-play" aria-hidden="true"></i> Read selection / page</button><button type="button" id="pauseSpeech"><i class="fas fa-pause" aria-hidden="true"></i> Pause / resume</button><button type="button" id="stopSpeech"><i class="fas fa-stop" aria-hidden="true"></i> Stop</button></div>
        <p class="accessibility-speech-status" id="accessibilitySpeechStatus" role="status" aria-live="polite"></p>
      </div>
      <button type="button" class="accessibility-reset" data-accessibility="reset">Reset options</button>
      <button type="button" class="accessibility-install" id="installAppButton" hidden><i class="fas fa-download" aria-hidden="true"></i> Install app</button>
      <p class="accessibility-install-help" id="installAppHelp" hidden>To install, use your browser menu and choose “Install app” or “Add to Home Screen”.</p>
    </div>
  </section>
</div>
<script src="<?= BASE_URL ?>assets/js/main.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/main.js') ?>"></script>
<script src="<?= BASE_URL ?>assets/js/accessibility.js?v=3"></script>
<script src="<?= BASE_URL ?>assets/js/bulk-delete.js?v=1"></script>
<script src="<?= BASE_URL ?>assets/js/form-tabs.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/form-tabs.js') ?>"></script>
</body>
</html>
