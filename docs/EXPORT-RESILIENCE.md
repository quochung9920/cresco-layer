# Export Resilience 0.24.3

Cresco Layer keeps the external-first workflow unchanged: Elementor -> export -> ChatGPT -> import.

## What changed

1. The server keeps the full Elementor registry as a light index but limits detailed capability hydration to the current target/context plus a bounded construction set.
2. Dynamic Tag export is metadata-only. It does not call `get_controls()` or `get_editor_config()` for every tag.
3. Module export reads manager names/counts without instantiating every module.
4. Exact Runtime reuses detailed capabilities already returned by the server and fetches only missing capability details with at most two workers.
5. Capabilities required by the editable target/context remain fail-closed. Optional construction capability failures are recorded and omitted instead of failing the whole package.
6. `export-target-status` is explicitly excluded from the Exact Runtime export interceptor.
7. If a read-only Full Context export fails with a server 5xx error, the client retries once with bounded Smart server context. Exact Runtime still validates/enriches the successful retry. The bundle records `manifest.exportRecovery`.
8. Export failures render a diagnostic card inside the Cresco panel with stage, error ID, HTTP status, memory/fatal details when available, and a `Copy diagnostics` action.

## Safety rules

- Recovery is attempted only for GET export requests using `context=full`.
- There is at most one recovery retry (`cresco_recovery=1`).
- Exact Runtime failures for required target capabilities are never retried with weaker capability rules.
- AI may only use element/widget types present in `runtimeCapabilities`.
- Existing/editable target types are never silently truncated.

## Diagnostics

The console API remains available:

```js
window.CrescoLayerExportDiagnostics?.getLastError()
window.CrescoLayerExportDiagnostics?.getHistory()
window.CrescoLayerExportDiagnostics?.copyLastError()
window.CrescoLayerExactRuntimeExport?.getDiagnostics()
```

A successful recovery is recorded as `recovered-smart-context` and includes the first failing stage/error ID.
