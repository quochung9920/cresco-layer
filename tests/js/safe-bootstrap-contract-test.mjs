import fs from 'node:fs';
import assert from 'node:assert/strict';

const bootstrap = fs.readFileSync(new URL('../../assets/editor-bootstrap.js', import.meta.url), 'utf8');
const assets = fs.readFileSync(new URL('../../includes/Support/Assets.php', import.meta.url), 'utf8');

assert.match(assets, /cresco-layer-editor-bootstrap/, 'Editor must enqueue the safe bootstrap.');
assert.match(assets, /assets\/editor-bootstrap\.js/, 'Safe bootstrap asset path is missing.');
assert.match(assets, /'safeMode'\s*=>\s*false/, 'Localized safe-mode state is missing.');
assert.match(assets, /cresco_safe/, 'Emergency cresco_safe query flag is missing.');
assert.match(assets, /'mode'\s*=>\s*'safe-lazy'/, 'Safe-lazy bootstrap mode is missing.');

for (const forbidden of [
  "wp_enqueue_script( 'cresco-layer-editor'",
  "wp_enqueue_script( 'cresco-layer-exact-runtime-export'",
  "wp_enqueue_script( 'cresco-layer-fidelity-engine'",
  "wp_enqueue_script( 'cresco-layer-ai-context-v3'",
  "wp_enqueue_script( 'cresco-layer-ai-bundle'",
  "wp_enqueue_script( 'cresco-layer-visual-verification'",
  "wp_enqueue_script( 'cresco-layer-skills'",
  "wp_enqueue_script( 'cresco-layer-skills-accuracy'",
]) {
  assert.ok(!assets.includes(forbidden), `Heavy editor startup enqueue must be removed: ${forbidden}`);
}

assert.match(bootstrap, /exchangeScripts\s*=\s*\[/, 'Lazy exchange script manifest is missing.');
assert.match(bootstrap, /exact-runtime-export\.js/, 'Exact Runtime must be lazy-loadable.');
assert.match(bootstrap, /fidelity-engine\.js/, 'Fidelity Engine must be lazy-loadable.');
assert.match(bootstrap, /ai-bundle\.js/, 'External AI bundle must be lazy-loadable.');
assert.match(bootstrap, /ai-panel\.js/, 'External exchange panel must be lazy-loadable.');
assert.match(bootstrap, /elementor\/init/, 'Bootstrap should activate from the Elementor ready event.');
assert.match(bootstrap, /passive-timeout/, 'Bootstrap must fail passive after a bounded startup budget.');
assert.doesNotMatch(bootstrap, /new\s+MutationObserver/, 'Safe bootstrap must not observe the editor DOM.');
assert.doesNotMatch(bootstrap, /setInterval\s*\(/, 'Safe bootstrap must not poll forever.');
assert.doesNotMatch(bootstrap, /window\.fetch\s*=/, 'Safe bootstrap must not intercept global fetch.');

console.log('Cresco Layer safe lazy bootstrap contract passed.');
