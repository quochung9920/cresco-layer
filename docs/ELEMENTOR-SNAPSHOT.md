# Full Elementor Runtime Snapshot v1

`cresco-elementor-snapshot/v1` is the administrator-only configuration snapshot produced by Cresco Layer 0.5.0.

The snapshot is intentionally broader than the widget catalog. Its goal is to collect every meaningful Elementor Core, Elementor Pro and registered addon configuration value that can be serialized safely at runtime, while preserving both an AI-friendly normalized representation and the closest safe raw representation available from WordPress/Elementor.

## Contract

Every lazily loaded snapshot payload contains:

```json
{
  "schema": "cresco-elementor-snapshot/v1",
  "section": "...",
  "normalized": {},
  "raw": {},
  "coverage": {
    "status": "complete|partial|failed",
    "errors": 0,
    "redactions": 0,
    "omissions": 0
  },
  "redactions": [],
  "omissions": [],
  "scanErrors": []
}
```

`normalized` is stable, named data intended for inspection and AI reasoning. `raw` preserves serializable Elementor/WordPress runtime structures so new Elementor fields are not silently lost simply because Cresco does not understand them yet.

## Snapshot sections

The index endpoint advertises these lazy sections:

- `environment`: WordPress/PHP/Elementor/Elementor Pro versions, active theme and active plugin stack;
- `global-settings`: Elementor-related WordPress options, multisite options and current-user Elementor editor metadata;
- `features`: runtime Elementor feature/experiment definitions plus saved experiment states;
- `breakpoints`: all and active Elementor breakpoint definitions exposed by the current runtime;
- `active-kit`: active Site Kit, Site Settings, Global Colors, Global Fonts, settings/data and Kit post meta;
- `dynamic-tags`: registered Dynamic Tags from the current Elementor runtime, including exposed controls;
- `runtime`: Elementor/Elementor Pro module inventory, dependency-aware Pro capability signals and the lightweight widget/element registry;
- `records`: Elementor-owned post types and an index of Elementor documents, templates, Theme Builder records, popups, custom fonts, custom icons, custom code and other recognized Elementor records.

Widget and element details are fetched separately through the runtime `CapabilityScanner`. Classic controls, Atomic/V4 controls and Atomic props schema are all represented.

Elementor-owned records are also fetched one-by-one. A record snapshot preserves its WordPress post fields, post meta, taxonomies, persisted Elementor document data, page settings and current-user working/autosave document data when Elementor exposes it.

## Runtime discovery v2

Cresco 0.5.0 corrects two important Elementor 4.x runtime-discovery assumptions.

Dynamic Tags are read from Elementor's `get_tags()` registry records using their registered `instance` instead of treating the registry record itself as the tag object. If Elementor Pro is active and the registry remains empty after registration is requested, Dynamic Tags coverage becomes `partial` rather than silently returning a trusted empty list.

Core/Pro module discovery now enumerates `get_modules_names()` and resolves each module with `get_modules($name)`. Cresco never calls `get_modules()` without its required module name. This prevents the Elementor Pro `ArgumentCountError` seen with the previous runtime scanner.

The runtime section also reports dependency-aware Pro capability signals. Licensed features whose external dependency (for example WooCommerce, ACF, Pods or Toolset) is not active are identified as `dependency-inactive`; they are not presented as live registered controls.

## REST API

All snapshot routes require `manage_options`.

```text
GET /wp-json/cresco-layer/v1/elementor-snapshot
GET /wp-json/cresco-layer/v1/elementor-snapshot/section/{section}
GET /wp-json/cresco-layer/v1/elementor-snapshot/widget/{name}
GET /wp-json/cresco-layer/v1/elementor-snapshot/element/{name}
GET /wp-json/cresco-layer/v1/elementor-snapshot/record/{postId}
```

The index includes a `downloadPlan` containing the section names, registered widget names, registered element names and recognized Elementor record IDs. The WordPress admin downloader executes that plan sequentially and assembles the final JSON in the browser. This prevents one giant PHP request from exhausting memory and isolates failures to the individual section/widget/element/record that failed.

## Secret handling

Cresco never intentionally exports credentials. The shared `SerializableSanitizer` redacts keys matching common secret/credential patterns, including passwords, API keys, access/refresh tokens, private keys, consumer/client/app secrets, license keys, SMTP passwords, webhook secrets, authorization values and nonces. Non-secret public configuration such as public keys remains available.

It also redacts common secret-bearing URL query parameters and bearer tokens before output.

Unsupported runtime objects, resources, callbacks, object cycles, excessive nesting and bounded-size truncations are not stringified. They are omitted and their paths are reported under `omissions`.

## Coverage semantics

A section is `complete` when its scanner completed without runtime exceptions. It is `partial` when the scanner recovered from one or more exceptions. Redactions and intentional non-serializable runtime omissions do not by themselves mark the section partial; they are expected safety behavior and are counted separately.

The browser-built snapshot aggregates both HTTP/request failures and each payload's internal `coverage.status`. Therefore the top-level snapshot cannot report `complete` when a section/widget/element/record returned `partial`, `failed` or `unavailable` inside an otherwise successful HTTP response.

A failed addon widget therefore produces a partial snapshot with an explicit error instead of making the entire export fail or hiding the missing coverage.

## Relationship to the AI package

The full snapshot is deliberately separate from `cresco-layer-ai-package/v2`. A full-site runtime snapshot can be very large and should not be injected into every AI edit request.

Cresco 0.5.0 adds `cresco-context-resolver/v1`. Normal AI exports use the `smart` profile: the package keeps a compact index of every registered type but expands only controls relevant to the editable scope/read-only context plus a bounded set of insertion candidates. A `full` profile is available when the AI genuinely needs detailed controls for every registered type.

See `docs/AI-CONTEXT-RESOLVER.md` for the complete export/import context contract.
