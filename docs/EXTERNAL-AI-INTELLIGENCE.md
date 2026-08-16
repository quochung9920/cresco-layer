# Cresco Layer External AI Intelligence

Cresco Layer 0.20 extends the 0.18/0.19 external-AI foundation with a **Semantic Design Compiler**, professional design intelligence and rendered verification. Elementor remains the source of truth. Cresco does not become a page builder and does not maintain a parallel design system.

## Goal

The external model should reason about **design intent**, not memorize Elementor internals. Cresco exports enough information for the model to understand the existing interface, then resolves the model's semantic design request into controls proven by the active Elementor installation.

```text
Elementor working document
  -> Exact Runtime + Active Kit + layout/visual context
  -> task-aware runtime widget discovery
  -> Widget Intelligence + Semantic Scene + Placement/Mutation Boundary
  -> Design Intelligence + Structure Grammar + Semantic Bindings
  -> external AI returns cresco-ai-mutation/v3 (preferred)
  -> SemanticDesignCompiler lowers semantic layout/style/responsive intent
  -> cresco-ai-mutation/v2
  -> AIMutationCompiler
  -> Cresco ID allocation + deterministic normalization
  -> internal cresco-layer-patch/v1
  -> SemanticPatchGuard
  -> preview / apply / persistence verification
  -> optional rendered verification
```

## Existing intelligence retained

The top-level context remains `cresco-ai-context/v3` for compatibility. It continues to expose task-aware runtime discovery, Widget Intelligence, Semantic Scene, Construction Plan, Placement Context, Mutation Boundary, control examples, runtime selection, Active Kit/design-system context, responsive foundation and runtime-proven semantic content bindings.

Final Elementor IDs for new nodes remain Cresco-owned. Existing IDs remain authoritative. Custom CSS remains last resort. Global references and Dynamic Tags remain preserve-by-default.

## Design Intelligence in 0.20

Schema: `cresco-design-intelligence/v1`.

The export now adds a professional UI/UX guidance layer with product archetype, style keywords, design dials, a semantic spacing scale, quality priorities and anti-patterns. The optional dials are:

- variance — minimal/structured to bold/asymmetric;
- motion — subtle to expressive;
- density — spacious to dense.

The principles are informed by the public MIT-licensed `nextlevelbuilder/ui-ux-pro-max-skill` project at revision `a38d04c3d5c298c851dbe5e6ee1965ee3de42cb5`. Cresco records that provenance but has no runtime dependency on the repository and does not import its large datasets. Active Elementor Kit values still outrank generic design suggestions.

See `docs/DESIGN-INTELLIGENCE.md`.

## Semantic Design Mutation v3

`cresco-ai-mutation/v3` is now preferred for external design work.

For new UI, nodes may express:

- semantic `content`;
- `layoutIntent`;
- `styleIntent`;
- `responsiveIntent`;
- `accessibilityIntent`;
- structural `children`;
- exact runtime `settings` only as an expert escape hatch.

For existing elements, `designChanges` specifies an `elementId` and semantic content/layout/style/responsive/accessibility changes. Cresco reads the live element type and resolves exact runtime controls before producing v2 changes.

The compiler fails closed if it cannot prove a control, option, unit or responsive suffix. The model does not get permission to invent an Elementor setting simply because a CSS concept exists.

See `docs/SEMANTIC-DESIGN-COMPILER.md`.

## Structure Grammar

Schema: `cresco-structure-grammar/v1`.

Structural Elementor element types may own child elements when scope permits. Widgets do not receive arbitrary child nodes through semantic mutation. Nested/disclosure widgets such as Accordion, Tabs, Carousel, Menu or Loop are marked as runtime-managed nested content; their internal storage must come from native controls/repeaters or a dedicated adapter rather than DOM inference.

## AI Bundle v2

`cresco-ai-bundle/v2` adds a separate `05-design-intelligence.json` and upgrades the widget guide/output contract for mutation v3. The bundle still includes the full Context v3, task brief, optional best-effort `current-desktop.png`, optional reference image and manifest.

See `docs/AI-BUNDLE.md`.

## Rendered verification

After a successful semantic mutation apply, the Import panel can run **Verify Render**. Cresco resolves temporary refs to final Elementor IDs and checks measurable rendered geometry/computed-style/accessibility/UX conditions in the Elementor preview iframe.

This is a deterministic verification layer, not a claim of pixel-perfect image comparison. A mismatch does not silently mutate the page; repair remains another reviewed mutation.

See `docs/VISUAL-VERIFICATION.md`.

## Backward compatibility

0.20 continues to accept:

- `cresco-ai-mutation/v2`;
- `cresco-layer-patch/v1`;
- `cresco-layer-ai-result/v1`.

Checksum freshness is not reintroduced. Existing placeholder rejection, delta-first policy, explicit destructive rebuild intent, SemanticPatchGuard, Elementor Document API persistence and post-apply verification remain in force.
