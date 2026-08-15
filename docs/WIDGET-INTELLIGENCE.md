# Widget Intelligence

Cresco Layer 0.18 adds `cresco-widget-intelligence/v1` to external AI context. Its job is to answer a different question from Exact Runtime:

- Exact Runtime: **what controls does this installed widget actually expose?**
- Widget Intelligence: **which installed widget is semantically appropriate for this part of the interface?**

Both are required. Semantic advice may never create a capability that the active Elementor runtime does not prove.

## Runtime-first selection

The intelligence layer builds an index from `runtime.widgets` and `runtime.elements`. Role candidates are then filtered against that index.

For example, a headline may prefer `heading`, and a CTA may prefer `button`, but those recommendations are emitted only when those types exist in the current runtime. Alternatives are likewise runtime-proven.

Third-party Elementor add-ons can participate when their registered type is present in runtime metadata. A Pro-only widget is not recommended on an installation where it is absent.

## Common semantic families

Current deterministic families cover layout, headings, text, buttons, icons, lists, images/media, forms, navigation, query/loop widgets, carousels, disclosure widgets, video, commerce and code/HTML fallbacks.

Example role record:

```json
{
  "headline": {
    "preferredWidget": "heading",
    "alternatives": [],
    "avoidWidgets": ["text-editor", "html"],
    "reason": "Render semantic headings or short prominent text with native heading level and typography controls.",
    "runtimeProven": true
  }
}
```

## Relationship to semantic scene

`semanticScene` analyzes the existing selected subtree and assigns deterministic role hints with confidence. `constructionPlan` uses task wording plus Widget Intelligence to suggest a runtime-supported structure for common new UI patterns.

The external model should therefore reason in this order:

```text
user task / reference
  -> existing semantic scene
  -> desired semantic roles
  -> widget intelligence recommendation
  -> exact runtime controls
  -> Active Kit / responsive rules
  -> semantic mutation
```

## Server enforcement

Recommendations are not merely prompt text. When `cresco-ai-mutation/v2` introduces or rebuilds a node, `AIMutationCompiler` verifies its `widgetIntent`/element type against the active `CapabilityScanner` catalog. An invented type is rejected before an internal patch is produced.

This closes a class of failures where a model understands the visual role but emits a widget name unavailable on the actual site.

## Protected families

Some widget families mix presentation with behavioral/external configuration. The exported mutation boundary identifies settings that should remain unchanged during ordinary visual work, including form submission destinations, webhooks, query/template sources, navigation sources, transactional settings and code-like content.

A visual request should still style those widgets through native controls; it should not silently change what they submit, query, execute or purchase.

## Custom CSS

Widget Intelligence does not make Custom CSS a preferred widget/control path. If a semantic role maps to a native widget and the runtime exposes the required control, external AI should use that control. Custom CSS remains a fallback for behavior that cannot be expressed through proven native controls and remains subject to `SemanticPatchGuard` analysis.
