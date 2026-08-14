# Cresco Layer

**Lossless AI exchange and professional intelligence for Elementor + Elementor Pro.**

Cresco Layer keeps Elementor as the source of truth and turns the active Elementor installation into an AI-readable design language. It can export a complete page, one widget, one container subtree or an explicit selection together with the registered controls/capabilities that AI needs to modify it safely and import the result back.

## Core principle

Elementor remains responsible for:

- editor UI and canvas;
- page document model;
- responsive behavior;
- rendering;
- history;
- persistence;
- final Update/Publish.

Cresco Layer adds an interchange and intelligence layer. It does **not** fork Elementor or create a second page builder.

## Requirements

- WordPress 6.6+
- PHP 8.1+
- Elementor
- Elementor Pro for Pro-only integrations

## Elementor-native AI workflow

Inside Elementor, select a widget or container. Cresco Layer adds editor tools and context-menu actions:

- **Cresco · Export element for AI** — export only the selected element configuration;
- **Cresco · Export subtree for AI** — export the selected root and every descendant;
- **Cresco · Import AI changes** — paste a reviewed scoped AI patch, validate it and apply it back to the selected target.

The exported package includes the raw Elementor object plus the complete runtime capability catalog needed to understand controls that are currently using defaults and therefore may not exist in persisted `settings` yet.

## Export scopes

### Widget only

Use when AI should improve only the selected widget/container configuration. Children are preserved automatically during complete element replacement.

### Subtree

Use for a hero, pricing block, form area, navigation cluster or any nested design group. AI may modify the root and descendants, add children and reorganize inside the exported subtree, but cannot escape into unrelated parts of the page.

### Selection

Backend support exists for multiple explicit element IDs. Each selected root is exported without implicitly making unrelated descendants editable.

### Entire document

Use for page-wide redesign or screenshot-to-Elementor generation. Document scope can also use the `replace-document` operation for intentional complete page replacement.

## AI package v2

Current package schema:

```text
cresco-layer-ai-package/v2
```

Important sections include:

```text
manifest
editableScope
document
elementContext
elementStates
siteContext
designSystem
widgetCatalog
elementCatalog
relevantCapabilities
dynamicTags
templates
assets
capabilities
audit
instructions
```

### Raw element data

Cresco exports Elementor's own raw object rather than converting it into a Cresco document model. This preserves settings, responsive values, Dynamic Tags, global references, Atomic/V4 data and addon-specific metadata.

### Complete capability metadata

For registered widgets/elements Cresco exports serializable metadata exposed by the Elementor control stack, including where available:

- control name/type/label/description;
- default values;
- options;
- responsive and Dynamic Tag support;
- size units;
- ranges/min/max/step;
- selectors and selector dictionaries;
- conditions;
- render type;
- frontend availability;
- group prefixes/types;
- device-specific defaults.

This lets AI know what the current Elementor installation can do even when a setting is omitted from the page because Elementor is using a default.

### Actual installation, not a hard-coded catalog

The scanner asks Elementor's runtime managers for registered widgets and element types. Therefore Elementor Pro and registered addon widgets can be described without Cresco maintaining a fixed manually copied widget list.

## Elementor Configuration & Widget Catalog

The **Elementor → Cresco Layer** admin screen includes a read-only runtime inspector. Click **Load Elementor catalog** to inspect the actual active installation instead of a hard-coded reference.

The catalog exposes:

- Elementor, Elementor Pro, WordPress and PHP versions;
- active Elementor breakpoints;
- active Kit/design-system settings;
- every registered element type;
- every registered widget, including Elementor Pro and registered addon widgets;
- widget/element class name, categories, keywords and panel visibility where available;
- every registered control with its type, label, description, default, options, responsive/dynamic support, units, ranges, conditions, selectors and related metadata;
- `rawMetadata`, containing every serializable field Elementor exposes for that control. Object/resource/callback values are intentionally omitted;
- complete default settings for each widget/element.

The inspector supports search across widget names, element names and control metadata, lazy expandable control details, and a **Download full JSON** action. Secret-like configuration keys are redacted from the read-only catalog response.

Runtime endpoint:

```text
GET /wp-json/cresco-layer/v1/elementor-catalog
```

## Scoped safety

Every scoped package includes both:

- a full working-document checksum;
- a target `scopeChecksum`.

If the footer changes after you export a hero, the hero patch may still be valid. If the hero itself changes, Cresco rejects the stale AI result.

Server-side scope enforcement prevents an AI patch exported for one widget/subtree from modifying unrelated page elements.

## Semantic Elementor safety

Cresco Layer 0.3 adds a semantic guard between JSON validation and Elementor persistence. A patch can now be syntactically valid but still be rejected if it does not make sense for the target Elementor controls.

The guard validates, where runtime metadata is available:

- native control existence;
- responsive suffix support;
- select/choose options;
- units and numeric ranges;
- no-op operations against current persisted values;
- lossless handling of global references and unknown persisted settings;
- custom CSS patterns that are likely visual no-ops.

For example, AI output that only declares variables such as `--padding-top` or `--min-height` without consuming them through `var(...)` is rejected instead of being reported as a successful visual change.

`custom_css` is treated as fallback. When an equivalent native Elementor control exists, Cresco reports that fact so the patch can be rewritten using native responsive settings such as `padding_tablet` or `min_height_mobile`.

## Post-apply verification

After Elementor accepts a reviewed patch save, Cresco reloads working data and verifies each requested operation. The apply API response distinguishes successful save from successful persistence of the reviewed values.

This closes the gap between:

```text
Patch accepted
```

and:

```text
Requested Elementor values are actually present after save
```

The final visual review and Update/Publish decision still belongs to the user in Elementor.

## Lossless round trip

Cresco preserves unknown safe Elementor fields during lossless operations.

The design goal is:

```text
Elementor element
  -> Export
  -> No intentional AI change
  -> Import
  -> Equivalent Elementor element
```

A future Elementor or addon field should not disappear simply because Cresco does not know its semantic meaning yet.

## Patch format

AI returns:

```json
{
  "schema": "cresco-layer-patch/v1",
  "base": {
    "postId": 123,
    "checksum": "document-sha256"
  },
  "scope": {
    "mode": "subtree",
    "rootElementId": "abc123",
    "elementIds": ["abc123"],
    "checksum": "scope-sha256"
  },
  "label": "Upgrade hero",
  "operations": [
    {
      "operation": "update-setting",
      "elementId": "abc123",
      "setting": "content_width",
      "value": "boxed"
    }
  ]
}
```

Supported operations:

- `update-setting`
- `remove-setting`
- `replace-settings`
- `replace-element`
- `insert-element`
- `remove-element`
- `move-element`
- `update-page-setting`
- `remove-page-setting`
- `replace-document`

See [`docs/AI-PATCH-SPEC.md`](docs/AI-PATCH-SPEC.md).

## Screenshot-to-Elementor workflow

A practical workflow is:

1. Open an Elementor page or create a blank target page.
2. Export the entire document context or a target subtree.
3. Give the Cresco package and reference screenshot to an AI with vision.
4. Ask the AI to use only controls/widgets exposed in the package and return `cresco-layer-patch/v1` JSON.
5. Validate and preview in Cresco.
6. Apply to Elementor working data.
7. Review visually in Elementor.
8. Use Elementor Update/Publish only after approval.

For a new full-page reconstruction, AI can use `replace-document`; for normal daily work, smaller scoped operations are preferred.

## Admin AI Exchange

The existing **Elementor → Cresco Layer** screen remains available for document-level export, quality audit, runtime Elementor catalog inspection and patch preview/apply.

The 0.10 admin experience is organized into three tabs — **AI Exchange**, **Runtime Inspector** and **Local AI** (administrators only) — on a token-driven design system with an optional dark mode. The active tab and theme are remembered per browser. The AI Exchange tab adds a four-step workflow strip, a drag-and-drop / file-picker loader for `cresco-layer-patch/v1` JSON, live patch validation (JSON syntax, schema and operation count are checked as you type), a direct “Open this document in Elementor” link, `Ctrl+Enter` to validate, toast notifications for results, and skeleton loading states in the runtime inspector.

Cresco audits include design/accessibility/performance signals such as nesting, missing image alt text, multiple H1s, button naming, image sizing and local color proliferation.

## Existing Cresco Elementor extensions

Cresco Layer also currently registers:

- Cresco Advanced Heading
- Cresco Advanced Button
- Cresco Smart Image
- Cresco Advanced Icon
- Cresco Divider
- Cresco Spacer
- Cresco Post Meta Dynamic Tag
- Cresco Site Info Dynamic Tag
- Pro Theme Conditions for logged-in visitor and user role
- Cresco Workflow Event for Elementor Pro Forms

These remain secondary to the AI interchange architecture; Cresco does not need to recreate every Elementor widget.

## Security

AI packages redact key names resembling credentials, passwords, API keys, private keys, tokens, authorization data, nonces and secrets.

AI patches are validated before preview and again before apply. Cresco rejects active script/iframe/object/embed markup, JavaScript URLs, inline event handlers, dangerous secret keys, duplicate IDs, cyclic moves, scoped operations outside the exported target and semantically invalid Elementor settings.

The Elementor runtime catalog is read-only and uses the same secret-like key redaction policy before returning active Kit/control configuration to the browser.

Cresco writes reviewed results through Elementor's Document API rather than direct `_elementor_data` updates. Published/private content is kept in working/autosave data where supported; AI Apply does not mean Publish.

## Development checks

```bash
php scripts/check-architecture.php
php tests/php/patch-validator-test.php
php tests/php/semantic-patch-guard-test.php
php tests/php/element-locator-test.php
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
node --check assets/admin.js
node --check assets/editor.js
```

## Architecture

See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).
