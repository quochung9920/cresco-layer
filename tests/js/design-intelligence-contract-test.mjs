import fs from 'node:fs';

const intelligence = fs.readFileSync(new URL('../../assets/design-intelligence.js', import.meta.url), 'utf8');
const contract = fs.readFileSync(new URL('../../assets/semantic-design-contract.js', import.meta.url), 'utf8');
const policy = fs.readFileSync(new URL('../../assets/ai-context-policy.js', import.meta.url), 'utf8');
const panel = fs.readFileSync(new URL('../../assets/semantic-design-ui.js', import.meta.url), 'utf8');

function expect(condition, message) {
  if (!condition) {
    console.error('FAIL:', message);
    process.exit(1);
  }
}

expect(intelligence.includes("schema: 'cresco-design-intelligence/v1'"), 'Design Intelligence schema is missing.');
expect(intelligence.includes("repository: 'nextlevelbuilder/ui-ux-pro-max-skill'"), 'UI/UX Pro Max inspiration must be attributed.');
expect(intelligence.includes("license: 'MIT'"), 'External design-intelligence inspiration license metadata is missing.');
expect(intelligence.includes("principles-inspired-no-runtime-dependency"), 'Cresco must not acquire a runtime dependency on the reference project.');
expect(intelligence.includes("variance") && intelligence.includes("motion") && intelligence.includes("density"), 'Variance, motion and density design dials are required.');
expect(intelligence.includes("category: 'accessibility'") && intelligence.includes("category: 'touch-interaction'") && intelligence.includes("category: 'performance'"), 'Professional quality-priority hierarchy is incomplete.');
expect(intelligence.includes('reuse the current Elementor design language') || intelligence.includes('Reuse the current Elementor design language'), 'Active Elementor design language must remain authoritative.');
expect(intelligence.includes('data-cresco-design-dial'), 'AI panel must expose optional design dials.');

expect(contract.includes("'cresco-ai-mutation/v3'"), 'Mutation v3 must be the semantic design contract.');
expect(contract.includes('semanticDesignAdd') && contract.includes('semanticDesignEdit'), 'External AI needs add and edit v3 templates.');
expect(contract.includes('Use designChanges for existing elements'), 'Existing-element semantic edit contract is missing.');
expect(contract.includes('Prefer existing Active Kit global references'), 'Active Kit global-first design policy is missing.');
expect(contract.includes('$new:section'), 'New element examples should use temporary references, not final Elementor IDs.');

expect(policy.includes('function structureGrammar(pkg)'), 'Runtime structure grammar must be exported.');
expect(policy.includes("canAcceptChildren: false"), 'Widgets must not accept arbitrary child nodes by default.');
expect(policy.includes('runtime-managed-nested-content'), 'Nested widgets need an explicit runtime-managed content policy.');
expect(policy.includes('CrescoLayerDesignIntelligence.enrich'), 'Design intelligence must participate in the final AI context pipeline.');

expect(panel.includes('cresco-ai-mutation/v3 (preferred)'), 'Import UI must advertise mutation v3 as preferred.');
expect(panel.includes('semantic design delta'), 'Prepared-context UI must explain the semantic design workflow.');

console.log('Semantic Design Intelligence contract tests passed.');
