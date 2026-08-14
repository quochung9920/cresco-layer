import fs from 'node:fs';
import assert from 'node:assert/strict';

const source = fs.readFileSync(new URL('../../assets/local-ai-admin.js', import.meta.url), 'utf8');
const integration = fs.readFileSync(new URL('../../includes/LocalAI/AdminIntegration.php', import.meta.url), 'utf8');
const adminCss = fs.readFileSync(new URL('../../assets/admin.css', import.meta.url), 'utf8');

assert.match(source, /Local AI Manager/, 'Cresco admin must render the Local AI Manager inside its page.');
assert.match(source, /Browser \/ Local Bridge/, 'Browser / Local Bridge mode is missing.');
assert.match(source, /WordPress server direct/, 'Server-direct mode is missing.');
assert.match(source, /\/local-ai\/settings/, 'Local AI settings REST route is not used.');
assert.match(source, /\/local-ai\/models/, 'Local model discovery is not wired.');
assert.match(source, /\/local-ai\/diagnostics/, 'Local AI diagnostics are not wired.');
assert.match(source, /only the validated Cresco Skill Runtime can execute Elementor changes/, 'UI must communicate the execution boundary.');
assert.match(source, /Saved tokens are not exposed to browser mode/, 'Browser mode must not expose server-stored API tokens.');
assert.doesNotMatch(source, /document\/elements\/settings|update-setting|replace-element/, 'Local AI admin must not execute Elementor mutations directly.');

assert.match(integration, /'cresco-layer' === \$page/, 'Local AI admin assets must recognize the Cresco page slug independently of the WordPress parent menu hook.');
assert.match(integration, /str_ends_with\( \$screen_id, '_page_cresco-layer' \)/, 'Local AI admin assets must accept WordPress screen IDs whose parent menu changes.');
assert.match(integration, /plugins_page_cresco-layer/, 'Plugins-parent fallback hook must remain supported for local WordPress environments.');
assert.doesNotMatch(adminCss, /\.cresco-layer-admin\{max-width:1280px\}/, 'Cresco Layer admin must not be capped at the legacy 1280px width.');
assert.match(adminCss, /\.cresco-layer-admin\{[^}]*max-width:none/, 'Cresco Layer admin must expand to the available WordPress content width.');

console.log('Local AI admin contract test passed.');
