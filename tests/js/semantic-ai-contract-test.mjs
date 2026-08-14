import fs from 'node:fs';
import assert from 'node:assert/strict';

const source = fs.readFileSync(new URL('../../assets/semantic-ai.js', import.meta.url), 'utf8');

assert.match(source, /Analyze AI/, 'Cresco Skills must expose Local AI analysis for the selected widget.');
assert.match(source, /\/local-ai\/.*\/analyze/, 'Selected-widget Local AI analyze route is not wired.');
assert.match(source, /\/local-ai\/.*\/validate/, 'Browser inference must return to Cresco for server-side plan validation.');
assert.match(source, /format:\s*'json'/, 'Ollama browser inference must request JSON output.');
assert.match(source, /requestedSkills/, 'Validated semantic skill plans are not rendered/applied.');
assert.match(source, /Selection changed/, 'AI analysis must be invalidated when Elementor selection changes.');
assert.match(source, /rollback/, 'Batch apply must have rollback behavior for partial failures.');
assert.match(source, /document\/elements\/settings/, 'Validated Local AI plans must execute through Elementor native settings commands.');
assert.doesNotMatch(source, /custom_css|insert-element|replace-element/, 'Local AI widget analysis must not bypass native widget skills with structural/CSS mutation.');

console.log('Semantic Local AI editor contract test passed.');
