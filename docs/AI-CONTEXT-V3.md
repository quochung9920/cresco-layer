# Cresco Layer AI Context v3

Cresco Layer 0.16.0 introduces an AI-first exchange layer designed for one simple user flow:

**Select Elementor element → describe the change → Prepare for AI → send JSON + optional reference image → Import Result → Preview → Apply.**

The user no longer needs to choose Smart/Exact Runtime in the main workflow, understand patch mechanics, manually manage scope, or inspect Elementor runtime catalogs.

## Product principles

1. Elementor remains the source of truth.
2. Exact Runtime is automatic in the main AI workflow.
3. Existing Elementor content is read-only source context.
4. AI mutations are delta-first by default.
5. Native Elementor controls are preferred over Custom CSS.
6. Parent Container gap/row-gap/column-gap owns sibling spacing whenever possible.
7. Full replacement is an explicit rebuild action, not a convenience operation.
8. Preview and server-side validation remain mandatory before Apply.

## Unified Cresco AI panel

The main editor UX is now a single floating **Cresco AI** entry point. The legacy multi-button toolbar is hidden from the normal workflow.

The panel has two tabs:

- **Create / Edit** — describe the task, choose Auto/Edit/Add/Rebuild, optionally register a reference image, then prepare/copy/download AI context.
- **Import Result** — paste or drop the AI response, preview its changes, then apply to the current Elementor working document.

### Change types

- **Auto**: Cresco tells AI to choose the smallest safe delta.
- **Edit**: AI should return native setting updates/removals/moves for existing IDs.
- **Add**: AI should return only new inserted Elementor subtree data.
- **Rebuild**: the only destructive mode; a full target replacement is allowed only when explicitly selected.

## `cresco-ai-context/v3`

The editor takes the server's scoped package, lets Exact Runtime enrich it with live capabilities, then compiles a new AI-facing package:

```json
{
  "schema": "cresco-ai-context/v3",
  "aiBrief": "# Cresco AI Task ...",
  "task": {},
  "target": {},
  "currentInterface": {},
  "visualSnapshot": {},
  "layoutGraph": {},
  "designSystem": {},
  "responsive": {},
  "runtime": {},
  "rules": {},
  "outputContract": {},
  "contextQuality": {},
  "sourceContext": {},
  "diagnostics": {}
}
```

The important difference from the older export is information order. AI sees the task and constraints first, then the visual/layout model, then exact control capabilities, then lower-level source/debug context.

## AI Brief

Every v3 package starts with a short plain-language briefing containing:

- user goal;
- selected target;
- preservation/rebuild policy;
- source element count;
- context quality;
- native-control-first and gap-first design rules;
- the required output contract.

This lets a model understand the task before reading runtime metadata.

## Visual Snapshot

`visualSnapshot` is a structured snapshot from the live Elementor preview. It deliberately does not embed a bitmap or a base64 image inside JSON.

It contains:

- live viewport width/height/device-pixel-ratio;
- selected target bounds;
- selected target computed CSS values;
- count of visible Elementor nodes;
- optional reference-image metadata.

If the task uses a visual reference, the user attaches that same image to the AI chat separately. This keeps the JSON small and avoids huge binary payloads.

## Layout Graph

`layoutGraph` combines the persisted Elementor tree with live preview geometry.

Each node records:

- `id`;
- parent ID;
- sibling index;
- nesting depth;
- Elementor `elType` / `widgetType`;
- inferred Cresco container role where available;
- child count;
- important layout/typography settings;
- rendered bounds;
- computed display/flex/grid/gap/padding/typography/background/border properties.

This gives AI both the semantic Elementor structure and the visual result of that structure.

## Runtime compaction

Exact Runtime remains authoritative. `runtime` is a compact AI-friendly representation of the live runtime capability data rather than a second guessed schema.

For each loaded widget/element it keeps:

- exact control key;
- type;
- responsive flag;
- default;
- units;
- ranges;
- options;
- conditions;
- selectors;
- Atomic/binding metadata when present;
- detailed capability loaded status.

AI must never invent a key not present here.

## Context Quality

Every v3 package has `contextQuality` with a score and explicit checks.

Current scoring verifies:

- Exact Runtime availability;
- Active Elementor Kit / design system availability;
- layout graph presence;
- live target visual metrics;
- source tree presence;
- exchange safety policy presence.

Grades:

- 95–100: Excellent
- 80–94: Good
- 65–79: Usable
- below 65: Incomplete

The panel shows this before the user sends the package to AI.

## Output contract

The v3 package does not ask AI to guess mutation strategy.

For normal Add/Edit/Auto work the preferred result is still `cresco-layer-patch/v1`, but the package provides explicit small templates:

- `insert-element` for additions;
- `update-setting` for existing control edits;
- `move-element` when relocating existing elements;
- no echoing of the existing source subtree.

For explicit Rebuild mode the package provides a `cresco-layer-ai-result/v1` template with `intent: "replace-target"`.

The existing safe-exchange guard continues to reject `[TRUNCATED]`, `[REDACTED]` and `__cresco_truncated__` before Preview/Apply.

## Import UX

The new panel sends the AI's raw response to the server normalizer. This is important: the browser no longer requires the user to hand-edit common AI wrappers or Markdown code fences.

The server remains authoritative for:

- schema recognition;
- target/scope validation;
- semantic/runtime validation;
- placeholder blocking;
- internal patch compilation;
- preview diff;
- persistence;
- verification;
- rollback/history.

The panel presents user-facing counts instead of patch jargon:

- added;
- updated;
- moved;
- replaced;
- removed;
- warnings;
- risk message.

After Apply, Elementor preview is refreshed so persisted working data is shown again.

## Reference images

The panel accepts an optional reference-image selection only to record metadata in the package. The image itself is not embedded.

The intended workflow is:

1. choose the reference image in Cresco;
2. Prepare for AI;
3. copy/download the JSON;
4. send JSON and attach the same image in the AI conversation.

This keeps the package portable, readable and token-efficient.

## Compatibility

The REST backend and safe exchange model from 0.15.1 remain intact. AI Context v3 is compiled in the Elementor editor after Exact Runtime enrichment, so server-side tooling that still consumes the scoped v2 package is not broken.

Legacy `cresco-layer-patch/v1` results continue to import through the server normalizer.

## Main files

- `assets/ai-context-v3.js` — AI-first context compiler, layout graph, live visual metrics, capability compaction and quality score.
- `assets/ai-panel.js` — unified Prepare/Import workflow.
- `assets/ai-panel.css` — user-facing panel styling.
- `assets/exact-runtime-export.js` — authoritative live runtime enrichment.
- `includes/AI/ExchangeSafetyGuard.php` — read-only source / delta mutation policy and placeholder blocking.
- `includes/Support/Assets.php` — deterministic editor script/style loading order.

## Design intent

The user should think in terms of **what they want to build**. Cresco handles Elementor internals.

The AI should think in terms of **visual intent + exact available controls + the smallest required mutation**. It should not reconstruct existing source data, invent Elementor settings, or use Custom CSS when a native control exists.
