import fs from 'node:fs';

const source = fs.readFileSync(new URL('../../assets/ai-panel.js', import.meta.url), 'utf8');

function expect(condition, message) {
  if (!condition) {
    console.error('FAIL:', message);
    process.exit(1);
  }
}

expect(source.includes('AI Design'), 'Unified panel title is missing.');
expect(source.includes('Create / Edit'), 'Create/Edit tab is missing.');
expect(source.includes('Import Result'), 'Import tab is missing.');
expect(source.includes('Prepare for AI'), 'Prepare action is missing.');
expect(source.includes('AI Context Quality'), 'Context quality UI is missing.');
expect(source.includes("setProfile('exact')"), 'Main AI workflow must force Exact Runtime.');
expect(source.includes("scope = data.elType === 'widget' || !!data.widgetType ? 'widget' : 'subtree'"), 'Scope should be inferred from the selected Elementor type.');
expect(source.includes('window.CrescoLayerAIIntent'), 'Task intent must be passed into context compilation.');
expect(source.includes("aiResult: raw"), 'Import must send raw AI output to the tolerant server normalizer.');
expect(source.includes('/preview'), 'Import preview endpoint is missing.');
expect(source.includes('/apply'), 'Import apply endpoint is missing.');
expect(source.includes('Existing UI is preserved by delta operations.'), 'Delta safety feedback is missing.');
expect(source.includes('data-cresco-ai-legacy-hidden'), 'Legacy multi-button toolbar should be hidden in the main UX.');
expect(source.includes('Apply to Elementor'), 'Apply action should use user-facing language rather than patch jargon.');

console.log('Unified Cresco AI panel contract tests passed.');
