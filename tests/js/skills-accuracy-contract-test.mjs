import fs from 'node:fs';
import assert from 'node:assert/strict';

const source = fs.readFileSync(new URL('../../assets/skills-accuracy.js', import.meta.url), 'utf8');

assert.match(source, /displayLabel/, 'Skill panel should render disambiguated semantic display labels.');
assert.match(source, /semanticBase|semanticId/, 'Skill panel should expose semantic identity metadata.');
assert.match(source, /data-cresco-semantic-id/, 'Skill cards should be annotated with semantic IDs.');
assert.match(source, /\/documents\/.*\/skills\//, 'Semantic label decorator must read the selected widget runtime profile.');
assert.doesNotMatch(source, /document\/elements\/settings/, 'Semantic label decoration must never mutate Elementor settings.');

console.log('Semantic skill label contract test passed.');
