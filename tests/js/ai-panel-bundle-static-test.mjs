import fs from 'node:fs';

const source = fs.readFileSync(new URL('../../assets/ai-panel.js', import.meta.url), 'utf8');
if (!source.includes('Export AI Bundle')) throw new Error('AI Panel is missing Export AI Bundle action');
if (!source.includes('cresco-ai-mutation/v2')) throw new Error('AI Panel does not advertise semantic mutation v2');
if (!source.includes('CrescoLayerAIBundle')) throw new Error('AI Panel is not wired to the AI Bundle exporter');
console.log('AI panel bundle integration contract passed');
