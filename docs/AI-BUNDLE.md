# Cresco AI Bundle v2

Cresco Layer 0.20 packages the prepared external-AI context into a local ZIP so an external model receives the task, Elementor runtime knowledge, design intelligence, output contract and visual references as one coherent hand-off.

Schema: `cresco-ai-bundle/v2`

Default files:

- `01-TASK.md` — concise goal, target, placement, design dials, widget/ID/output rules and quality priorities.
- `02-context.json` — complete prepared `cresco-ai-context/v3`.
- `03-widget-guide.json` — Widget Intelligence, Construction Plan, Semantic Bindings, Structure Grammar, semantic design-intent vocabulary and control examples.
- `04-output-contract.json` — AI response contract, preferring `cresco-ai-mutation/v3`.
- `05-design-intelligence.json` — professional design guidance, Active Kit design system, responsive context and mutation boundary.
- `manifest.json` — bundle metadata and the actual file list.
- `current-desktop.png` — optional best-effort raster capture of the selected target in Elementor preview.
- `reference-<filename>` — optional reference image selected in the Cresco AI panel.

## Semantic design workflow

The external model should prefer design intent over Elementor implementation details:

```text
content/layout/style/responsive/accessibility intent
  -> cresco-ai-mutation/v3
  -> Cresco SemanticDesignCompiler
  -> active-runtime Elementor controls
  -> semantic mutation v2
  -> internal patch/v1
```

Version 2 and legacy result/patch formats remain accepted, but v3 is preferred for new design work.

## Design intelligence

The bundle carries a deterministic `cresco-design-intelligence/v1` profile. It combines the user's task, optional variance/motion/density dials, professional UI/UX quality priorities and the current Elementor design system. Active Kit remains source of truth; this layer does not create a parallel token system.

The quality principles are informed by the MIT-licensed `nextlevelbuilder/ui-ux-pro-max-skill` project, with provenance recorded in the exported context. Cresco has no runtime dependency on that repository and does not copy its large design datasets into the plugin.

## Raster capture

Raster capture is deliberately best-effort rather than fabricated. Cresco resolves the selected target in Elementor's same-origin preview iframe, clones the subtree with computed styles, serializes it through SVG `foreignObject`, then paints that SVG to a canvas and exports PNG.

The capture may be unavailable when the browser cannot serialize/rasterize the subtree safely, for example because of cross-origin assets, unsupported browser rendering behavior, very large target geometry or a missing preview node. The ZIP still exports in that case and the manifest records `raster.status = "unavailable"`.

The structured `visualSnapshot` and `layoutGraph` remain authoritative context when no raster is available.

## ZIP implementation

The bundle writer uses a minimal local uncompressed ZIP implementation with CRC32 and does not load a third-party archive library. This keeps the editor workflow self-contained and avoids adding a remote dependency.

## External AI guidance

The external model should read files in numeric order, use only runtime-proven widgets/controls, prefer `cresco-ai-mutation/v3`, preserve protected/global/dynamic bindings, and return only the requested semantic delta. Final Elementor IDs for new nodes are owned by Cresco.
