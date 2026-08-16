import fs from 'node:fs';

const source = fs.readFileSync(new URL('../../assets/ai-panel.js', import.meta.url), 'utf8');
if (!source.includes('Export for ChatGPT')) throw new Error('AI Panel is missing primary external export action');
if (!source.includes('JSON only')) throw new Error('AI Panel is missing JSON-only external package export');
if (!source.includes('cresco-ai-mutation/v3')) throw new Error('AI Panel does not advertise semantic design mutation v3');
if (!source.includes('CrescoLayerAIBundle.export')) throw new Error('AI Panel is not wired to the ZIP bundle exporter');
if (!source.includes('CrescoLayerAIBundle.exportJson')) throw new Error('AI Panel is not wired to the single JSON exporter');
if (!source.includes('Drop ChatGPT result JSON here')) throw new Error('AI Panel is not file-first on import');
console.log('External AI panel bundle integration contract passed');
