# `cresco-ai-mutation/v2`

`cresco-ai-mutation/v2` is the preferred external-AI response contract in Cresco Layer 0.18. It keeps the AI focused on semantic intent while Cresco compiles that intent into the internal scoped `cresco-layer-patch/v1` format.

## Core principles

- Existing Elementor IDs are authoritative.
- Final IDs for new nodes are allocated by Cresco.
- `widgetIntent` must name a type proven by the active Elementor runtime.
- Exact Elementor `settings` are still validated by `SemanticPatchGuard`.
- Visual edits may not silently modify protected behavioral/external settings.
- Add operations cannot escape the selected editable scope.
- Full rebuild remains explicit and target-local.

## Add

```json
{
  "schema": "cresco-ai-mutation/v2",
  "intent": "add",
  "target": {
    "postId": 3,
    "id": "abc1234"
  },
  "placement": {
    "mode": "inside-end"
  },
  "nodes": [
    {
      "ref": "$new:headline",
      "role": "headline",
      "widgetIntent": "heading",
      "content": {
        "text": "A healthier home starts here",
        "semanticLevel": "h2"
      },
      "settings": {}
    }
  ]
}
```

Supported Add placement modes in 0.18 are `inside-start` and `inside-end`. If the exported placement context marks `before-target` or `after-target` as `requiresWiderScope`, select/export the parent Container rather than writing outside the scope.

Nested nodes are represented through `children` (or `elements` for compatibility):

```json
{
  "ref": "$new:card",
  "widgetIntent": "container",
  "settings": {},
  "children": [
    {
      "ref": "$new:title",
      "widgetIntent": "heading",
      "content": {
        "text": "Card title",
        "semanticLevel": "h3"
      }
    }
  ]
}
```

## Edit

Edits use exact existing element IDs and exact runtime setting keys:

```json
{
  "schema": "cresco-ai-mutation/v2",
  "intent": "edit",
  "target": {
    "postId": 3,
    "id": "abc1234"
  },
  "changes": [
    {
      "elementId": "def5678",
      "setting": "title",
      "value": "Updated heading"
    },
    {
      "elementId": "def5678",
      "setting": "typography_font_size",
      "value": {
        "unit": "px",
        "size": 48,
        "sizes": []
      }
    }
  ]
}
```

Remove one setting with:

```json
{
  "elementId": "def5678",
  "setting": "margin",
  "remove": true
}
```

Do not invent responsive suffixes. Use only `emittableKeys` exported for the actual control/runtime.

## Protected behavioral edits

Generic visual mutations reject setting names associated with external/behavioral configuration, including common form webhooks/email routing, redirects, payments, query/template sources and code-like controls.

Only an explicit user request may opt into those changes with:

```json
{
  "allowBehavioralChanges": true
}
```

This flag does not bypass runtime or semantic validation.

## Move

```json
{
  "schema": "cresco-ai-mutation/v2",
  "intent": "move",
  "target": {
    "postId": 3,
    "id": "abc1234"
  },
  "elementId": "def5678",
  "placement": {
    "parentId": "abc1234",
    "position": 1
  }
}
```

Both the moved element and destination parent must be inside the exported editable scope.

## Remove

```json
{
  "schema": "cresco-ai-mutation/v2",
  "intent": "remove",
  "target": {
    "postId": 3,
    "id": "abc1234"
  },
  "elementIds": ["def5678"]
}
```

The selected root itself cannot be deleted through this narrow semantic contract.

## Rebuild

```json
{
  "schema": "cresco-ai-mutation/v2",
  "intent": "rebuild",
  "target": {
    "postId": 3,
    "id": "abc1234"
  },
  "nodes": [
    {
      "widgetIntent": "container",
      "settings": {},
      "children": []
    }
  ]
}
```

Rebuild requires exactly one root. The root Elementor `elType` must match the live selected target; a widget rebuild must also retain that widget type. Select a Container for structural redesigns.

## Content shortcuts

The compiler supports a small semantic content layer while exact `settings` remain available:

- heading-like widget: `content.text` -> `title`, `content.semanticLevel` -> `header_size`
- text-editor-like widget: `content.html` or `content.text` -> `editor`
- button-like widget: `content.text` -> `text`, `content.url` -> `link`
- image-like widget: `content.image` -> `image`
- icon-like widget: `content.icon` -> `selected_icon`

Explicit `settings` take precedence over these content shortcuts and are still checked against the active Elementor capability catalog.

## ID policy

For new nodes, prefer temporary references:

```json
{
  "ref": "$new:cta-primary",
  "widgetIntent": "button"
}
```

References must be unique within the answer. Cresco allocates final collision-free Elementor IDs against the current working document and removes `ref` before persistence. A repeated temporary ref fails closed rather than merging two nodes.

## Compilation and validation

```text
cresco-ai-mutation/v2
  -> AIMutationCompiler
  -> ElementorIdGenerator
  -> MutationNormalizer (deterministic safe repairs only)
  -> cresco-layer-patch/v1
  -> PatchValidator
  -> SemanticPatchGuard
  -> Preview / Apply / Verify
```

The semantic contract is not a validation bypass. If a control, widget, unit, option, responsive key or value is not supported by the live runtime, the mutation is rejected.
