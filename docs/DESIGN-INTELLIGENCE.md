# Design Intelligence

Cresco Layer 0.20 introduced a deterministic design-guidance layer to the external-AI context. Cresco Layer 0.21 adds a second `cresco-design-reasoning/v1` layer that converts those general principles into product/page-specific objectives, visual hierarchy, composition strategy, reference-image translation and quality gates.

Neither layer replaces Elementor Site Settings or creates a second token system. Active Elementor Kit values and the live Elementor runtime remain authoritative.

## UI/UX Pro Max inspiration

The design-quality model is informed by the public MIT-licensed project [`nextlevelbuilder/ui-ux-pro-max-skill`](https://github.com/nextlevelbuilder/ui-ux-pro-max-skill), reviewed at revision `a38d04c3d5c298c851dbe5e6ee1965ee3de42cb5`.

Cresco does **not** depend on that repository at runtime and does not import its large searchable datasets, Python search tooling or generated design-system files. Instead it adopts compatible high-level ideas that are useful for Elementor compilation:

- analyze product and page intent before picking style;
- accessibility before decoration;
- touch/interaction safety;
- performance and layout stability;
- visual consistency;
- responsive/fluid layout;
- typography and color legibility;
- purposeful motion with reduced-motion support;
- form feedback and behavioral preservation;
- predictable navigation;
- explicit design dials for variance, motion and density;
- a pre-delivery quality checklist rather than style-only generation.

The reference project is MIT licensed. Cresco records source repository, revision, license and integration mode inside exported design metadata for provenance.

## Design dials

The AI panel exposes three optional dials. `Auto` lets Cresco infer a conservative starting point from the task.

- **Variance** — minimal/structured through balanced to bold/asymmetric.
- **Motion** — subtle through standard to expressive.
- **Density** — spacious through standard to dense.

These dials do not directly write CSS or Elementor settings. They are design constraints provided to the external model together with the active Kit and runtime.

## Context contracts

`cresco-design-intelligence/v1` contains:

- product archetype;
- style keywords;
- design dial values and tiers;
- semantic spacing intent scale;
- ordered quality priorities;
- Active Kit availability summary;
- design anti-patterns;
- core design principles.

`cresco-design-reasoning/v1` then adds:

- page archetype;
- audience signals;
- product/page objective;
- ordered visual hierarchy;
- composition and proof strategy;
- semantic emphasis/surface/depth vocabulary;
- machine-readable critical/high/advisory quality gates;
- reference-image adaptation rules;
- a professional conflict-resolution order.

The external model is asked to reuse the existing Elementor design language before inventing local values. Global Colors, Global Fonts, Dynamic Tags and behavioral bindings remain preserve-by-default.

## Semantic design intent

`cresco-semantic-design-intent/v1` defines the vocabulary accepted by `cresco-ai-mutation/v3`. Common properties include layout direction/alignment/gap/width/padding, typography and colors, responsive variants and accessibility intent.

The vocabulary intentionally stays smaller than Elementor's control surface. If a requested property cannot be represented safely by semantic intent, the model may use explicit runtime-proven `settings`; those settings still pass through the normal semantic guard.

The reasoning layer chooses *what the design should prioritize*. `SemanticDesignCompiler` still decides whether the requested layout/style values can be expressed through exact controls in the active Elementor installation.

## Quality hierarchy

The default decision order is:

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

Cresco should never trade readable contrast, keyboard focus, touch usability, reduced-motion behavior or preserved form/query/navigation behavior for closer decorative similarity to a reference image.

The combined Design Intelligence + Design Reasoning layers are meant to make external AI output more coherent, product-aware and professionally constrained while keeping Elementor itself as the editable source of truth.
