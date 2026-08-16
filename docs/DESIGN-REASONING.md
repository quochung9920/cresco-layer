# Professional Design Reasoning

Cresco Layer 0.21 adds `cresco-design-reasoning/v1`, a deterministic design-brief layer for external AI. It sits above Exact Runtime and Widget Intelligence and below the final AI mutation contract.

The goal is not to make Cresco another design-system product. Elementor Active Kit remains the editable source of truth. Design Reasoning helps an external model decide **what hierarchy and composition are appropriate**, while Cresco continues to decide **which Elementor widgets and controls are actually legal**.

## UI/UX Pro Max reference

The reasoning workflow is informed by the public MIT-licensed project `nextlevelbuilder/ui-ux-pro-max-skill`, reviewed at revision `a38d04c3d5c298c851dbe5e6ee1965ee3de42cb5`.

Cresco adopts compatible concepts such as:

- analyze product/page intent before picking a visual style;
- accessibility and interaction safety before decoration;
- explicit variance, motion and density controls;
- product/page-specific design reasoning;
- quality checks for responsive layout, typography, forms, navigation and performance;
- a pre-delivery checklist rather than style-only generation.

Cresco does **not** vendor the reference project's searchable datasets, Python search tool or generated design systems. There is no runtime dependency. The source repository, revision, license and integration mode are exported for provenance.

## Export contract

`designReasoning` contains:

- `productArchetype` — SaaS, dashboard, commerce, service, editorial, portfolio or general web;
- `pageArchetype` — landing, dashboard, pricing, checkout, article, portfolio, lead generation or content section;
- `audienceSignals` — conservative task-derived usage signals;
- `objective` — the design outcome Cresco expects the region to serve;
- `visualHierarchy` — ordered attention priorities;
- `compositionStrategy` — recommended composition patterns;
- `pageStrategy` — action, proof and rhythm guidance for the page type;
- `designVocabulary` — semantic emphasis, surface, depth and spacing roles;
- `qualityGates` — critical, high and advisory checks;
- `referenceTranslation` — how to adapt a supplied reference image through the current site and Elementor runtime;
- `antiPatterns` — product/task-specific failure modes;
- `decisionOrder` — the order to resolve conflicts between usability, hierarchy, runtime fit and decorative polish;
- `elementorTranslation` — final hand-off rules into semantic mutation v3.

## Decision order

The default professional priority is:

```text
accessibility + behavior safety
  -> user task + hierarchy
  -> responsive layout
  -> Active Kit consistency
  -> widget/runtime fit
  -> typography + color
  -> depth + motion
  -> decorative polish
```

This prevents a visually attractive reference from overriding accessibility, form behavior, responsive correctness or Elementor-native semantics.

## Reference images

When a reference image is supplied, Cresco asks the external model to extract:

- visual hierarchy;
- section composition;
- relative proportions;
- spacing rhythm;
- typography character;
- color relationships;
- surface depth;
- component patterns;
- imagery treatment;
- visible interaction/motion cues.

The external model should then translate those qualities through Widget Intelligence, Active Kit and Exact Runtime. It should not blindly copy raw pixel values, framework markup, brand identity or unsupported interaction behavior.

## Quality gates

Critical gates cover readable contrast, visible focus, touch usability and preservation of behavioral/external configuration. High-priority gates cover responsive overflow, hierarchy, Active Kit consistency and layout stability. Advisory gates cover purposeful motion, richer product proof and section rhythm.

These gates are design guidance for the external model. Runtime legality and mutation safety are still enforced by Cresco's compiler, Mutation Normalizer, SemanticPatchGuard and post-apply verification.

## AI Bundle

AI Bundle v3 adds `06-design-reasoning.json` as a standalone file. `01-TASK.md` points the model to this brief before it returns a mutation.

The intended sequence is:

```text
Task + reference
  -> Design Reasoning
  -> Widget Intelligence / Structure Grammar
  -> Exact Runtime / Active Kit
  -> cresco-ai-mutation/v3
  -> SemanticDesignCompiler
  -> validation / preview / apply
  -> rendered visual verification
```

External AI should return only the final semantic mutation. It should not return design analysis prose or echo the reasoning context.
