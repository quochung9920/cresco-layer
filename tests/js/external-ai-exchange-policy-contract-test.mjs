import fs from 'node:fs';
import vm from 'node:vm';

const bundleSource = fs.readFileSync(new URL('../../assets/ai-bundle.js', import.meta.url), 'utf8');
const policySource = fs.readFileSync(new URL('../../assets/external-ai-exchange-policy.js', import.meta.url), 'utf8');

function expect(condition, message) {
  if (!condition) {
    console.error('FAIL:', message);
    process.exit(1);
  }
}

const sandbox = {
  window: { crescoLayerEditor: { version: '0.24.0', restRoot: '/wp-json/cresco-layer/v1' } },
  document: { querySelector() { return null; }, createElement() { return { click() {}, remove() {} }; }, body: { appendChild() {} } },
  console,
  TextEncoder,
  Uint8Array,
  Uint32Array,
  DataView,
  Blob,
  URL: { createObjectURL() { return 'blob:test'; }, revokeObjectURL() {} },
  CSS: { escape(value) { return String(value); } },
  XMLSerializer: class {},
  Image: class {},
  Headers,
  Response,
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
vm.runInContext(bundleSource, sandbox);
vm.runInContext(policySource, sandbox);

const policy = sandbox.window.CrescoLayerExternalAIExchangePolicy;
const bundle = sandbox.window.CrescoLayerAIBundle;
expect(policy && policy.schema === 'cresco-external-exchange-policy/v1', 'External exchange policy is missing.');

const elementContext = {
  schema: 'cresco-ai-context/v3',
  target: { postId: 7, id: 'abc1234', scope: 'subtree' },
  outputContract: { preferredSchema: 'cresco-layer-patch/v1', rules: [], templates: {} },
};
const normalizedElement = policy.normalize(elementContext);
expect(normalizedElement.outputContract.preferredSchema === 'cresco-ai-mutation/v3', 'Element/subtree export must prefer semantic mutation v3.');
expect(normalizedElement.externalExchangePolicy.mode === 'semantic-target-mutation', 'Element/subtree external mode is wrong.');
const externalElement = bundle.package(normalizedElement);
expect(externalElement.resultContract.preferredSchema === 'cresco-ai-mutation/v3', 'Element package preferred schema is wrong.');
expect(externalElement.resultContract.acceptedSchemas.includes('cresco-ai-mutation/v3'), 'Element package must advertise semantic v3 as accepted.');
expect(externalElement.resultContract.acceptedSchemas.includes('cresco-layer-patch/v1'), 'Element package must preserve compatible patch import support.');

const documentContext = {
  schema: 'cresco-ai-context/v3',
  target: { postId: 7, id: '', scope: 'document' },
  outputContract: { preferredSchema: 'cresco-ai-mutation/v3', rules: [], templates: { semanticDesignAdd: {}, semanticDesignEdit: {} } },
};
const normalizedDocument = policy.normalize(documentContext);
expect(normalizedDocument.outputContract.preferredSchema === 'cresco-layer-patch/v1', 'Document export must prefer patch v1.');
expect(normalizedDocument.outputContract.scope === 'document', 'Document output contract must preserve document scope.');
expect(normalizedDocument.externalExchangePolicy.mode === 'document-patch', 'Document external mode is wrong.');
expect(normalizedDocument.outputContract.templates.documentEdit.scope.mode === 'document', 'Document edit template is missing document scope.');
expect(normalizedDocument.outputContract.templates.documentInsert.operations[0].parentId === '', 'Document insert template must support top-level insertion.');
expect(normalizedDocument.outputContract.templates.documentInsert.operations[0].element.ref === '$new:top-level-section', 'Document insert template must delegate new ID allocation to Cresco.');
expect(!('semanticDesignAdd' in normalizedDocument.outputContract.templates), 'Invalid document-level semantic add template must be removed.');
const externalDocument = bundle.package(normalizedDocument);
expect(externalDocument.resultContract.preferredSchema === 'cresco-layer-patch/v1', 'Document external package must prefer patch v1.');
expect(externalDocument.resultContract.acceptedSchemas.length === 1, 'Document external package should not advertise target-root mutation schemas.');
expect(externalDocument.resultContract.acceptedSchemas[0] === 'cresco-layer-patch/v1', 'Document external package must advertise only document-safe patch v1.');

const rulesBefore = normalizedDocument.outputContract.rules.length;
policy.normalize(normalizedDocument);
expect(normalizedDocument.outputContract.rules.length === rulesBefore, 'External policy normalization must be idempotent.');

console.log('External AI exchange policy contract tests passed.');
