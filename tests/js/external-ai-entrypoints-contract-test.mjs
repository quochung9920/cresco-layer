import fs from 'node:fs';

const source = fs.readFileSync(new URL('../../assets/external-ai-entrypoints.js', import.meta.url), 'utf8');

function expect(condition, message) {
  if (!condition) {
    console.error('FAIL:', message);
    process.exit(1);
  }
}

expect(source.includes("title: 'Cresco - Export to ChatGPT'"), 'External export context-menu action is missing.');
expect(source.includes("title: 'Cresco - Import AI Result'"), 'External import context-menu action is missing.');
expect(source.includes("open('export')") && source.includes("open('import')"), 'Context-menu actions are not routed to the new panel.');
expect(source.includes("group.name === 'cresco-layer-ai-exchange'"), 'Legacy Cresco context group replacement is missing.');
expect(source.includes('bridge.openEdit = function ()') && source.includes('bridge.openImport = function ()'), 'Legacy bridge methods are not redirected.');
expect(source.includes("value.scope") && source.includes("value.scope.mode"), 'Patch v1 scope inference is missing.');
expect(source.includes("setScope(box, 'cresco-import-scope', scope)"), 'Imported result scope is not synchronized into the panel.');
expect(source.includes("setScope(box, 'cresco-export-scope', 'document')"), 'No-selection export should default to document scope.');
expect(source.includes("setScope(box, 'cresco-import-scope', 'document')"), 'No-selection import should default to document scope.');
expect(!source.includes('Add/remove AI selection'), 'Legacy AI-selection UX must not be reintroduced.');

console.log('External AI entrypoint contract tests passed.');
