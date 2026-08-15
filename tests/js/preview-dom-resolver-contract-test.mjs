import fs from 'node:fs';
import assert from 'node:assert/strict';

const source = fs.readFileSync(new URL('../../assets/ai-context-v3.js', import.meta.url), 'utf8');

/*
 * The Elementor editor chrome carries data-id attributes too — the Navigator lists every element by
 * id — so searching the top document first finds a panel row and measures the sidebar instead of the
 * design. An AI handed those numbers designs against the sidebar, and the old contract could not
 * tell: targetBounds was non-null either way.
 */

// Preview iframes must be searched before the editor document, and named frames before anonymous ones.
const previewFn = source.slice(source.indexOf('function previewDocuments'), source.indexOf('function insideEditorChrome'));
assert.ok(previewFn.length > 0, 'previewDocuments must exist.');

const namedAt = previewFn.indexOf('#elementor-preview-iframe');
// The editor document also appears in an early return for documents without querySelectorAll, so the
// ordering that matters is where it is *appended* relative to the iframe collection.
const editorPushAt = previewFn.lastIndexOf("out.push({ doc: document, source: 'editor-document'");
assert.ok(namedAt !== -1, 'The named Elementor preview iframe must be recognised.');
assert.ok(editorPushAt !== -1, 'The editor document must still be reachable as a last resort.');
assert.ok(namedAt < editorPushAt, 'The preview iframe must be collected before the editor document.');
assert.match(previewFn, /rank: 3/, 'A named preview frame must carry the highest rank.');
assert.match(previewFn, /rank: 0/, 'The editor document must carry the lowest rank.');

// A node inside editor UI is refused rather than measured.
assert.match(source, /function insideEditorChrome/, 'Editor chrome must be detectable.');
for (const marker of ['elementor-panel', 'elementor-navigator']) {
	assert.ok(source.includes(marker), `Editor chrome detection must know about ${marker}.`);
}
assert.match(source, /if \(insideEditorChrome\(node\)\)/, 'A chrome hit must be skipped, not returned.');

// Confidence is computed, and a non-null node is not treated as proof on its own.
assert.match(source, /function resolveTargetNode/, 'A confidence-scoring resolver must exist.');
assert.match(source, /function applyGeometryChecks/, 'Geometry must be sanity-checked against the tree.');
assert.match(source, /confidence/, 'The resolver must report confidence.');

// The anomalies the brief calls out must each be detected.
const checks = source.slice(source.indexOf('function applyGeometryChecks'), source.indexOf('function domNode'));
assert.match(checks, /descendants but occupies less than/, 'A large tree rendering in a sliver must be flagged.');
assert.match(checks, /No descendant reported visible bounds/, 'A tree with no visible children must be flagged.');
assert.match(checks, /outside the preview viewport/, 'Out-of-viewport geometry must be flagged.');
assert.match(checks, /effectively zero-sized/, 'Zero-sized bounds must be flagged.');

// The snapshot states its own trustworthiness instead of leaving it to be inferred.
assert.match(source, /status: trusted \? 'trusted' : 'untrusted'/, 'The snapshot must declare trusted/untrusted.');
assert.match(source, /Target DOM could not be confidently resolved inside Elementor preview canvas/, 'An unresolved target must say so in the words the contract specifies.');
assert.match(source, /source: resolved\.source/, 'The snapshot must name where the measurement came from.');

// Context quality must lose points for an untrusted snapshot rather than award them for a non-null field.
const quality = source.slice(source.indexOf('function quality('));
assert.doesNotMatch(quality, /ok: !!visual\.targetBounds/, 'Presence of bounds must not be full credit; it is non-null even for the sidebar.');
assert.match(quality, /visualConfidence/, 'Visual credit must be proportional to resolver confidence.');
assert.match(quality, /'untrusted' === visual\.status/, 'An untrusted snapshot must reduce the score.');
assert.match(quality, /warnings/, 'The quality report must surface a warning the user can see.');
assert.match(quality, /cresco-context-quality\/v2/, 'The richer quality contract must be versioned.');

console.log('Preview DOM resolver contract test passed.');
