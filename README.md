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

## Scoped safety

Every scoped package includes both:

- a full working-document checksum;
- a target `scopeChecksum`.

If the footer changes after you export a hero, the hero patch may still be valid. If the hero itself changes, Cresco rejects the stale AI result.

Server-side scope enforcement prevents an AI patch exported for one widget/subtree from modifying unrelated page elements.

## Lossless round trip

Cresco 0.2 introduces `replace-element` and preserves unknown safe Elementor fields.

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

The existing **Elementor → Cresco Layer** screen remains available for document-level export, quality audit and patch preview/apply.

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

AI patches are validated before preview and again before apply. Cresco rejects active script/iframe/object/embed markup, JavaScript URLs, inline event handlers, dangerous secret keys, duplicate IDs, cyclic moves and scoped operations outside the exported target.

Cresco writes reviewed results through Elementor's Document API rather than direct `_elementor_data` updates. Published/private content is kept in working/autosave data where supported; AI Apply does not mean Publish.

## Development checks

```bash
php scripts/check-architecture.php
php tests/php/patch-validator-test.php
php tests/php/element-locator-test.php
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
node --check assets/admin.js
node --check assets/editor.js
```

## Architecture

See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

## Version 0.2 focus

0.2 turns the initial document-level AI bridge into a **scoped, lossless Elementor AI exchange**:

- editor-native widget/subtree export;
- full runtime capability scanning;
- parent/sibling read-only context;
- target-level checksums;
- server-side scope sandbox;
- lossless element replacement;
- full-document replacement for intentional page generation;
- Dynamic Tag/template/referenced-asset context;
- strengthened quality gates and scope tests.
