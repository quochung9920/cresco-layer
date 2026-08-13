import fs from 'node:fs';
import assert from 'node:assert/strict';

const source = fs.readFileSync(new URL('../../assets/editor.js', import.meta.url), 'utf8');

assert.match(source, /Edit with AI/, 'Editor toolbar should expose one human-friendly Edit with AI entry point.');
assert.match(source, /This element only/, 'Element-only scope must be described in user language.');
assert.match(source, /This section \+ children/, 'Subtree scope must be described in user language.');
assert.match(source, /Selected elements/, 'Selection scope must be exposed.');
assert.match(source, /type=\"file\"/, 'Import modal must accept JSON files directly.');
assert.match(source, /Drop Cresco AI result here/, 'Import modal must support drag-and-drop.');
assert.match(source, /cresco-layer-patch\/v1/, 'Import must identify the Cresco patch schema.');
assert.match(source, /cresco-layer-ai-package\/v2/, 'Import must detect an AI input package selected by mistake.');
assert.match(source, /payload\.type === 'elementor'/, 'Import must detect raw Elementor clipboard data selected by mistake.');
assert.match(source, /patchTargetCheck/, 'Import must verify the current Elementor target before validation.');
assert.match(source, /Copy diagnostics/, 'Persistent validation errors should expose copyable diagnostics.');
assert.match(source, /cresco-ai-input-post/, 'AI input downloads should use an unmistakable filename prefix.');

console.log('Cresco Layer editor AI UX contract test passed.');
