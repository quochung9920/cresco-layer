# Cresco Layer

**Professional intelligence and AI exchange for Elementor + Elementor Pro.**

Cresco Layer keeps Elementor as the source of truth and adds a provider-neutral AI workflow, design/accessibility/performance auditing, advanced widgets, safe dynamic data, Pro Theme Conditions and workflow hooks.

## Product principles

- Elementor remains the editor, renderer, responsive system, history system and persistence owner.
- Cresco Layer never creates a second page document model.
- AI never receives credentials and never writes directly to the live published page.
- AI changes are expressed as a small, validated `cresco-layer-patch/v1` patch.
- A patch must match the checksum of the Elementor working document it was generated from.
- Users preview the patch before applying it, then review and use Elementor Update/Publish normally.

## Current modules

### AI Exchange

From **Elementor → Cresco Layer** in wp-admin:

1. Choose an Elementor document.
2. **Export for AI** to download an AI-safe JSON package from the current Elementor working copy/autosave when present.
3. Give that package to ChatGPT, Claude, Gemini, Ollama or another agent.
4. Ask the AI to return `cresco-layer-patch/v1` JSON.
5. Paste the patch into Cresco Layer.
6. **Validate & Preview**.
7. Review operation counts and before/after quality audit.
8. **Apply reviewed patch**.
9. Open Elementor, review visually and Update/Publish when ready.

For published/private documents, Cresco Layer stores reviewed AI changes in Elementor autosave/working data instead of overwriting the live published page. Draft documents remain drafts.

The export contains document content, page settings, active Kit/design-system context, active breakpoints, widget/control capabilities, a quality audit and AI instructions. Keys that look like passwords, tokens, nonces, API keys or secrets are redacted.

### Design / accessibility / performance audit

The initial audit checks:

- document size and nesting depth
- image alt coverage
- oversized image sources
- heading usage / multiple H1s
- button accessible names
- local color proliferation

It produces separate accessibility, performance and design-consistency scores plus actionable issues.

### Advanced widgets

- Cresco Advanced Heading
- Cresco Advanced Button
- Cresco Smart Image
- Cresco Advanced Icon
- Cresco Divider
- Cresco Spacer

They use Elementor's widget/control APIs and Elementor responsive controls rather than a parallel editor runtime.

### Dynamic data

- Cresco Post Meta
- Cresco Site Info (safe allowlisted site values only)

### Elementor Pro integration

When Elementor Pro is active:

- **Cresco · Logged-in visitor** Theme Condition.
- **Cresco · User role** Theme Condition group with WordPress roles.
- **Cresco Workflow Event** Form action, which fires local WordPress actions without sending submission data to an external service.

## Patch format

```json
{
  "schema": "cresco-layer-patch/v1",
  "base": {
    "postId": 123,
    "checksum": "64-character-sha256"
  },
  "label": "Improve hero",
  "operations": [
    {
      "operation": "update-setting",
      "elementId": "71e85b1",
      "setting": "title_color",
      "value": "#6d28d9"
    }
  ]
}
```

Supported operations:

- `update-setting`
- `remove-setting`
- `replace-settings`
- `insert-element`
- `remove-element`
- `move-element`
- `update-page-setting`
- `remove-page-setting`

See `docs/AI-PATCH-SPEC.md` for details.

## Requirements

- WordPress 6.6+
- PHP 8.1+
- Elementor
- Elementor Pro for Pro-only integrations and active Dynamic Tags behavior

## Development

```bash
php scripts/check-architecture.php
php tests/php/patch-validator-test.php
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
node --check assets/admin.js
```

## Architecture

See `docs/ARCHITECTURE.md`.

## Status

Cresco Layer 0.1 establishes the first production-oriented platform: AI-safe exchange, validated patching, Elementor working-copy persistence, quality auditing, advanced widgets, dynamic tags and Elementor Pro integration. It intentionally does not fork or replace the Elementor editor.
