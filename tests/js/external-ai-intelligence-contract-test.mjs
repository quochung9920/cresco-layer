import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../assets/external-ai-intelligence.js', import.meta.url), 'utf8');
const sandbox = {
  window: { crescoLayerEditor: { restRoot: '/wp-json/cresco-layer/v1' }, fetch: async () => ({}) },
  console,
  Set,
  Array,
  Object,
  String,
  Number,
  Math,
  JSON,
};
sandbox.window.window = sandbox.window;
vm.createContext(sandbox);
vm.runInContext(source, sandbox);

const enrich = sandbox.window.CrescoLayerExternalAIIntelligence.enrich;
const context = {
  schema: 'cresco-ai-context/v3',
  aiBrief: '# Cresco AI Task',
  task: { request: 'Create a two-column lead hero with form', changeType: 'add' },
  target: { postId: 3, id: 'root123', type: 'container', scope: 'subtree', editableElementIds: ['root123', 'form123'] },
  currentInterface: {
    widgetTypes: { heading: 1, form: 1 },
    element: {
      id: 'root123', elType: 'container', settings: {}, elements: [
        { id: 'head123', elType: 'widget', widgetType: 'heading', settings: { title: 'HELLO', header_size: 'h1' }, elements: [] },
        { id: 'form123', elType: 'widget', widgetType: 'form', settings: {}, elements: [] },
      ],
    },
  },
  runtime: {
    mode: 'exact-runtime',
    widgets: {
      heading: { detailLoaded: true, controls: { title: { type: 'text', default: '' }, header_size: { type: 'select', options: { h1: 'H1', h2: 'H2' } } } },
      'text-editor': { detailLoaded: true, controls: { editor: { type: 'wysiwyg', default: '' } } },
      button: { detailLoaded: true, controls: { text: { type: 'text', default: '' } } },
      'icon-list': { detailLoaded: true, controls: { icon_list: { type: 'repeater', default: [] } } },
      form: { detailLoaded: true, controls: { form_fields: { type: 'repeater', default: [] }, actions_after_submit: { type: 'select2', default: [] } } },
      icon: { detailLoaded: true, controls: { size: { type: 'slider', size_units: ['px'], range: { px: { min: 6, max: 300 } } } } },
    },
    elements: {
      container: { detailLoaded: true, controls: { width: { type: 'slider', size_units: ['px', 'custom'], range: { px: { min: 500, max: 1600 } } } } },
    },
  },
  sourceContext: {
    elementContext: [{
      parent: { id: 'parent1', elType: 'container' },
      index: 1,
      siblings: [{ id: 'before1' }, { id: 'root123' }, { id: 'after1' }],
    }],
  },
  visualSnapshot: { status: 'trusted', confidence: 0.98 },
  designSystem: { colors: {} },
  responsive: { breakpoints: {} },
  outputContract: { schema: 'cresco-ai-output-contract/v2', rules: [] },
  contextQuality: { warnings: [] },
  rules: {},
};

const out = enrich(context);
if (out.widgetIntelligence.roles.headline.preferredWidget !== 'heading') throw new Error('headline widget selection failed');
if (!out.semanticScene.parts.some((p) => p.role === 'headline')) throw new Error('semantic scene failed');
if (out.constructionPlan.recommended.some((step) => !step.widgetType)) throw new Error('construction plan contains unsupported widget');
const after = out.placementContext.allowedPlacements.find((p) => p.intent === 'after-target');
if (!after || after.allowed !== false || after.requiresWiderScope !== true) throw new Error('placement scope safety failed');
if (!out.mutationBoundary.protected.some((item) => item.elementId === 'form123')) throw new Error('form protection missing');
if (out.outputContract.preferredSchema !== 'cresco-ai-mutation/v2') throw new Error('mutation v2 not preferred');
if (out.contextQuality.schema !== 'cresco-context-quality/v3') throw new Error('quality v3 missing');
if (!out.aiBrief.includes('Cresco owns final Elementor IDs')) throw new Error('ID ownership brief missing');
console.log('external AI intelligence contract passed');
