# Cresco AI Bundle v3

Cresco Layer 0.21 packages the prepared external-AI context into a local ZIP so an external model receives the task, Elementor runtime knowledge, design intelligence, professional design reasoning, output contract and visual references as one coherent hand-off.

Schema: `cresco-ai-bundle/v3`

Default files:

- `01-TASK.md` — concise goal, target, placement, product/page design brief, widget/ID/output rules, decision order and quality priorities.
- `02-context.json` — complete prepared `cresco-ai-context/v3`.
- `03-widget-guide.json` — Widget Intelligence, Construction Plan, Semantic Bindings, Structure Grammar, semantic design-intent vocabulary and control examples.
- `04-output-contract.json` — AI response contract, preferring `cresco-ai-mutation/v3`.
- `05-design-intelligence.json` — design dials, professional UI/UX guidance, Active Kit design system, responsive context and mutation boundary.
- `06-design-reasoning.json` — product/page reasoning profile, visual hierarchy, composition strategy, reference-image translation and machine-readable quality gates.
- `manifest.json` — bundle metadata and the actual file list.
- `current-desktop.png` — optional best-effort raster capture of the selected target in Elementor preview.
- `reference-<filename>` — optional reference image selected in the Cresco AI panel.

## Semantic design workflow

The external model should prefer design intent over Elementor implementation details:

```text
task + reference
  -> design reasoning
  -> widget/structure intelligence
  -> content/layout/style/responsive/accessibility intent
  -> cresco-ai-mutation/v3
  -> Cresco SemanticDesignCompiler
  -> active-runtime Elementor controls
  -> semantic mutation v2
  -> internal patch/v1
```

Version 2 and legacy result/patch formats remain accepted, but v3 semantic mutation is preferred for new design work.

## Design intelligence and reasoning

The bundle carries both:

- `cresco-design-intelligence/v1` — task-derived design dials, spacing intent and ordered professional quality priorities;
- `cresco-design-reasoning/v1` — product/page-specific objective, hierarchy, composition, semantic design vocabulary, reference translation and quality gates.

Both layers combine the user's task with the current Elementor design system. Active Kit remains source of truth; Cresco does not create a parallel token system.

The workflow is informed by the MIT-licensed `nextlevelbuilder/ui-ux-pro-max-skill` project, with provenance recorded in the exported context. Cresco has no runtime dependency on that repository and does not vendor its large searchable datasets or Python tooling.

## Reference-image translation

A reference image is treated as design evidence, not as raw Elementor instructions. The model is asked to extract hierarchy, composition, proportions, spacing rhythm, typography character, color relationships, surface depth and component patterns, then adapt those qualities through the current Active Kit, Widget Intelligence and Exact Runtime.

Critical accessibility, behavior-preservation and responsive rules outrank decorative similarity to the reference.

## Raster capture

Raster capture is deliberately best-effort rather than fabricated. Cresco resolves the selected target in Elementor's same-origin preview iframe, clones the subtree with computed styles, serializes it through SVG `foreignObject`, then paints that SVG to a canvas and exports PNG.

The capture may be unavailable when the browser cannot serialize/rasterize the subtree safely, for example because of cross-origin assets, unsupported browser rendering behavior, very large target geometry or a missing preview node. The ZIP still exports in that case and the manifest records `raster.status = "unavailable"`.

The structured `visualSnapshot` and `layoutGraph` remain authoritative context when no raster is available.

## ZIP implementation

The bundle writer uses a minimal local uncompressed ZIP implementation with CRC32 and does not load a third-party archive library. This keeps the editor workflow self-contained and avoids adding a remote dependency.

## External AI guidance

The external model should read files in numeric order, especially `06-design-reasoning.json` before finalizing composition. It must use only runtime-proven widgets/controls, prefer `cresco-ai-mutation/v3`, preserve protected/global/dynamic bindings, and return only the requested semantic delta. Final Elementor IDs for new nodes are owned by Cresco.
