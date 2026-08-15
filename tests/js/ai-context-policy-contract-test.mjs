import fs from 'node:fs';

const source = fs.readFileSync(new URL('../../assets/ai-context-policy.js', import.meta.url), 'utf8');

function expect(condition, message) {
  if (!condition) {
    console.error('FAIL:', message);
    process.exit(1);
  }
}

expect(source.includes("pkg.schema !== 'cresco-ai-context/v3'"), 'Policy must operate only on Context v3.');
expect(source.includes("target.scope === 'widget' ? 'widget' : 'subtree'"), 'Policy must derive output scope from selected target.');
expect(source.includes('A widget cannot own a new sibling/section'), 'Widget add operations must be refused with a clear reason.');
expect(source.includes('target.canAcceptChildren = mode !== \'widget\''), 'Context must tell AI whether the target can accept children.');
expect(source.includes('Widget target: edit native settings'), 'Widget-safe output guidance is missing.');
expect(source.includes('control.emittableKeys = keys'), 'Every compact runtime control must expose exact emittable keys.');
expect(source.includes('runtime.activeResponsiveSuffixes = suffixes'), 'Active responsive suffixes must be exported once for AI reference.');
expect(source.includes("name !== 'desktop'"), 'Desktop must remain the base control rather than an invented suffix.');
expect(source.includes('function rebuildSkeleton(pkg, target)'), 'Rebuild contract must derive its skeleton from the selected live target.');
expect(source.includes('if (current.widgetType) element.widgetType = current.widgetType'), 'Widget rebuilds must preserve the widget type rather than becoming a container.');
expect(source.includes('contract.templates.rebuild.element = rebuildSkeleton(pkg, target)'), 'Rebuild template must use the selected target skeleton.');

console.log('AI context scope, responsive emission and rebuild policy tests passed.');
