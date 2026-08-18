import fs from 'node:fs';
import assert from 'node:assert/strict';

const sync = fs.readFileSync(new URL('../../assets/export-target-sync.js', import.meta.url), 'utf8');
const diagnosticsClient = fs.readFileSync(new URL('../../assets/export-error-diagnostics.js', import.meta.url), 'utf8');
const resolver = fs.readFileSync(new URL('../../includes/AI/ExportTargetResolver.php', import.meta.url), 'utf8');
const gate = fs.readFileSync(new URL('../../includes/AI/ExportTargetGate.php', import.meta.url), 'utf8');
const controller = fs.readFileSync(new URL('../../includes/REST/ExportTargetSyncController.php', import.meta.url), 'utf8');
const diagnostics = fs.readFileSync(new URL('../../includes/Diagnostics/ExportDiagnostics.php', import.meta.url), 'utf8');
const assets = fs.readFileSync(new URL('../../includes/Support/Assets.php', import.meta.url), 'utf8');
const plugin = fs.readFileSync(new URL('../../includes/Plugin.php', import.meta.url), 'utf8');

assert.match(sync, /document\/save\/auto/, 'Export preflight must use Elementor autosave through the Commands API.');
assert.match(sync, /force:\s*true/, 'Autosave must be forced so freshly-created client elements reach the server before export.');
assert.match(sync, /MAX_STATUS_ATTEMPTS\s*=\s*4/, 'Server synchronization polling must be bounded.');
assert.match(sync, /export-target-status/, 'Editor preflight must verify the target against server-side Elementor data.');
assert.match(sync, /client_present/, 'Target status requests must carry live-editor target evidence when it is available.');
assert.match(sync, /clientTargetPresent/, 'Client target existence must be checked before releasing a scoped export.');
assert.match(sync, /stale-target/, 'Client preflight must distinguish a stale selected target.');
assert.match(sync, /sync-pending/, 'Client preflight must distinguish a live target that is still waiting for autosave synchronization.');
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
assert.match(resolver, /sync-pending/, 'Resolver must distinguish a live editor target that has not reached server data.');
assert.match(resolver, /stale-target/, 'Resolver must distinguish a target that the live editor says is gone.');
assert.match(resolver, /target-missing/, 'Resolver must preserve an explicit unknown/missing server state when client evidence is unavailable.');
assert.match(resolver, /clientPresent/, 'Resolver response must preserve the client-presence evidence used for classification.');
assert.doesNotMatch(resolver, /update_post_meta|->save\s*\(/, 'Resolver must remain read-only and never persist Elementor data itself.');

assert.match(controller, /export-target-status/, 'Target status REST endpoint is missing.');
assert.match(controller, /client_present/, 'Target status REST endpoint must accept live-editor presence evidence.');
assert.match(controller, /current_user_can\( 'edit_post'/, 'Target status endpoint must require edit permission.');

assert.match(gate, /rest_dispatch_request/, 'Scoped export must have a server-side hard gate after REST permission checks and before PackageBuilder runs.');
assert.match(gate, /-100,\s*4/, 'REST dispatch gate must register all four rest_dispatch_request arguments.');
assert.doesNotMatch(gate, /add_filter\(\s*'rest_pre_dispatch'/, 'Target hard gate must not inspect document state at rest_pre_dispatch.');
assert.doesNotMatch(gate, /add_filter\(\s*'rest_request_before_callbacks'/, 'Target hard gate must not inspect document state before the route permission callback.');
assert.match(gate, /target-sync-gate/, 'Hard-gate failures must expose a dedicated diagnostic stage.');
assert.match(gate, /cresco_export_target_not_ready/, 'Hard gate must return a specific target-not-ready error.');
assert.match(gate, /'stale-target' === \$state \? 410 : 409/, 'Stale target and synchronization conflicts must not surface as generic 500 errors.');
assert.match(gate, /targetStatus/, 'Hard-gate responses must include the complete target status payload.');
assert.match(gate, /crescoDiagnostic/, 'Pre-callback target failures must carry their own diagnostics payload.');

assert.match(diagnostics, /get_url_params\(\)/, 'Export diagnostics must resolve route parameters when they are already available.');
assert.match(diagnostics, /\/documents\/\(\\d\+\)\/export\$/, 'Export diagnostics must have a route fallback for the document post ID at rest_pre_dispatch time.');
assert.match(diagnosticsClient, /targetStatusFrom/, 'Client diagnostics must preserve server target-state diagnostics.');
assert.match(diagnosticsClient, /withClientPresence/, 'Direct export requests must carry live target evidence when target-sync can provide it.');
assert.match(diagnosticsClient, /entry\.stage === 'target-sync-gate'/, 'Target-sync failures must never trigger Full-to-Smart context recovery.');

assert.match(assets, /assets\/export-target-sync\.js/, 'Passive target sync guard must be available in the Elementor editor.');
assert.match(plugin, /ExportTargetSyncController/, 'Target sync REST controller must be registered by the plugin.');
assert.match(plugin, /ExportTargetGate/, 'Server export target gate must be wired by the plugin.');
assert.match(plugin, /\$target_gate->register_hooks\(\)/, 'Server target gate must be active before REST export callbacks execute.');

console.log('Cresco Layer export target sync contract passed.');
