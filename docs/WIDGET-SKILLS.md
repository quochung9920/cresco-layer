# Cresco Runtime Widget Skills

Cresco Layer 0.6 adds a deterministic skill runtime for editing the currently selected Elementor widget/container without putting a chatbot or LLM behind the editor.

## Product contract

```text
Selected Elementor element
  -> active Elementor runtime metadata
  -> Cresco Skill Compiler
  -> element-specific skill registry
  -> deterministic command or explicit skill parameters
  -> native Elementor setting operation
  -> Elementor live settings API + history
```

The runtime registry is the source of truth. A skill is executable only when the active Elementor installation exposes the corresponding control/Atomic prop. Cresco never invents a setting name merely because an Elementor version or addon is expected to support it.

## Coverage model

The skill runtime learns from every widget/element registered in the active Elementor managers, including where available:

- Elementor Free widgets and containers;
- Elementor Pro widgets/modules;
- Atomic/V4 elements and props;
- WordPress widgets exposed through Elementor;
- Cresco widgets;
- third-party addon widgets that register through Elementor's runtime control APIs.

Classic controls are compiled from `get_controls()`. Atomic/V4 editability is compiled from normalized `get_atomic_controls()` + `get_props_schema()` bindings. Persisted settings are read from the selected document instance, not from registry prototypes.

## What one compiled skill knows

Each runtime control is represented as a skill record containing the metadata Cresco can prove from Elementor, such as:

- exact control/prop binding;
- type, label and description;
- source (`classic-control`, `atomic-control`, Atomic prop schema);
- current desktop and responsive values;
- default value;
- active responsive devices;
- allowed units;
- select/choose options;
- min/max/range/step;
- Dynamic Tag capability;
- frontend/render metadata;
- selectors and selector dictionaries;
- control conditions/dependencies;
- group-control prefix/type;
- Atomic prop schema when applicable;
- semantic role/category inferred from the real setting key;
- risk and execution mode.

## Expert knowledge layer

`ExpertProfiles` augments the runtime facts with domain guidance. It does not create controls. Current expert families include:

- containers/flex/grid/layout;
- headings and text;
- buttons/actions;
- images/media and video;
- forms, fields and actions-after-submit;
- navigation/menu/dropdown behavior;
- posts/query/loop/taxonomy/product widgets;
- slides/carousels;
- tabs/accordion/nested disclosures;
- counter/progress/countdown/ratings;
- social widgets;
- WooCommerce/commerce widgets;
- HTML/code/shortcode widgets;
- Atomic/V4 elements.

This split is intentional: expert knowledge explains semantics, while runtime metadata proves what the installed widget can actually do.

## Skill modes and risk

Every compiled control is classified independently.

- `direct`: scalar/native controls such as color, dimensions, slider, select and switcher.
- `expert`: structured or potentially sensitive configuration such as repeater/gallery/code data. The palette exposes the exact runtime binding and requires an explicit structured value.
- `read-only`: Elementor UI controls such as headings/sections that do not carry an editable value.

Risk labels are separate from execution mode:

- `safe`: ordinary presentation/content settings.
- `conditional`: a control depends on another Elementor setting.
- `structural`: repeater/gallery/structure data.
- `expert`: code/HTML/custom CSS-style controls.
- `external`: email, redirect, webhook, payment or credential-adjacent controls.

Secret-like setting keys are blocked from generic skill execution.

## Conditions and prerequisites

A control can be present but inactive. The compiler preserves Elementor conditions and can enable a narrow set of safe, provable prerequisites when both controls exist in the selected widget's runtime registry.

Example: setting a native `background_color` may require the widget's native `background_background` control to be `classic`. Cresco may emit:

```json
[
  {
    "operation": "update-setting",
    "setting": "background_background",
    "value": "classic"
  },
  {
    "operation": "update-setting",
    "setting": "background_color",
    "value": "#07133F"
  }
]
```

Cresco does not synthesize a prerequisite if the active widget does not expose that setting.

## Responsive skills

Responsive variants come from the active Elementor breakpoints and each control's runtime responsive flag. For example, a responsive `padding` control resolves to native settings such as:

```text
padding
padding_tablet
padding_mobile
```

Only active/proven devices are offered. A non-responsive control cannot be forced into a responsive suffix by a command.

## Deterministic command bar

The command bar is a convenience parser, not a chatbot. It maps a bounded vocabulary to semantic roles that must already exist in the selected widget's compiled registry.

Examples:

```text
padding 24px
mobile padding 20px
width 50%
min height 480px
gap 24px
background #07133F
radius 16px
font size 36px
mobile font size 28px
text color #ffffff
hide mobile
show mobile
```

If the parser cannot map a command to a proven skill, it fails instead of guessing.

## Elementor editor integration

`assets/skills.js` adds a **Cresco Skills** launcher and side palette. It:

1. tracks the currently selected Elementor element;
2. loads that element's compiled skill profile;
3. shows only skills derived from its actual controls/props;
4. accepts explicit parameters or deterministic commands;
5. sends live unsaved settings to the resolver so conditions/previews use current editor state;
6. rejects any returned operation that targets another element;
7. applies only native live setting operations through `document/elements/settings`;
8. wraps changes in Elementor history so Undo/Redo remains available.

Structural insertion/removal/move operations remain outside this widget-only skill palette and continue to use Cresco's reviewed scoped patch workflow.

## REST API

Get the skills for one selected element:

```text
GET /wp-json/cresco-layer/v1/documents/{postId}/skills/{elementId}
```

Resolve an explicit skill:

```json
POST /wp-json/cresco-layer/v1/documents/{postId}/skills/{elementId}/resolve
{
  "skillId": "control.padding",
  "params": {
    "device": "mobile",
    "top": "20",
    "right": "16",
    "bottom": "20",
    "left": "16",
    "unit": "px"
  },
  "liveSettings": {}
}
```

Resolve a deterministic command:

```json
{
  "command": "mobile padding 20px",
  "liveSettings": {}
}
```

The resolver returns `cresco-layer-skill-resolution/v1` with native operations and a before/after preview. It does not call an AI provider.

## Invariants

- No chatbot/LLM is required or embedded by the widget skill runtime.
- Never invent Elementor setting keys.
- Never add a responsive suffix to a non-responsive control.
- Validate options, units and ranges from runtime metadata.
- Preserve Dynamic Tag/global/Atomic context unless explicitly changed.
- Keep one skill execution inside the currently selected element.
- Use Elementor live settings/history in the editor.
- Keep Elementor responsible for final rendering, document persistence and Update/Publish.
