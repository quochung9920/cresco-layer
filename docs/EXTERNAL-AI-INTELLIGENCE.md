# Cresco Layer External AI Intelligence

Cresco Layer 0.18 introduced a semantic intelligence layer between the existing Exact Runtime export and external AI systems. Cresco Layer 0.19 extends that foundation with task-aware runtime discovery, runtime-derived semantic bindings and a raster-aware AI Bundle exporter. Elementor remains the source of truth. Cresco does not become a page builder and does not maintain a parallel design system.

## Goal

An external model should not have to infer every design decision from a raw Elementor tree. The exported `cresco-ai-context/v3` explains both **what exists** and **how the active Elementor installation can safely construct or edit it**.

The intended flow is:

```text
Elementor working document
  -> Exact Runtime + Active Kit + layout/visual context
  -> task-aware runtime widget discovery
  -> Cresco AI Context v3
  -> External AI Intelligence enrichment
  -> external AI returns cresco-ai-mutation/v2 (preferred)
  -> runtime semantic binding + semantic mutation compiler
  -> Cresco ID allocation + deterministic normalization
  -> internal cresco-layer-patch/v1
  -> SemanticPatchGuard
  -> preview / apply / verify
```

## New exported intelligence

The top-level context remains `cresco-ai-context/v3` for compatibility.

### `taskRuntimeDiscovery`

Schema: `cresco-task-runtime-discovery/v1`

Exact Runtime uses the current task plus Elementor's live registry title/category/keyword metadata to load additional relevant widget detail. Only registered runtime types are eligible. This closes the gap where an FAQ/Carousel/Menu widget may be available in Elementor but absent from the selected subtree and old fixed construction set.

### `widgetIntelligence`

Schema: `cresco-widget-intelligence/v1`

For runtime-proven widget/element types it provides semantic family, purpose, preferred roles, important controls, risk and detail availability. The role matrix only recommends types that exist in the active runtime; a Pro or third-party widget is never assumed merely because Cresco knows its name.

### `semanticScene`

Schema: `cresco-semantic-scene/v1`

Adds deterministic role hints to the selected subtree: headline, body copy, CTA, form, proof list, media, layout group and other common regions. Every inference carries a confidence value. Low-confidence roles are guidance rather than authority.

### `constructionPlan`

Schema: `cresco-construction-plan/v1`

For common requests such as lead-generation heroes, hero sections, trust tickers and FAQ sections, Cresco emits a role-oriented construction recipe. Recommended steps are filtered through the active runtime. Unsupported recipe parts are reported instead of invented.

### `placementContext`

Schema: `cresco-placement-context/v1`

Describes the selected target, parent, index, previous/next siblings, whether the target can own children and which placements are legal in the exported scope. A sibling placement outside the selected subtree is explicitly marked `requiresWiderScope` so the AI does not silently insert into the wrong parent.

### `mutationBoundary`

Schema: `cresco-mutation-boundary/v1`

Separates editable IDs from read-only context and marks behavior/external bindings that visual requests should preserve. Form actions, webhooks, query/template bindings, navigation sources, transactional commerce settings and code-like controls are treated as higher-risk boundaries.

### `controlExamples`

Schema: `cresco-control-examples/v1`

Provides value-shape examples derived from the active runtime. Examples are hints; actual runtime metadata (`options`, `size_units`, ranges, conditions and emittable responsive keys) remains authoritative.

### `runtimeSelection`

Schema: `cresco-task-runtime-selection/v1`

Identifies widget types relevant to the current interface and construction plan so an external model can focus on a smaller conceptual set. The full exact runtime is retained for validation and uncommon requirements.

## Runtime semantic binding in 0.19

The backend semantic compiler no longer assumes that all heading-like widgets use Elementor core keys such as `title` and `header_size`, or that every button uses `text` and `link`. For `content` shortcuts, Cresco first validates `widgetIntent` against the live capability catalog and then emits only candidate semantic control keys that actually exist on that runtime entry.

This means a third-party heading exposing `headline`/`html_tag`, for example, can receive semantic text/heading-level content without pretending to be Elementor core. Exact explicit `settings` remain available and still pass through runtime validation and `SemanticPatchGuard`.

Arbitrary child nodes under semantic widgets are rejected. Structural children belong under runtime-proven structural elements/Containers.

## Context Quality v3

Schema: `cresco-context-quality/v3`

Quality is multi-dimensional rather than merely checking whether fields exist. The score covers runtime coverage, widget intelligence, semantic scene, visual confidence, Active Kit, responsive context, placement context, binding protection and output contract readiness.

An untrusted visual snapshot lowers the score and produces an explicit warning.

## Widget choice policy

External AI should follow this order:

1. Determine the semantic role from the task and `semanticScene`.
2. Choose the preferred runtime-proven type in `widgetIntelligence` / `constructionPlan`.
3. Use exact controls from `runtime` for that type.
4. Reuse Active Kit/global references where possible.
5. Use native Elementor controls before `custom_css`.
6. Preserve protected behavioral/dynamic/global bindings unless explicitly requested.

The server independently validates `widgetIntent` for new/rebuilt semantic nodes against the active Elementor capability catalog. An invented widget type is rejected before it can become Elementor data.

## ID ownership

Final Elementor IDs for new nodes belong to Cresco, not the external model.

New semantic nodes may either omit an ID or use a unique temporary reference:

```json
{
  "ref": "$new:primary-cta",
  "widgetIntent": "button"
}
```

Cresco allocates a collision-free 7-character Elementor ID using the current working document, rewrites the subtree and reports the mapping. Existing target IDs are never remapped.

## Deterministic mutation normalization

Before `SemanticPatchGuard`, Cresco may repair a mutation only when semantic equivalence is provable from runtime metadata.

Current supported repair:

- a `px` slider value outside the control's declared px range may be converted to the **same native control** using Elementor's `custom` unit when that unit is explicitly supported, preserving the exact CSS length (for example `300px` -> custom `"300px"`).

Cresco intentionally does **not** silently clamp values such as an icon size of `5px` when the runtime minimum is `6px`, and does not convert relative typography units to pixels unless equivalence can be proven. Ambiguous values continue to fail in `SemanticPatchGuard` with an actionable diagnostic.

## Visual snapshot and AI Bundle boundary

Structured visual context remains live computed geometry. Version 0.19 additionally adds a best-effort browser raster capture to `cresco-ai-bundle/v1`.

The bundle contains task/context/widget-guide/output-contract files, optional `current-desktop.png`, the selected reference image when provided, and a manifest. Raster capture is not guaranteed: if browser/cross-origin/rendering constraints prevent a trustworthy PNG, the bundle remains valid and explicitly reports the raster as unavailable rather than fabricating an image.

See `docs/AI-BUNDLE.md`.

## Placement boundary

A selected subtree cannot authorize writes to its parent. Therefore `before-target` / `after-target` may be visible in context but marked `requiresWiderScope`. Select/export the parent Container when a sibling insertion is required.

The preferred semantic mutation contract currently compiles Add operations inside the selected Container (`inside-start` / `inside-end`). More general anchor-relative sibling insertion can be added later without weakening scope safety.

## Backward compatibility

0.19 keeps accepting:

- `cresco-layer-patch/v1`
- `cresco-layer-ai-result/v1`

`cresco-ai-mutation/v2` is preferred for external AI because it lets Cresco own IDs, widget/runtime validation and internal patch mechanics.

Checksum freshness is not reintroduced. Existing placeholder rejection, delta-first policy, explicit destructive rebuild intent, semantic validation and Elementor Document API persistence remain in force.
