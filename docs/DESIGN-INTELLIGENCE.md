# Design Intelligence

Cresco Layer 0.20 adds a deterministic design-guidance layer to the external-AI context. It does not replace Elementor Site Settings and it does not create a second token system. Active Elementor Kit values and the live Elementor runtime remain authoritative.

## UI/UX Pro Max inspiration

The design-quality model is informed by the public MIT-licensed project [`nextlevelbuilder/ui-ux-pro-max-skill`](https://github.com/nextlevelbuilder/ui-ux-pro-max-skill), reviewed at revision `a38d04c3d5c298c851dbe5e6ee1965ee3de42cb5` during the 0.20 implementation.

Cresco does **not** depend on that repository at runtime and does not import its large local datasets. Instead it adopts compatible high-level principles that are useful for Elementor compilation:

- accessibility before decoration;
- touch/interaction safety;
- performance and layout stability;
- visual consistency;
- responsive/fluid layout;
- typography and color legibility;
- purposeful motion with reduced-motion support;
- form feedback and behavioral preservation;
- predictable navigation;
- explicit design dials for variance, motion and density.

The reference project is MIT licensed. Cresco records the source repository, revision and license inside `designIntelligence.source` for provenance.

## Design dials

The AI panel exposes three optional dials. `Auto` lets Cresco infer a conservative starting point from the task.

- **Variance** — minimal/structured through balanced to bold/asymmetric.
- **Motion** — subtle through standard to expressive.
- **Density** — spacious through standard to dense.

These dials do not directly write CSS or Elementor settings. They are design constraints provided to the external model together with the active Kit and runtime.

## Context contract

The export adds `cresco-design-intelligence/v1` with:

- product archetype;
- style keywords;
- design dial values and tiers;
- semantic spacing intent scale;
- ordered quality priorities;
- Active Kit availability summary;
- design anti-patterns;
- core design principles.

The external model is asked to reuse the existing Elementor design language before inventing local values. Global Colors, Global Fonts, Dynamic Tags and behavioral bindings remain preserve-by-default.

## Semantic design intent

`cresco-semantic-design-intent/v1` defines the vocabulary accepted by `cresco-ai-mutation/v3`. Common properties include layout direction/alignment/gap/width/padding, typography and colors, responsive variants and accessibility intent.

The vocabulary intentionally stays smaller than Elementor's control surface. If a requested property cannot be represented safely by semantic intent, the model may use explicit runtime-proven `settings`; those settings still pass through the normal semantic guard.

## Quality hierarchy

Design guidance is advisory, but safety and accessibility rules are stronger than style preferences. In particular, Cresco should not trade readable contrast, keyboard focus, touch usability, reduced-motion behavior or preserved form/query/navigation behavior for a decorative effect.

The design intelligence layer is meant to make external AI output more coherent and professionally constrained while keeping Elementor itself as the editable source of truth.
