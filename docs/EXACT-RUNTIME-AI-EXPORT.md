# Exact Runtime AI Export

Elementor editor exports now offer an **Exact Runtime** context mode for redesign tasks. The mode keeps the normal scoped Cresco AI package, then enriches it from Cresco's live lazy Elementor catalog before it is copied or downloaded.

Exact Runtime includes detailed live capability entries for the editable/context types plus a bounded construction set of common layout, content, media, button and form types that are actually registered in the current Elementor runtime. Each entry comes from the runtime catalog with raw metadata enabled, including exact setting keys, defaults, responsive flags, units, ranges, options, conditions, selectors and Atomic bindings/prop schema when Elementor exposes them.

The package adds `runtimeCapabilities`, `capabilityLock` and `siteDesignContext`. The lock forbids invented control keys or responsive suffixes and tells the AI to use `custom_css` only when no current native control can express the required property. Exact Runtime fails closed if a registered required capability cannot be loaded. Smart mode remains available for smaller edit-only packages.
