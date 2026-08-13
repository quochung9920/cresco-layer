# Editor AI workflow

Cresco Layer 0.5.1 keeps the scoped `cresco-layer-ai-package/v2` → `cresco-layer-patch/v1` exchange, but makes the Elementor editor workflow usable without handling JSON manually in the normal case.

## Edit with AI

The editor toolbar now exposes one primary **Edit with AI** action. The user chooses the smallest editable scope that matches the task:

- **This element only** → `widget` scope. Edit only the selected widget/container settings. Existing children are preserved.
- **This section + children** → `subtree` scope. Edit the selected container and descendants.
- **Selected elements** → `selection` scope. Edit only the explicitly collected elements; their descendants are not automatically editable.

Use the smallest scope possible. A completed page does not need to be exported as a full document just to restyle one button or redesign one content block.

## AI selection

A non-contiguous AI selection is kept in the editor bridge. Elements can be added or removed from the selection from the Elementor context menu with **Cresco · Add/remove AI selection**. The floating selection counter shows the current number of selected elements.

Selection exports use the existing backend `selection` scope and send the comma-separated element IDs to the document export endpoint.

## Clear input/output filenames

Editor exports use an input-specific filename:

`cresco-ai-input-post<postId>-<target>-<scope>.json`

The file is the input package that should be sent to an AI. It is not the file imported back into Cresco.

## Import AI result

**Import AI** accepts the AI result as a local `.json` file through drag-and-drop or a file picker. Manual paste remains available as a fallback.

Before validation the editor identifies common mistakes locally:

- `cresco-layer-patch/v1` → accepted as an AI result;
- `cresco-layer-ai-package/v2` → rejected as an AI input package;
- Elementor clipboard/export data with `type: elementor` → rejected with a targeted explanation;
- invalid or unknown JSON → rejected before a REST request is sent.

For widget/subtree patches, Cresco compares the patch root against the element currently selected in Elementor. A mismatch is shown persistently and validation stays disabled until the correct target is selected.

## Validation and preview

After local file/schema/target checks, **Validate & Preview** still uses the existing server-side patch validator, semantic guard, scope checksum validation and PatchApplier preview. The preview summarizes operation counts, scope, target, native-control operations, structural operations and semantic warnings.

Validation failures are kept in the modal instead of existing only as a transient toast. The user can copy diagnostics for support or AI-assisted repair.

## Apply

**Apply reviewed patch** continues to save through Elementor's document APIs and does not publish the page. Cresco then syncs supported operations into the open Elementor canvas and reports when a reopen is required.
