import fs from 'node:fs';
import assert from 'node:assert/strict';

const panel = fs.readFileSync(new URL('../../assets/ai-panel.js', import.meta.url), 'utf8');
const entrypoints = fs.readFileSync(new URL('../../assets/external-ai-entrypoints.js', import.meta.url), 'utf8');
const bundle = fs.readFileSync(new URL('../../assets/ai-bundle.js', import.meta.url), 'utf8');
const css = fs.readFileSync(new URL('../../assets/ai-panel.css', import.meta.url), 'utf8');
const bootstrap = fs.readFileSync(new URL('../../assets/editor-bootstrap.js', import.meta.url), 'utf8');
const assetsPhp = fs.readFileSync(new URL('../../includes/Support/Assets.php', import.meta.url), 'utf8');

assert.match(panel, /External AI Exchange/, 'Primary editor UX must describe an external exchange, not an embedded AI builder.');
assert.match(panel, /Export to ChatGPT/, 'Primary export tab is missing.');
assert.match(panel, /Import AI Result/, 'Primary import tab is missing.');
assert.match(panel, /Export for ChatGPT/, 'Primary external export action is missing.');
assert.match(panel, /JSON only/, 'Single JSON package fallback is missing.');
assert.match(panel, /Selected element/, 'Element scope must be available.');
assert.match(panel, /Selected subtree/, 'Subtree scope must be available.');
assert.match(panel, /Entire page/, 'Document scope must be available.');
assert.match(panel, /Reference image \(optional\)/, 'Reference-image bundle support is missing.');
assert.match(panel, /Drop ChatGPT result JSON here/, 'External result import must be file-first.');
assert.match(panel, /Preview Changes/, 'Import must preview before apply.');
assert.match(panel, /Apply to Elementor/, 'Import apply action is missing.');
assert.match(panel, /This result targets/, 'Import must block obvious target mismatch before preview.');
assert.doesNotMatch(panel, /What do you want AI to do\?/, 'Design prompts must not be authored in Elementor in the primary workflow.');
assert.doesNotMatch(panel, /Create \/ Edit/, 'Embedded create/edit UX must not return to the primary workflow.');

assert.match(entrypoints, /Cresco - Export to ChatGPT/, 'Legacy-compatible external entrypoint module must still route to export.');
assert.match(entrypoints, /Cresco - Import AI Result/, 'Legacy-compatible external entrypoint module must still route to import.');
assert.doesNotMatch(entrypoints, /Add\/remove AI selection/, 'Legacy AI-selection action must not remain in the replacement entrypoint group.');
assert.match(css, /cresco-ai-legacy-hidden=\"true\"\]\{display:none!important\}/, 'Legacy floating editor toolbar must be hidden if a legacy tool is loaded.');

assert.match(bundle, /cresco-external-ai-package\/v1/, 'External single-file package schema is missing.');
assert.match(bundle, /cresco-ai-bundle\/v4/, 'External ZIP bundle schema is missing.');
assert.match(bundle, /README-FOR-CHATGPT\.md/, 'Bundle needs an AI-readable entrypoint.');
assert.match(bundle, /cresco-package\.json/, 'Bundle needs the machine-readable external package.');
assert.match(bundle, /current-preview\.png/, 'Bundle should support rendered preview evidence.');

assert.match(assetsPhp, /cresco-layer-editor-bootstrap/, 'Elementor must enqueue the safe bootstrap.');
assert.doesNotMatch(assetsPhp, /wp_enqueue_script\(\s*'cresco-layer-external-ai-exchange-policy'/, 'External exchange policy must not be eager-loaded during Elementor startup.');
assert.doesNotMatch(assetsPhp, /wp_enqueue_script\(\s*'cresco-layer-external-ai-entrypoints'/, 'External entrypoints must not be eager-loaded during Elementor startup.');
assert.match(bootstrap, /external-ai-exchange-policy\.js/, 'External exchange policy must remain available through the lazy exchange manifest.');
assert.match(bootstrap, /ai-panel\.js/, 'External panel must remain available through the lazy exchange manifest.');
assert.match(bootstrap, /Cresco - Export to ChatGPT/, 'Safe bootstrap must provide the startup-safe Elementor context-menu export action.');
assert.match(bootstrap, /Cresco - Import AI Result/, 'Safe bootstrap must provide the startup-safe Elementor context-menu import action.');
assert.doesNotMatch(assetsPhp, /wp_enqueue_script\( 'cresco-layer-semantic-ai'/, 'Embedded Local AI UI must not be loaded in the default Elementor editor.');
assert.doesNotMatch(assetsPhp, /wp_enqueue_style\( 'cresco-layer-semantic-ai'/, 'Embedded Local AI UI styles must not be loaded in the default Elementor editor.');

console.log('Cresco Layer external AI editor UX contract passed.');
