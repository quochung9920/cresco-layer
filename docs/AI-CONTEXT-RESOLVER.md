# AI Context Resolver v1

Cresco Layer 0.5.0 uses `cresco-context-resolver/v1` to turn the full Elementor runtime knowledge available on a site into a bounded, task-specific `cresco-layer-ai-package/v2`.

## Why the resolver exists

The Full Elementor Runtime Snapshot is a diagnostic/knowledge-base artifact. It may contain hundreds of registered widget/element types, every serializable control, global options, templates and runtime records. Sending the entire snapshot for every AI edit is wasteful and can make the model less precise.

Normal AI export therefore uses the snapshot/runtime as a source of truth but exports only the detailed capabilities that matter to the current editing task.

## Profiles

### `smart` (default)

The default export profile includes:

- the exact editable Elementor element data and checksum-protected scope;
- parent/sibling context as read-only data for scoped exports;
- detailed controls for widget/element types present in the editable scope;
- detailed controls for widget/element types present in the read-only context;
- a bounded set of common insertion candidates for document/subtree editing;
- a compact `registryIndex` containing every registered widget and element type without expanding all their controls;
- active Kit/Site Settings, global colors/fonts through the existing design-system payload, active breakpoints and Dynamic Tags;
- dependency-aware Elementor Pro runtime information;
- `capabilityCoverage` so the AI knows which sources are trusted, partial or unavailable.

This keeps normal exports much smaller than embedding the full runtime catalog.

### `full`

`context=full` expands detailed capability metadata for every currently registered widget and element. This is an escape hatch for unusual tasks where the AI must be free to insert or reason about any registered type.

The full runtime snapshot remains a separate artifact even in this profile; global raw WordPress/Elementor diagnostic data is not injected into every AI edit request.

## REST export

```text
GET /wp-json/cresco-layer/v1/documents/{postId}/export?scope=widget&selected={elementId}&context=smart
GET /wp-json/cresco-layer/v1/documents/{postId}/export?scope=subtree&selected={elementId}&context=full
```

`context=smart` is the default when the parameter is omitted, so existing editor export actions automatically benefit from the resolver without UI changes.

## AI package contract

The package keeps the existing `cresco-layer-ai-package/v2` schema and adds context-resolution metadata rather than replacing the transport contract.

Important fields:

- `manifest.contextProfile`: `smart` or `full`;
- `manifest.contextResolver`: resolver version;
- `registryIndex`: all registered type summaries;
- `widgetCatalog` / `elementCatalog`: detailed controls selected for the current task;
- `relevantCapabilities.roles`: why each detailed capability was included (`editable`, `read-only-context`, `insertion-candidate`, or `full-profile`);
- `dynamicTags`: registered runtime Dynamic Tags;
- `capabilityCoverage`: trust state for controls, Active Kit, breakpoints, Dynamic Tags and Pro runtime modules;
- `contextResolver.stats`: registered-versus-expanded counts and scan error count;
- `contextResolver.runtime.dependencies`: dependency signals and licensed-but-inactive Pro capabilities.

The `designSystem` field remains the Active Kit settings array for backward compatibility with existing AI package consumers.

## Dynamic Tags discovery

Elementor's Dynamic Tags manager returns registry records shaped around a registered `instance` plus its class. Cresco 0.5.0 reads that registry shape directly instead of treating each registry record as the tag object itself. Calling `get_tags()` also lets Elementor fire its normal Dynamic Tags registration hook when necessary.

If Elementor Pro is active but the registry is still empty after registration is requested, Cresco marks Dynamic Tags coverage as `partial` instead of silently reporting an empty trusted catalog.

## Elementor Pro module discovery

Elementor/Elementor Pro module managers expose module names separately from the module getter. Cresco now enumerates `get_modules_names()` and resolves each module with `get_modules($name)`. It never calls `get_modules()` without the required module name.

This fixes the runtime snapshot failure previously caused by `ArgumentCountError` on Elementor Pro 4.x.

## Dependency-aware capabilities

A Pro license can advertise capabilities whose external dependency is not active on the current WordPress site. Cresco reports these separately so the AI does not confuse licensed potential with live runtime availability.

Current dependency signals include WooCommerce, ACF, Pods and Toolset. For example, WooCommerce-related Pro features may be licensed while WooCommerce is inactive; such capabilities are reported as `dependency-inactive` rather than being invented in the live widget catalog.

## AI safety rules

The package instructions explicitly require the AI to:

- never invent an Elementor setting name;
- modify settings only when a detailed capability entry backs the setting;
- treat `registryIndex` as discovery metadata, not permission to invent controls;
- request/use `full` context when detailed controls for an unusual type are required;
- avoid relying on any source marked `partial` or `unavailable`;
- preserve IDs, unknown Elementor fields, Atomic/V4 data, Dynamic Tags and global style references unless intentionally changing them;
- return only `cresco-layer-patch/v1` JSON.

Cresco's existing schema validation, scope validation, semantic control/value validation, preview, apply transaction and read-back verification remain authoritative after the AI returns a patch.

## Snapshot relationship

The Full Runtime Snapshot and AI Context Package have separate responsibilities:

```text
Elementor runtime
  -> Full Runtime Snapshot / registries (knowledge source)
  -> Context Resolver
  -> task-specific AI package
  -> AI
  -> cresco-layer-patch/v1
  -> validation + preview + apply + verification
  -> Elementor
```

The snapshot should be used for diagnostics and full-site inspection. The context-resolved AI package should be used for normal external AI analysis/editing.
