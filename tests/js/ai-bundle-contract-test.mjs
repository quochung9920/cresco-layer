import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../assets/ai-bundle.js', import.meta.url), 'utf8');

const sandbox = {
  window: { crescoLayerEditor: { version: '0.24.0' } },
  document: { querySelector() { return null; }, createElement() { return { style: {}, click() {}, remove() {}, getContext() { return null; } }; }, body: { appendChild() {} } },
  console,
  TextEncoder,
  Uint8Array,
  Uint32Array,
  DataView,
  Blob,
  URL: { createObjectURL() { return 'blob:test'; }, revokeObjectURL() {} },
  CSS: { escape(v) { return String(v); } },
  XMLSerializer: class { serializeToString() { return '<div></div>'; } },
  Image: class {},
  Promise,
  Array,
  Object,
  String,
  Number,
  Math,
  JSON,
  Date,
};
sandbox.window.window = sandbox.window;
vm.createContext(sandbox);
vm.runInContext(source, sandbox);

const api = sandbox.window.CrescoLayerAIBundle;
if (!api || api.version !== '4.0.0') throw new Error('External AI bundle API v4 missing');
if (api.packageSchema !== 'cresco-external-ai-package/v1') throw new Error('External package schema missing');
if (api.bundleSchema !== 'cresco-ai-bundle/v4') throw new Error('Bundle v4 schema missing');

const pkg = {
  schema: 'cresco-ai-context/v3',
  target: { postId: 3, id: 'root123', scope: 'subtree' },
  outputContract: { preferredSchema: 'cresco-ai-mutation/v3' },
  contextQuality: { score: 98 },
  widgetIntelligence: {},
  constructionPlan: {},
  semanticBindings: {},
  structureGrammar: {},
  controlExamples: {},
  visualContext: { schema: 'cresco-visual-context/v1' },
};

const external = api.package(pkg);
if (external.schema !== 'cresco-external-ai-package/v1') throw new Error('Self-describing package schema missing');
if (external.workflow !== 'elementor-export-external-ai-import') throw new Error('External workflow marker missing');
if (!external.instructionsForAI.some((line) => line.includes('design request is supplied by the user in the chat'))) throw new Error('Package still assumes the prompt is authored inside Elementor');
if (external.resultContract.preferredSchema !== 'cresco-ai-mutation/v3') throw new Error('Preferred external result schema missing');
if (!external.resultContract.filename.endsWith('.json')) throw new Error('Result filename contract missing');
if (external.context !== pkg) throw new Error('Original AI context must remain embedded losslessly');

const result = await api.build(pkg, null);
if (!(result.blob instanceof Blob)) throw new Error('AI bundle did not produce a ZIP Blob');
if (result.manifest.schema !== 'cresco-ai-bundle/v4') throw new Error('AI bundle manifest v4 schema missing');
if (result.manifest.packageSchema !== 'cresco-external-ai-package/v1') throw new Error('Manifest does not declare package schema');
if (result.manifest.preferredOutputSchema !== 'cresco-ai-mutation/v3') throw new Error('Bundle must prefer semantic design mutation v3');
for (const required of ['README-FOR-CHATGPT.md', 'cresco-package.json', 'elementor-context.json', 'output-contract.json', 'widget-guide.json', 'visual-context.json', 'manifest.json']) {
  if (!result.manifest.files.includes(required)) throw new Error('AI bundle missing ' + required);
}

console.log('External AI bundle v4 contract passed');
