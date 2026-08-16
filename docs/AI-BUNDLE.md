# Cresco AI Bundle v1

Cresco Layer 0.19 can package the prepared external-AI context into a local ZIP for easier transfer to an external model.

Schema: `cresco-ai-bundle/v1`

Default files:

- `01-TASK.md` — concise goal, target, placement, widget/ID/output rules.
- `02-context.json` — complete prepared `cresco-ai-context/v3`.
- `03-widget-guide.json` — Widget Intelligence, Construction Plan, Semantic Bindings/Structure Grammar when present, and control examples.
- `04-output-contract.json` — AI response contract, preferring `cresco-ai-mutation/v2`.
- `manifest.json` — bundle metadata and the actual file list.
- `current-desktop.png` — optional best-effort raster capture of the selected target in Elementor preview.
- `reference-<filename>` — optional reference image selected in the Cresco AI panel.

## Raster capture

Raster capture is deliberately best-effort rather than fabricated. Cresco resolves the selected target in Elementor's same-origin preview iframe, clones the subtree with computed styles, serializes it through SVG `foreignObject`, then paints that SVG to a canvas and exports PNG.

The capture may be unavailable when the browser cannot serialize/rasterize the subtree safely, for example because of cross-origin assets, unsupported browser rendering behavior, very large target geometry or a missing preview node. The ZIP still exports in that case and the manifest records `raster.status = "unavailable"`.

The structured `visualSnapshot` and `layoutGraph` remain authoritative context when no raster is available.

## ZIP implementation

The bundle writer uses a minimal local uncompressed ZIP implementation with CRC32 and does not load a third-party archive library. This keeps the editor workflow self-contained and avoids adding a remote dependency.

## External AI guidance

The external model should read files in numeric order, use only runtime-proven widgets/controls, preserve protected/global/dynamic bindings, and return only the requested semantic delta. Final Elementor IDs for new nodes are owned by Cresco.
