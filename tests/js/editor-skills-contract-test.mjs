import fs from 'node:fs';
import assert from 'node:assert/strict';

const source = fs.readFileSync(new URL('../../assets/skills.js', import.meta.url), 'utf8');

assert.match(source, /deterministic-widget-skills-v1/, 'Skill palette must declare deterministic runtime mode.');
assert.match(source, /usesChatbot\s*=\s*false/, 'Skill palette must explicitly remain chatbot-free.');
assert.match(source, /No chatbot · deterministic/, 'UI must communicate that commands do not use a chatbot.');
assert.match(source, /\/skills\//, 'Skill palette must use per-element skill REST endpoints.');
assert.match(source, /liveSettings/, 'Skill resolver must receive the live selected-element settings.');
assert.match(source, /document\/elements\/settings/, 'Skill execution must use Elementor live native settings API.');
assert.match(source, /document\/history\/start-log/, 'Skill execution must create Elementor history entries.');
assert.match(source, /Skill attempted to escape the selected widget/, 'Live runtime must block skill target escape.');
assert.match(source, /operation\.operation === 'update-setting'/, 'Live runtime must explicitly allow native setting updates.');
assert.match(source, /Widget skill runtime only permits live setting operations/, 'Structural operations must not be silently executed by the widget-only palette.');
assert.match(source, /event\.altKey/, 'Skill palette should expose its keyboard shortcut.');
assert.doesNotMatch(source, /openai|anthropic|chatgpt|completion|messages\/create/i, 'Widget skill runtime must not embed an LLM/chat provider.');

console.log('Deterministic Elementor widget skill palette contract test passed.');
