import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../assets/ai-bundle.js', import.meta.url), 'utf8');

const sandbox = {
  window: { crescoLayerEditor: { version: '0.21.0' } },
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
if (!api || api.version !== '3.0.0') throw new Error('AI bundle API v3 missing');

const pkg = {
  schema: 'cresco-ai-context/v3',
  task: { request: 'Create hero', referenceImage: { provided: true } },
  target: { postId: 3, id: 'root123', scope: 'subtree' },
  placementContext: { allowedPlacements: [] },
  outputContract: { preferredSchema: 'cresco-ai-mutation/v3' },
  contextQuality: { score: 95 },
  widgetIntelligence: {},
  constructionPlan: {},
  semanticBindings: {},
  structureGrammar: {},
  semanticDesignIntent: {},
  designIntelligence: { productArchetype: 'service', designDials: { variance: { tier: 'balanced-modern' }, motion: { tier: 'standard' }, density: { tier: 'standard' } } },
  designReasoning: { schema: 'cresco-design-reasoning/v1', productArchetype: 'service', pageArchetype: 'landing', objective: 'Build trust and convert.', decisionOrder: ['accessibility-and-behavior-safety'], referenceTranslation: { provided: true } },
  designSystem: {},
  responsive: {},
  mutationBoundary: {},
  controlExamples: {},
};

const result = await api.build(pkg, null);
if (!(result.blob instanceof Blob)) throw new Error('AI bundle did not produce a zip Blob');
if (result.manifest.schema !== 'cresco-ai-bundle/v3') throw new Error('AI bundle manifest v3 schema missing');
if (result.manifest.preferredOutputSchema !== 'cresco-ai-mutation/v3') throw new Error('AI bundle must prefer semantic design mutation v3');
if (result.manifest.designReasoningSchema !== 'cresco-design-reasoning/v1') throw new Error('AI bundle must declare design reasoning schema');
for (const required of ['01-TASK.md', '02-context.json', '03-widget-guide.json', '04-output-contract.json', '05-design-intelligence.json', '06-design-reasoning.json', 'manifest.json']) {
  if (!result.manifest.files.includes(required)) throw new Error('AI bundle missing ' + required);
}
console.log('AI bundle v3 contract passed');
