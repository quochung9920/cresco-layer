# Cresco Layer 0.19 Semantic Runtime Compiler

Cresco Layer 0.19 focuses on the three accuracy gaps that matter most when an external AI is asked to reproduce a design inside Elementor: finding the right installed widget for the task, mapping semantic content through the live widget controls instead of core-only assumptions, and packaging a usable visual/context bundle.

## Task-aware runtime discovery

Exact Runtime now considers the current AI request when selecting construction capabilities. It still starts from the current document, read-only context and the proven construction set, then adds only widget types that are present in Elementor's live registry and match either deterministic task hints or registry title/category/keyword metadata.

The export records this in `taskRuntimeDiscovery` with schema `cresco-task-runtime-discovery/v1`.

No missing or unregistered widget is invented. A request such as "create an FAQ accordion" can therefore load the registered Accordion/Nested Accordion/Toggle detail even when that widget is not already present in the selected subtree.

## Runtime semantic bindings

`cresco-ai-mutation/v2` content shortcuts no longer blindly assume that every heading uses `title`/`header_size` or every button uses `text`/`link`. The compiler first validates `widgetIntent` against the active Elementor catalog, then emits a semantic shortcut only through a candidate control key that actually exists for that runtime widget.

Examples:

- a core Heading may resolve `content.text` to `title`;
- a third-party heading that exposes `headline` may resolve the same semantic content to `headline`;
- semantic heading level is emitted only when a compatible runtime control such as `header_size`, `html_tag` or `tag` exists and accepts the requested level;
- button label and URL similarly bind only to controls actually present in the runtime entry.

Explicit `settings` still take precedence and remain subject to `SemanticPatchGuard`.

Widgets are not treated as arbitrary layout containers. A semantic widget with nested child nodes is rejected; structural children must be placed under a runtime-proven structural element such as a Container.

## External AI bundle

The editor adds an `Export AI Bundle` action after context preparation. It produces `cresco-ai-bundle-<target>.zip` containing:

- `01-TASK.md`
- `02-context.json`
- `03-widget-guide.json`
- `04-output-contract.json`
- `manifest.json`
- `current-desktop.png` when a same-origin browser raster capture succeeds
- the selected reference image when provided

Raster capture is best-effort. It is generated from the selected target inside Elementor's preview iframe by cloning the rendered subtree with computed styles into an SVG `foreignObject`, then rasterizing that SVG to PNG. Browsers can refuse or taint rasterization when the target depends on cross-origin assets or unsupported rendering features. In that case the bundle remains valid without a raster file and the manifest marks the raster as unavailable; Cresco does not fabricate an image.

The ZIP writer is local and uncompressed, so no external JavaScript archive dependency is required.

## Safety remains unchanged

0.19 does not weaken the existing boundaries:

- Elementor runtime remains authoritative.
- Active Kit remains the design-system source of truth.
- External AI does not own final Elementor IDs.
- Semantic mutation remains delta-first.
- Protected form/query/navigation/commerce/code behavior is preserved unless explicitly requested.
- `MutationNormalizer` performs only deterministic semantics-preserving repair.
- `SemanticPatchGuard` remains the final runtime/semantic authority before apply.
- Existing `cresco-layer-patch/v1` and `cresco-layer-ai-result/v1` imports remain supported.
