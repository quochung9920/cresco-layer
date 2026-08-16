# Cresco Layer

Cresco Layer is an Elementor intelligence and safe AI exchange plugin. Elementor remains the source of truth for document structure, controls, Active Kit design settings, rendering, history and persistence; Cresco adds deterministic runtime discovery, scoped context export, semantic AI compilation, validation and editor workflow tooling.

## Current release

**0.19.0 — Semantic Runtime Compiler**

The 0.19 release extends the 0.18 External AI Intelligence foundation with three accuracy-focused capabilities:

- **Task-aware runtime widget discovery** — Exact Runtime can load additional registered widget detail when the current request and Elementor registry metadata indicate the widget is relevant, such as Accordion for an FAQ task, without inventing unavailable widgets.
- **Runtime-derived semantic content bindings** — `cresco-ai-mutation/v2` semantic shortcuts bind only to control keys that actually exist on the active runtime widget. Third-party heading/button variants no longer have to mimic Elementor core key names.
- **Raster-aware AI Bundle export foundation** — the editor can package task/context/widget guide/output contract plus a best-effort same-origin raster capture and optional reference image into a local ZIP without an external archive dependency.

The existing safety model remains unchanged: final new Elementor IDs are owned by Cresco, Active Kit remains the design-system source of truth, deterministic normalization runs before `SemanticPatchGuard`, and all applies continue through preview/validation/verification.

See:

- `docs/SEMANTIC-RUNTIME-COMPILER.md`
- `docs/EXTERNAL-AI-INTELLIGENCE.md`
- `docs/AI-MUTATION-V2.md`
- `docs/WIDGET-INTELLIGENCE.md`
- `docs/AI-CONTEXT-V3.md`
- `docs/SAFE-AI-EXCHANGE.md`

## External AI flow

```text
Elementor working document
  -> Exact Runtime + Active Kit + live layout/visual context
  -> task-aware runtime discovery
  -> Cresco AI Context v3
  -> Widget Intelligence + Semantic Scene + Construction Plan
  -> external AI returns cresco-ai-mutation/v2 (preferred)
  -> runtime semantic binding + widget validation
  -> Cresco ID allocation + deterministic normalization
  -> internal cresco-layer-patch/v1
  -> SemanticPatchGuard
  -> preview / apply / verify
```

## Key principles

- Never invent Elementor controls or responsive suffixes.
- Use only widget/element types proven by the active Elementor runtime.
- Prefer native Elementor controls and Active Kit global references over custom CSS/local duplicates.
- Preserve existing IDs, Dynamic Tags, global references and behavioral/external bindings unless the user explicitly requests those changes.
- Use the smallest safe delta; full replacement is explicit rebuild only.
- New semantic nodes may omit final IDs or use unique `$new:<name>` references; Cresco allocates final collision-free Elementor IDs.
- Ambiguous normalization fails closed rather than guessing.

## Development

```bash
npm run check
```

The check suite includes JavaScript syntax/contract tests plus PHP import/compiler/normalizer/ID/safety tests. GitHub Actions may be unavailable independently of code quality if the repository account cannot start hosted jobs; local/targeted test results and CI infrastructure status should be reported separately.
