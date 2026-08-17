import fs from 'node:fs';

const source = fs.readFileSync(new URL('../../assets/ai-panel.js', import.meta.url), 'utf8');
const styles = fs.readFileSync(new URL('../../assets/ai-panel.css', import.meta.url), 'utf8');

function expect(condition, message) {
  if (!condition) {
    console.error('FAIL:', message);
    process.exit(1);
  }
}

expect(source.includes('External AI Exchange'), 'External exchange panel title is missing.');
expect(source.includes('Export to ChatGPT'), 'Export tab is missing.');
expect(source.includes('Import AI Result'), 'Import tab is missing.');
expect(source.includes('Export for ChatGPT'), 'Primary external export action is missing.');
expect(source.includes('JSON only'), 'Single-file JSON export fallback is missing.');
expect(source.includes('Drop ChatGPT result JSON here'), 'File-first result import is missing.');
expect(source.includes("setProfile('exact')"), 'External export must force Exact Runtime.');
expect(source.includes('&context=full'), 'External export must use Full Context because the design prompt lives outside Elementor.');
expect(source.includes("workflow: 'external-file-exchange'"), 'External exchange intent marker is missing.');
expect(source.includes("request: ''"), 'Elementor must not require the design prompt before export.');
expect(source.includes('cresco-export-scope') && source.includes('cresco-import-scope'), 'Explicit export/import scope controls are missing.');
expect(source.includes('value="widget"') && source.includes('value="subtree"') && source.includes('value="document"'), 'Element, subtree and document scopes must be available.');
expect(source.includes('CrescoLayerAIBundle.exportJson') && source.includes('CrescoLayerAIBundle.export'), 'Panel is not wired to both external package exporters.');
expect(source.includes("aiResult: raw"), 'Import must send raw external AI output to the tolerant server normalizer.');
expect(source.includes('/preview'), 'Import preview endpoint is missing.');
expect(source.includes('/apply'), 'Import apply endpoint is missing.');
expect(source.includes('This result targets ') && source.includes('Select that original Elementor element'), 'Target mismatch protection is missing.');
expect(source.includes('Existing UI is preserved by delta operations.'), 'Delta safety feedback is missing.');
expect(source.includes('data-cresco-ai-legacy-hidden'), 'Legacy toolbar should stay hidden in the primary UX.');
expect(source.includes('Apply to Elementor'), 'Apply action should remain user-facing.');
expect(!source.includes('What do you want AI to do?'), 'Embedded prompt UI must not return to the primary workflow.');
expect(!source.includes('Create / Edit'), 'Embedded create/edit tab must not return to the primary workflow.');
expect(styles.includes('.cresco-ai-segmented input:focus-visible+span'), 'Segmented radio controls must expose a visible keyboard focus indicator.');
expect(styles.includes('.cresco-ai-tabs button:focus-visible'), 'External exchange tabs must expose a visible keyboard focus indicator.');
expect(styles.includes('.cresco-ai-primary:focus-visible') && styles.includes('.cresco-ai-secondary:focus-visible'), 'Primary and secondary panel actions must expose visible keyboard focus.');

console.log('External AI exchange panel contract tests passed.');
