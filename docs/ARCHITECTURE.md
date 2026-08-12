# Cresco Layer Architecture

## Boundary

Elementor is the platform owner for:

- page/document data
- editor UI and canvas
- responsive controls
- history
- frontend rendering
- persistence
- Theme Builder and Forms when Pro is active

Cresco Layer extends Elementor through addon hooks and document APIs. It does not create a competing React editor, Session model, canvas, navigator or save engine.

## Runtime

```text
WordPress
  └─ Elementor / Elementor Pro
       └─ Cresco Layer
            ├─ Advanced Widgets
            ├─ Dynamic Tags
            ├─ Pro Integrations
            ├─ Audit Engine
            ├─ AI Package Builder
            ├─ Patch Validator
            ├─ Patch Preview / Diff
            └─ Patch Applier -> Elementor working document
```

## AI safety model

```text
Elementor working document / autosave
  ↓ read
AI-safe package builder
  ↓ redact secrets
External AI/provider
  ↓ cresco-layer-patch/v1
Patch validator
  ↓ checksum + schema + active-markup checks
Preview
  ↓ explicit user action
Patch applier
  ↓
Elementor Document::save()
  ↓
Elementor autosave for published/private documents
or draft document for draft content
  ↓
User reviews in Elementor
  ↓
Elementor Update / Publish
```

The external AI is never a persistence owner and never receives a direct publish path.

## Concurrency

Every export contains a SHA-256 checksum of canonicalized Elementor elements and page settings from the current user's working document. Preview and Apply compare that checksum with the current working copy. A stale patch receives a conflict response rather than overwriting newer changes.

## V3 + Atomic/V4 compatibility

The package preserves Elementor's recursive element data. Classic widget elements (`widgetType`, `settings`, `elements`) and Atomic element fields (`version`, `styles`, `interactions`, `editor_settings`, `elements`) are allowed by the patch validator. Cresco Layer does not translate them into a private document schema.

Elementor's active breakpoint configuration is included in the AI package, and classic responsive setting suffixes remain Elementor-owned.

## Security invariants

- REST routes require `edit_post` for the target document.
- WordPress REST nonce authentication is used by the admin UI.
- Export redacts keys that resemble credentials, secrets, API keys, tokens, authorization headers and nonces.
- AI patches cannot modify keys that resemble sensitive credentials.
- AI patch strings reject executable script/iframe/object/embed markup, JavaScript URLs and inline event handlers.
- Elementor remains responsible for its final document/control validation during `Document::save()`.
- The Workflow Event form action is local only; it does not transmit form submissions to a remote endpoint.
- Published/private content is not directly overwritten by AI Apply; Elementor autosave/working data is used for review first.

## Extension direction

Future modules should preserve the same boundary:

- Design Intelligence reads Elementor Kit/global styles rather than creating a parallel token database.
- Accessibility and Performance inspectors report against Elementor working documents.
- Presets and components should reference Elementor/Atomic constructs where public APIs are available.
- AI operations should continue to use validated patches, not arbitrary PHP/JS or direct `_elementor_data` post-meta writes.
