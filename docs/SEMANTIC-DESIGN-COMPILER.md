# Semantic Design Compiler

Cresco Layer 0.20 introduces `cresco-ai-mutation/v3`, an AI-facing design-intent contract that sits above Elementor control names.

The external model should describe **what the interface should do and look like**. Cresco resolves that intent against the active Elementor runtime and lowers it to `cresco-ai-mutation/v2`, then the existing ID allocator, Mutation Normalizer, SemanticPatchGuard, preview/apply and persistence verification continue unchanged.

```text
External AI
  -> cresco-ai-mutation/v3
  -> SemanticDesignCompiler
  -> exact active-runtime controls
  -> cresco-ai-mutation/v2
  -> AIMutationCompiler
  -> cresco-layer-patch/v1
  -> SemanticPatchGuard
  -> Preview / Apply / Verify
```

## Add and rebuild

New nodes can use:

- `content`
- `layoutIntent`
- `styleIntent`
- `responsiveIntent`
- `accessibilityIntent`
- `children`
- `settings` as an expert escape hatch

Example:

```json
{
  "schema": "cresco-ai-mutation/v3",
  "intent": "add",
  "target": { "postId": 3, "id": "abc1234" },
  "placement": { "mode": "inside-end" },
  "nodes": [
    {
      "ref": "$new:hero-content",
      "widgetIntent": "container",
      "layoutIntent": {
        "direction": "column",
        "gap": "24px",
        "padding": "32px"
      },
      "responsiveIntent": {
        "mobile": {
          "layout": { "gap": "16px" }
        }
      },
      "children": [
        {
          "ref": "$new:title",
          "widgetIntent": "heading",
          "content": {
            "text": "A healthier, drier home starts here",
            "semanticLevel": "h1"
          },
          "styleIntent": {
            "fontSize": "clamp(40px, 6vw, 72px)"
          }
        }
      ]
    }
  ]
}
```

No final Elementor ID is required for new nodes. Cresco owns final ID allocation.

## Edit

Existing UI uses `designChanges` so a model can request design changes without naming Elementor settings:

```json
{
  "schema": "cresco-ai-mutation/v3",
  "intent": "edit",
  "target": { "postId": 3, "id": "abc1234" },
  "designChanges": [
    {
      "elementId": "def5678",
      "content": { "text": "Updated headline" },
      "styleIntent": {
        "fontSize": "48px",
        "textAlign": "center"
      },
      "responsiveIntent": {
        "mobile": {
          "style": { "fontSize": "32px", "textAlign": "left" }
        }
      }
    }
  ]
}
```

Cresco reads the live element by ID, determines its actual widget/element type, resolves only controls present in the active runtime, and converts those semantic changes into v2 `update-setting` operations.

## Fail-closed guarantees

The compiler never invents a control name or responsive suffix. A semantic property is compiled only when an exact candidate control exists on the active runtime entry. Select/choose values must be valid options. Slider/dimension units must be supported. Device names must come from Elementor's active breakpoint manager. Fluid `clamp/min/max/calc/var` values use the native `custom` unit only when that control exposes it.

If any mapping is ambiguous or unavailable, compilation stops with an actionable error. Explicit `settings` remain available for expert cases, but they still pass through SemanticPatchGuard.

## Structure policy

Arbitrary Elementor child nodes are allowed only under structural element types such as Containers. Widgets are treated as native content/interaction units. Nested Accordion, Tabs, Carousel, Menu and similar widgets must use their runtime-proven native repeater/content controls or a future dedicated adapter; Cresco does not infer a nested storage schema from rendered DOM.

## Backward compatibility

`cresco-ai-mutation/v2`, `cresco-layer-patch/v1` and `cresco-layer-ai-result/v1` remain accepted. Version 3 is preferred for external design work because it removes unnecessary Elementor implementation detail from the model's responsibility.
