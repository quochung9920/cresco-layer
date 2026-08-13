# Cresco Layer Architecture

## Product boundary

Elementor remains the editor, renderer, responsive engine, history owner and persistence source of truth. Cresco Layer is an intelligence/interchange layer; it does not create a second page document model.

The core workflow is:

```text
Elementor working document
  -> Full runtime knowledge + live registries
  -> Cresco Context Resolver
  -> task-specific capability + context export
  -> external AI / local agent
  -> validated Cresco patch
  -> preview + scope guard
  -> Elementor Document::save working data
  -> user reviews in Elementor
  -> user Update/Publish
```

## AI package v2

`cresco-layer-ai-package/v2` remains the transport contract. Cresco 0.5.0 resolves context before building it.

A normal `smart` package contains:

- manifest and exact Elementor/Pro versions;
- document checksum and target-scope checksum;
- raw Elementor element data;
- editable scope and read-only parent/sibling context;
- page settings;
- active Kit/design-system data;
- active breakpoints;
- a compact `registryIndex` of every registered widget and element type;
- detailed control metadata only for types relevant to the editable scope/read-only context plus bounded insertion candidates;
- control defaults, options, ranges, units, conditions, selectors, responsive/dynamic flags where Elementor exposes them;
- registered Dynamic Tags metadata;
- capability coverage/trust information;
- dependency-aware Elementor Pro runtime signals;
- editable Elementor template catalog;
- referenced media metadata;
- audit data;
- provider-neutral AI instructions.

`context=full` expands detailed capability metadata for every registered type when a task genuinely needs it. Even then, the raw Full Runtime Snapshot remains separate.

Secrets are redacted before the package leaves WordPress.

## AI Context Resolver

`cresco-context-resolver/v1` is the bridge between full runtime knowledge and a task-specific AI package.

The default `smart` profile recursively discovers the widget/element types present in the editable Elementor data and read-only context. Document/subtree exports also add a bounded set of common insertion candidates. It loads detailed capabilities only for those types while retaining a summary of every registered type in `registryIndex`.

The resolver also supplies Active Kit settings, global design-system summaries, active breakpoints, Dynamic Tags, Elementor/Pro module counts and dependency-aware Pro feature signals.

`capabilityCoverage` is part of the AI contract. The AI is instructed not to infer controls or runtime data from sources marked `partial` or `unavailable`, and it must never invent a setting merely because a type appears in the compact registry index.

See `docs/AI-CONTEXT-RESOLVER.md` for the profile and package contract.

## Full Elementor runtime snapshot

`cresco-elementor-snapshot/v1` is a separate administrator-only diagnostic/configuration export. It is intentionally not embedded into every AI package because a complete site snapshot can be very large.

The snapshot uses lazy, fault-isolated REST requests. The index advertises sections, registered widgets/elements and Elementor-owned record IDs. The browser downloads each item sequentially and assembles one final JSON file.

Every payload keeps two representations:

- `normalized`: stable data intended for human/AI reasoning;
- `raw`: the closest safe serializable runtime/post/meta representation exposed by the current Elementor installation.

Snapshot coverage includes environment, Elementor-related global options, features/experiments, breakpoints, the active Kit/Site Settings, Dynamic Tags, Elementor/Pro runtime modules and dependency signals, Classic + Atomic widget/element capabilities, and recognized Elementor-owned records such as documents, templates, Theme Builder templates, popups, custom fonts, custom icons and custom code.

Dynamic Tags are read from Elementor registry records through their registered `instance`. Module discovery enumerates `get_modules_names()` and resolves each module with `get_modules($name)`, matching Elementor 4.x method signatures.

A shared `SerializableSanitizer` redacts secrets and reports unsupported runtime objects/resources/callbacks instead of stringifying them. New/unknown Elementor fields are preserved in `raw` whenever they are serializable.

The final browser-built snapshot treats internal `partial`/`failed` scanner coverage as incomplete even when the HTTP request succeeded. Top-level `complete` therefore means all requested buckets completed without hidden partial scanner results.

See `docs/ELEMENTOR-SNAPSHOT.md` for the REST contract and coverage semantics.

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

Classic Elementor entries use `get_controls()` and derive defaults from control metadata without calling `get_settings()` on registry prototypes. Atomic/V4 entries use `get_atomic_controls()` plus `get_props_schema()` and normalize schema-only properties so editable Atomic data is not lost merely because legacy controls are empty.

The control catalog describes what an element *can* do, even when the current document omits a setting because Elementor is using the default.

## Editor-native exchange

Cresco enqueues an editor-only integration through Elementor's editor script hooks and extends the documented element context menu filter. A selected widget/container can be exported as widget-only or subtree scope without leaving Elementor.

Editor exports use the default Smart context profile automatically. The WordPress Cresco admin page additionally exposes an explicit Smart/Full selector for document exports.

The editor import dialog sends `expectedScope` in addition to the patch. Server-side code verifies that the AI result targets the selected element before preview/apply.

## Persistence and publication safety

Cresco never directly writes Elementor `_elementor_data`. Changes are handed to Elementor Document `save()`.

For published/private documents Cresco uses Elementor working/autosave data when available, so AI Apply is not equivalent to publishing. The user retains final Update/Publish control.

## Security rules

- WordPress edit capability is checked per target post.
- Full runtime snapshots require `manage_options`.
- REST requests use WordPress REST nonces.
- secrets and credentials are redacted from exports and rejected in patches;
- unsupported runtime objects/resources/callbacks are omitted from full snapshots and reported;
- active/executable markup is rejected;
- operation counts and nesting depth are bounded;
- duplicate IDs and cyclic moves are rejected;
- scoped patches are server-side sandboxed.

## Quality invariants

The repository quality gate enforces:

- no direct Elementor document meta persistence;
- package v2 + scope checksum presence;
- Context Resolver Smart/Full profiles and compact registry/detailed capability split;
- Dynamic Tags registry-instance discovery and named module discovery;
- dependency-aware Pro capability reporting;
- honest aggregate snapshot coverage;
- scoped patch guard presence;
- lossless element replacement support;
- full runtime snapshot schema/routes/sanitizer presence;
- Classic + Atomic capability scanner tests;
- snapshot serializer + snapshot/runtime discovery/context resolver contract tests;
- editor-native integration registration;
- PHP/JavaScript syntax checks and standalone scope/validator tests.
