import fs from 'node:fs';

const source = fs.readFileSync(new URL('../../assets/ai-context-v3.js', import.meta.url), 'utf8');

function expect(condition, message) {
  if (!condition) {
    console.error('FAIL:', message);
    process.exit(1);
  }
}

expect(source.includes("schema: 'cresco-ai-context/v3'"), 'AI export must use cresco-ai-context/v3.');
expect(source.includes("schema: 'cresco-layout-graph/v1'"), 'AI export must include a layout graph.');
expect(source.includes("schema: 'cresco-visual-snapshot/v1'"), 'AI export must include structured live visual metrics.');
expect(source.includes("schema: 'cresco-context-quality/v1'"), 'AI export must include a context quality score.');
expect(source.includes("mode: source.mode || 'unknown'"), 'Runtime mode must come from the Exact Runtime package.');
expect(source.includes("detailLoaded: entry.detailLoaded === true"), 'Detailed capability state must survive compaction.');
expect(source.includes("operation: 'insert-element'"), 'Add output template must be delta insert-element.');
expect(source.includes("operation: 'update-setting'"), 'Edit output template must be delta update-setting.');
expect(source.includes("intent: 'replace-target'"), 'Rebuild template must be explicitly destructive.');
expect(source.includes('sourceContextReadOnly: true'), 'Source context must be explicitly read-only.');
expect(source.includes('deltaMutationByDefault: true'), 'Delta mutation must be the default.');
expect(source.includes('nativeControlsFirst: true'), 'Native Elementor controls must remain first choice.');
expect(source.includes('preferGapOverMargins: true'), 'Gap-first sibling spacing policy must be exported.');
expect(source.includes("delivery: 'attach-separately'"), 'Reference images must be attached separately instead of bloating JSON.');
expect(!source.includes('data:image/'), 'AI context must not embed raster image data URLs.');

console.log('AI context v3 contract tests passed.');
