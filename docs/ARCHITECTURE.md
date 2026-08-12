# Cresco Layer Architecture

## Product boundary

Elementor remains the editor, renderer, responsive engine, history owner and persistence source of truth. Cresco Layer is an intelligence/interchange layer; it does not create a second page document model.

The core workflow is:

```text
Elementor working document
  -> Cresco capability + context export
  -> external AI / local agent
  -> validated Cresco patch
  -> preview + scope guard
  -> Elementor Document::save working data
  -> user reviews in Elementor
  -> user Update/Publish
```

## AI package v2

`cresco-layer-ai-package/v2` contains:

- manifest and exact Elementor/Pro versions;
- document checksum and target-scope checksum;
- raw Elementor element data;
- editable scope and read-only parent/sibling context;
- page settings;
- active Kit/design-system data;
- active breakpoints;
- complete registered widget and element control metadata available at runtime;
- control defaults, options, ranges, units, conditions, selectors, responsive/dynamic flags where Elementor exposes them;
- registered Dynamic Tags metadata;
- editable Elementor template catalog;
- referenced media metadata;
- audit data;
- provider-neutral AI instructions.

Secrets are redacted before the package leaves WordPress.

## Scope model

Supported export scopes:

- `document`: page/template plus page settings;
- `widget`: selected element settings only; descendants are read-only/preserved;
- `subtree`: selected root plus every descendant;
- `selection`: multiple explicitly selected roots without implicit descendants.

Each non-document package includes a `scopeChecksum`. A patch can survive unrelated page edits while remaining blocked when the exported target changed.

## Lossless element contract

Raw Elementor data is authoritative. Cresco does not normalize Elementor elements into its own schema.

Known fields are validated. Unknown safe element fields are preserved so that Atomic data, addon metadata and future Elementor fields are not destroyed during round trips.

`replace-element` enables a complete element round trip. Widget scope forces child preservation; subtree scope allows intentional descendant changes.

## Capability scanner

The scanner queries Elementor's registered widget manager and element manager at runtime. This means the AI package describes the actual installation, including registered addon widgets, instead of assuming a fixed Elementor Pro catalog.

Control metadata includes the values Cresco can safely serialize from Elementor's control stack: type, label, description, defaults, options, responsive/dynamic flags, units, ranges, selectors, conditions, render type and related metadata.

The control catalog describes what an element *can* do, even when the current document omits a setting because Elementor is using the default.

## Editor-native exchange

Cresco enqueues an editor-only integration through Elementor's editor script hooks and extends the documented element context menu filter. A selected widget/container can be exported as widget-only or subtree scope without leaving Elementor.

The editor import dialog sends `expectedScope` in addition to the patch. Server-side code verifies that the AI result targets the selected element before preview/apply.

## Persistence and publication safety

Cresco never directly writes Elementor `_elementor_data`. Changes are handed to Elementor Document `save()`.

For published/private documents Cresco uses Elementor working/autosave data when available, so AI Apply is not equivalent to publishing. The user retains final Update/Publish control.

## Security rules

- WordPress edit capability is checked per target post.
- REST requests use WordPress REST nonces.
- secrets and credentials are redacted from exports and rejected in patches;
- active/executable markup is rejected;
- operation counts and nesting depth are bounded;
- duplicate IDs and cyclic moves are rejected;
- scoped patches are server-side sandboxed.

## Quality invariants

The repository quality gate enforces:

- no direct Elementor document meta persistence;
- package v2 + scope checksum presence;
- scoped patch guard presence;
- lossless element replacement support;
- editor-native integration registration;
- syntax checks and standalone scope/validator tests.
