import fs from 'node:fs';

const engine = fs.readFileSync(new URL('../../assets/fidelity-engine.js', import.meta.url), 'utf8');
const exporter = fs.readFileSync(new URL('../../assets/fidelity-export.js', import.meta.url), 'utf8');
const verifier = fs.readFileSync(new URL('../../assets/fidelity-verification.js', import.meta.url), 'utf8');

function expect(condition, message) {
  if (!condition) {
    console.error('FAIL:', message);
    process.exit(1);
  }
}

expect(engine.includes("'cresco-fidelity-snapshot/v1'"), 'Snapshot schema missing.');
expect(engine.includes("'cresco-geometry-graph/v1'"), 'Geometry graph missing.');
expect(engine.includes('getBoundingClientRect') && engine.includes('getComputedStyle'), 'Rendered geometry/computed-style capture missing.');
expect(engine.includes('relativeX') && engine.includes('relativeY'), 'Parent-relative geometry missing.');
expect(engine.includes('previousId') && engine.includes('nextId'), 'Sibling graph missing.');
expect(engine.includes('horizontalOverflow') && engine.includes('invalidGeometry'), 'Quality blockers missing.');
expect(engine.includes('currentMode'), 'Elementor current breakpoint detection missing.');
expect(engine.includes('scoreChecks') && engine.includes('categoryFloorFailures'), 'Fidelity scoring/gating missing.');
expect(exporter.includes('visualContext') && exporter.includes('capturePackage'), 'AI export visual context integration missing.');
expect(exporter.includes('fidelityPolicy') && exporter.includes('current-preview'), 'AI export fidelity policy/breakpoint contract missing.');
expect(verifier.includes('Fidelity Score') && verifier.includes('Gate ') && verifier.includes('cresco-layer:fidelity-verified'), 'Rendered verification gate UI/event missing.');
expect(verifier.includes('/apply') && verifier.includes('scheduleVerification'), 'Post-apply automatic fidelity verification missing.');

console.log('Fidelity Foundation contract tests passed.');
