import fs from 'node:fs';

const reasoning = fs.readFileSync(new URL('../../assets/design-reasoning.js', import.meta.url), 'utf8');
const policy = fs.readFileSync(new URL('../../assets/ai-context-policy.js', import.meta.url), 'utf8');
const bundle = fs.readFileSync(new URL('../../assets/ai-bundle.js', import.meta.url), 'utf8');
const semantic = fs.readFileSync(new URL('../../assets/semantic-design-contract.js', import.meta.url), 'utf8');

function expect(condition, message) {
  if (!condition) {
    console.error('FAIL:', message);
    process.exit(1);
  }
}

expect(reasoning.includes("schema: 'cresco-design-reasoning/v1'"), 'Design reasoning schema is missing.');
expect(reasoning.includes("repository: 'nextlevelbuilder/ui-ux-pro-max-skill'"), 'Reference project attribution is missing.');
expect(reasoning.includes("license: 'MIT'"), 'Reference project license metadata is missing.');
expect(reasoning.includes('workflow-and-priority-model-inspired-no-dataset-vendoring'), 'Cresco must not vendor the reference datasets.');
expect(reasoning.includes('PRODUCT_PROFILES'), 'Product-specific reasoning profiles are missing.');
expect(reasoning.includes("return 'landing'") && reasoning.includes("return 'dashboard'") && reasoning.includes("return 'lead-generation'"), 'Page archetype detection is incomplete.');
expect(reasoning.includes("schema: 'cresco-design-quality-gates/v1'"), 'Machine-readable quality gates are missing.');
expect(reasoning.includes('a11y-readable-contrast') && reasoning.includes('behavior-preservation') && reasoning.includes('responsive-overflow'), 'Critical/high quality gates are incomplete.');
expect(reasoning.includes("schema: 'cresco-reference-translation/v1'"), 'Reference-image translation contract is missing.');
expect(reasoning.includes('analyze-and-adapt'), 'Reference images must be adapted rather than blindly copied.');
expect(reasoning.includes("schema: 'cresco-design-vocabulary/v1'"), 'Semantic design vocabulary is missing.');
expect(reasoning.includes('decisionOrder'), 'Professional decision priority order is missing.');
expect(reasoning.includes('Use visual verification after apply'), 'Reasoning must close the loop with visual verification.');

expect(policy.includes('CrescoLayerDesignReasoning.enrich'), 'Design reasoning must participate in final AI context enrichment.');
expect(semantic.includes('Read designReasoning before choosing composition'), 'Semantic mutation contract must consume the design brief.');
expect(semantic.includes('do not narrate design reasoning') || semantic.includes('Do not output analysis'), 'External AI must return mutations, not reasoning prose.');
expect(bundle.includes("'06-design-reasoning.json'"), 'AI Bundle must include a standalone design reasoning file.');
expect(bundle.includes("schema: 'cresco-ai-bundle/v3'"), 'AI Bundle v3 manifest is required.');
expect(bundle.includes('Accessibility, behavior safety, hierarchy, responsive fit and Active Kit consistency'), 'Task brief must expose the quality hierarchy.');

console.log('Professional design reasoning contract tests passed.');
