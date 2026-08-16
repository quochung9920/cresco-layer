import fs from 'node:fs';

const source = fs.readFileSync(new URL('../../assets/visual-verification.js', import.meta.url), 'utf8');

function expect(condition, message) {
  if (!condition) {
    console.error('FAIL:', message);
    process.exit(1);
  }
}

expect(source.includes("schema: 'cresco-visual-verification/v1'"), 'Visual verification schema is missing.');
expect(source.includes('#elementor-preview-iframe'), 'Verification must read the real Elementor preview iframe.');
expect(source.includes('getComputedStyle'), 'Verification must inspect rendered computed styles.');
expect(source.includes("'layout.gap'"), 'Layout gap verification is missing.');
expect(source.includes("'style.fontSize'"), 'Typography verification is missing.');
expect(source.includes("'a11y.ariaLabel'"), 'Accessibility verification is missing.');
expect(source.includes("'ux.touchTarget'"), 'Touch-target UX verification is missing.');
expect(source.includes("'ux.horizontalOverflow'"), 'Horizontal-overflow verification is missing.');
expect(source.includes("schema === 'cresco-ai-mutation/v3'"), 'Apply capture must recognize semantic design mutation v3.');
expect(source.includes("/apply"), 'Visual verification must attach only to the apply workflow.');
expect(source.includes('resolvedRefs'), 'Temporary refs must resolve to final Elementor IDs before verification.');
expect(source.includes('data-cresco-visual-verify'), 'Import UI needs an explicit Verify Render action.');
expect(source.includes('does not claim') || source.includes('not a claim'), 'Verifier must not claim pixel-perfect similarity when it only checks geometry/computed styles.');

console.log('Rendered visual verification contract tests passed.');
