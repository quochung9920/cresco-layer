import fs from 'node:fs';
import assert from 'node:assert/strict';

const sync = fs.readFileSync(new URL('../../assets/export-target-sync.js', import.meta.url), 'utf8');
const resolver = fs.readFileSync(new URL('../../includes/AI/ExportTargetResolver.php', import.meta.url), 'utf8');
const controller = fs.readFileSync(new URL('../../includes/REST/ExportTargetSyncController.php', import.meta.url), 'utf8');
const assets = fs.readFileSync(new URL('../../includes/Support/Assets.php', import.meta.url), 'utf8');
const plugin = fs.readFileSync(new URL('../../includes/Plugin.php', import.meta.url), 'utf8');

assert.match(sync, /document\/save\/auto/, 'Export preflight must use Elementor autosave through the Commands API.');
assert.match(sync, /force:\s*true/, 'Autosave must be forced so freshly-created client elements reach the server before export.');
assert.match(sync, /MAX_STATUS_ATTEMPTS\s*=\s*4/, 'Server synchronization polling must be bounded.');
assert.match(sync, /export-target-status/, 'Editor preflight must verify the target against server-side Elementor data.');
assert.match(sync, /data-cresco-export-bundle/, 'The guard must cover the primary ChatGPT bundle action.');
assert.match(sync, /data-cresco-export-json/, 'The guard must cover JSON-only export too.');
assert.match(sync, /document\.addEventListener\('click',\s*guardExport,\s*true\)/, 'Preflight must intercept the explicit Export click before the panel sends its request.');
assert.doesNotMatch(sync, /new\s+MutationObserver/, 'Target sync must not observe Elementor DOM mutations.');
assert.doesNotMatch(sync, /setInterval\s*\(/, 'Target sync must not add an infinite polling interval.');
assert.doesNotMatch(sync, /window\.fetch\s*=/, 'Target sync must not monkey-patch global fetch.');

assert.match(resolver, /cresco-export-target-status\/v1/, 'Target resolver schema is missing.');
assert.match(resolver, /get_doc_or_auto_save/, 'Resolver must inspect the same working/autosave source used by export.');
assert.match(resolver, /\$manager->get\( \$post_id \)/, 'Resolver must also inspect the main Elementor document for diagnostics.');
assert.match(resolver, /sync-required/, 'Resolver must distinguish a lagging working autosave.');
assert.match(resolver, /client-ahead/, 'Resolver must distinguish an editor client that is ahead of server data.');
assert.doesNotMatch(resolver, /update_post_meta|->save\s*\(/, 'Resolver must remain read-only and never persist Elementor data itself.');

assert.match(controller, /export-target-status/, 'Target status REST endpoint is missing.');
assert.match(controller, /current_user_can\( 'edit_post'/, 'Target status endpoint must require edit permission.');
assert.match(assets, /assets\/export-target-sync\.js/, 'Passive target sync guard must be available in the Elementor editor.');
assert.match(plugin, /ExportTargetSyncController/, 'Target sync REST controller must be registered by the plugin.');

console.log('Cresco Layer export target sync contract passed.');
