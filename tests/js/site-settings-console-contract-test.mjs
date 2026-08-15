import fs from 'node:fs';
import assert from 'node:assert/strict';

const js = fs.readFileSync(new URL('../../assets/admin.js', import.meta.url), 'utf8');
const php = fs.readFileSync(new URL('../../includes/Admin/AdminPage.php', import.meta.url), 'utf8');
const css = fs.readFileSync(new URL('../../assets/admin.css', import.meta.url), 'utf8');

// The console lives inside the existing Cresco admin page, not a new WordPress menu.
assert.doesNotMatch(php, /add_menu_page/, 'The Site Settings console must not add a top-level WordPress menu.');
assert.match(php, /data-cresco-tab="site-settings"/, 'The console must be a tab of the existing Cresco admin page.');
assert.match(php, /Elementor Global Settings/, 'The console must be titled for what it does.');

// It is an import/sync console, never a design editor: Elementor keeps owning the values.
for (const forbidden of [/id="cresco-layer-ss-primary-color"/, /type="color"/, /Primary Color/i, /H1 Size/i]) {
	assert.doesNotMatch(php, forbidden, 'The console must not offer per-value design inputs; those belong to Elementor Site Settings.');
}

// The three operations, and the routes they use.
assert.match(php, /id="cresco-layer-ss-preview"/, 'Preview action is missing.');
assert.match(php, /id="cresco-layer-ss-import"/, 'Import action is missing.');
assert.match(php, /id="cresco-layer-ss-verify"/, 'Verify action is missing.');
assert.match(js, /\/site-settings\/preview/, 'Preview must call the preview route.');
assert.match(js, /\/site-settings\/apply/, 'Import must call the apply route.');
assert.match(js, /\/site-settings\/verify/, 'Verify must call the verify route.');
assert.match(js, /\/site-settings\/health/, 'Environment status must come from backend discovery.');

// Environment is discovered, never assumed by the browser.
assert.match(php, /id="cresco-layer-ss-environment"/, 'The environment panel is missing.');
assert.doesNotMatch(js, /elementor-classic['"]\s*[,;)]/, 'The adapter name must come from the backend, not be hardcoded in the UI.');
assert.match(js, /loadEnvironment/, 'Discovery must run when the console is opened.');

// A mutation must be confirmed and must not be submittable twice.
assert.match(js, /ssLock\(true\)/, 'Running an operation must lock the buttons.');
assert.match(js, /if\(ssBusy\)return/, 'A second submission while busy must be refused.');
assert.match(js, /window\.confirm\(/, 'Import must be confirmed before it writes.');

// Results are rendered from the structured payload, including the states that are not errors.
assert.match(js, /no_op/, 'A no-op must be rendered as its own state.');
assert.match(js, /already synchronized/, 'A no-op must read as success, not as a failure.');
assert.match(js, /verification_failed/, 'Verification failure must be rendered explicitly.');
assert.match(js, /ssMismatchTable/, 'Mismatches must be rendered as a readable table.');
for (const field of ['semanticPath', 'elementorControl', 'controlType', 'expectedNormalized', 'actualNormalized', 'reason']) {
	assert.ok(js.includes(field), `The mismatch table must surface ${field}.`);
}
assert.match(js, /data\.rollback/, 'Rollback status must be shown to the user.');

// Skipped controls are informational, never errors.
assert.match(js, /\['Skipped',data\.skipped,'warning'\]/, 'Skipped controls must render as warnings, not errors.');
assert.match(js, /\['Preserved',data\.preserved/, 'Preserved values must be listed separately.');

// Technical detail exists but stays out of the way.
assert.match(php, /Show technical details/, 'A technical detail view must be available.');
assert.match(php, /id="cresco-layer-ss-technical" hidden/, 'Technical details must be collapsed by default.');

// Styling reuses the existing token system rather than introducing a framework.
assert.match(css, /\.cresco-layer-ss-env-card/, 'The console must carry its own styles in the existing stylesheet.');
assert.match(css, /var\(--cl-/, 'The console must use the existing Cresco design tokens.');

console.log('Elementor Site Settings console contract test passed.');
