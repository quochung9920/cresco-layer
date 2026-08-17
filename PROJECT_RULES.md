# Project Rules

> Repository scope: `quochung9920/cresco-layer` (Cresco Layer WordPress plugin).
> Audited against `main` at `b213f31aed93295d52c42fed5d33afbe1ab40577`, plugin/package version `0.24.3`, on 2026-08-17.
>
> This file is the default engineering policy for future AI Coding Agents and developers. Direct user instructions have higher priority. When a factual statement about current behavior conflicts with the repository, current code + current contract tests are authoritative and this file must be updated in the same change.

## 1. Project Overview

Cresco Layer is a **file-based, lossless, runtime-aware bridge between Elementor and external AI**. It is not a replacement page builder and it must not create a competing Elementor document model.

Canonical workflow:

```text
Elementor
→ Cresco Export for ChatGPT
→ external ZIP/JSON package
→ ChatGPT / external AI
→ Cresco Import AI Result
→ Preview + validation
→ Apply through Elementor APIs
→ read-back verification
→ rendered/fidelity verification
```

Core invariant: **Elementor is the source of truth** for documents, widgets, controls, breakpoints, Global Styles, rendering, history and persistence.

System requirements confirmed in the repository:

- WordPress 6.6+
- PHP 8.1+
- Node.js 20+ for development/tests
- Elementor required
- Elementor Pro optional at plugin boot, but required for Pro-only integrations such as Dynamic Tags/Pro Forms/Theme Conditions

### Repository scope vs website scope

This repository is a **plugin repository**, not the full WordPress website repository.

Not present / not confirmed from this repository:

- active WordPress theme implementation;
- child theme;
- theme `functions.php`;
- theme template overrides;
- website-wide custom CSS/JS outside Cresco;
- actual site content/templates;
- actual downstream `lisa-*` CSS implementation.

`PackageBuilder` reads the active theme, Elementor Kit, breakpoints and runtime controls dynamically at runtime. Never hard-code a theme assumption into Cresco because one local test site uses it.

## 2. Source-of-Truth Order

When sources disagree, use this order:

1. current runtime/code;
2. current contract/behavior tests and architecture invariants;
3. `PROJECT_RULES.md`;
4. current 0.24+ technical docs in `docs/`;
5. older/legacy docs.

Do not preserve an obsolete rule simply because an old document says so. Update docs/rules when architecture intentionally changes.

## 3. Repository Structure

```text
cresco-layer.php                 Plugin bootstrap/version/autoloader
includes/Plugin.php              Main service wiring and WordPress/Elementor hooks
includes/AI/                     Export/import, runtime capability, mutation/patch, fidelity policy
includes/Elementor/              Runtime discovery, snapshots, custom Elementor widgets/integrations
includes/SiteSettings/           Elementor Kit / Global Settings engine
includes/DesignSystem/           Fluid scale, contrast, presets, design-standard planning
includes/Audit/                  Accessibility/performance/design audit logic
includes/Diagnostics/            Export diagnostics/fatal recovery
includes/Skills/                 Deterministic runtime widget skills
includes/LocalAI/                Local AI settings/provider/context layer
includes/Admin/                  Cresco admin screen
includes/REST/                   REST controllers
includes/Support/                Assets, requirements, serialization helpers
assets/                          Admin/editor/frontend CSS and browser JS
tests/js/                        JS contract/behavior tests
tests/php/                       PHP contract/behavior tests
scripts/                         Architecture and lint checks
docs/                            Technical documentation
.github/workflows/ci.yml         CI quality pipeline
```

Important entry points:

- PHP: `cresco-layer.php` → `CrescoLayer\Plugin::boot()`.
- REST namespace: `cresco-layer/v1`.
- Elementor editor startup: `assets/editor-bootstrap.js` + `assets/export-target-sync.js` only.
- External exchange lazy pipeline: loaded by `assets/editor-bootstrap.js` after explicit user action.
- Frontend widget base CSS: `assets/frontend.css`.
- Admin design tokens/UI: `assets/admin.css`.

## 4. Non-Negotiable Architecture Invariants

Do not violate these without an explicit architecture change + tests + docs:

1. **Never write Elementor document content directly to `_elementor_data`.** Use Elementor Document APIs.
2. **Never write Site Settings behind the Elementor Kit API** (for example raw `_elementor_page_settings` writes).
3. Never use `eval()`, `shell_exec()`, `exec()` or equivalent dynamic-code shortcuts.
4. Resolve target/scope before persistence; a patch without permission must not gain document-wide access.
5. Runtime capability is authoritative when available. Never invent Elementor controls, responsive suffixes, units, options, Dynamic Tags or global references.
6. Preserve unknown persisted Elementor/addon/Atomic fields when they are not the requested mutation. Unknown persisted data is not permission for AI to invent new settings.
7. Prefer native Elementor controls over `custom_css` when the runtime can express the property natively.
8. Prefer active Elementor Global Styles/Kit tokens over local near-duplicates.
9. `save()` success is not final proof. Use read-back verification where the workflow requires accuracy.
10. Render/fidelity success requires real rendered evidence. **No evidence is not PASS.**
11. The user retains the final Elementor Update/Publish decision.
12. Site Settings and element/page mutation remain separate contracts/pipelines.
13. Cresco must fail closed on safety/validation uncertainty and fail soft only for explicitly optional enrichment.

Run `php scripts/check-architecture.php` when changing architecture-sensitive PHP/JS.

## 5. PHP Conventions

Follow the existing codebase, not a new parallel style.

- Namespace root: `CrescoLayer\`.
- Class files use PascalCase names matching the class (`PackageBuilder.php`, `ExportRuntimeCatalog.php`).
- Most services are `final class` unless inheritance is required by an external API (for example Elementor widgets).
- Use PHP 8.1 typed properties/return types where the surrounding module does.
- Existing method/property naming is predominantly `snake_case`; preserve the style of the file/module being edited.
- Constants use uppercase snake case.
- Use WordPress APIs and Elementor APIs instead of reimplementing platform behavior.
- User-facing strings use the `cresco-layer` text domain and WordPress escaping/translation helpers.
- Sanitize input at boundaries and escape output at render boundaries.
- Prefer small targeted methods over rewriting an entire service to change one behavior.

When reading query parameters only for routing/context, keep the existing explicit `phpcs:ignore` justification pattern if nonce verification is intentionally not applicable.

## 6. REST / Security Rules

Every REST route must have an explicit permission model.

Existing patterns:

- generic runtime inspection: `edit_posts` where appropriate;
- document mutation/export: `current_user_can( 'edit_post', $post_id )`;
- full runtime snapshots/global configuration: `manage_options`.

Rules:

- sanitize route/query arguments with WordPress sanitizers;
- JSON write endpoints must reject malformed/non-object payloads;
- preserve `X-WP-Nonce` behavior in browser requests;
- never expose secrets or private credentials in export packages;
- keep `SerializableSanitizer` and sensitive-setting guards as separate defense layers;
- do not log raw secrets in diagnostics;
- error responses should remain machine-readable and should preserve Cresco diagnostic IDs/stages when available.

Do not weaken capability checks to make a local demo pass.

## 7. Elementor Runtime Rules

### Runtime discovery

Cresco must inspect the Elementor runtime that is actually active. Hard-coded widget/control lists may be **hints/candidates only**, never the final authority.

Before adding support for a control:

1. prove the runtime control exists;
2. inspect its metadata/type/responsive support/units/options/range;
3. update normalizer/adapter only if needed;
4. add regression coverage;
5. ensure other Elementor/addon versions remain fail-safe.

### Current bounded export context

Current implementation deliberately separates **full registry awareness** from **detailed control hydration**:

- full registry index remains available;
- server detailed capability budget: 12 widgets / 6 element types;
- editable/read-only-context types are required and must not be silently truncated;
- construction candidates outrank generic registry entries;
- external-export Dynamic Tags use compact metadata, not full control/editor config hydration;
- runtime modules are summarized without instantiating every module;
- Exact Runtime reuses server-provided detail and only fetches missing capability;
- Exact Runtime uses bounded optional fetches/workers; required target/context capabilities remain fail-closed.

These are **resource safety budgets**, not quality targets. If changing them, update tests, diagnostics and documentation and measure long-document/runtime impact.

## 8. Export Target Synchronization

Do not return to the old pattern "client selected ID → backend missing → tell user to reselect" without diagnosis.

Canonical preflight:

```text
user clicks Export
→ Elementor force autosave via Commands API
→ export-target-status
→ resolve working/autosave + main document
→ bounded retry
→ only then run the export pipeline
```

Rules:

- `ExportTargetResolver` is read-only;
- never replace canonical Elementor data with clipboard/client JSON;
- do not write `_elementor_data` to repair synchronization;
- preflight work starts only after explicit Export action;
- retries must be bounded;
- a stale/unsynchronized target must stop export rather than send stale data to external AI.

## 9. Safe Bootstrap / Editor Critical Path

**Elementor usability has priority over Cresco exchange availability.**

Before the user opens Cresco, startup-safe code must not:

- run runtime scanners;
- capture computed styles/geometry;
- build AI context;
- call export/import REST APIs;
- run autosave;
- install DOM-wide `MutationObserver` loops;
- use `setInterval` polling;
- monkey-patch `window.fetch`;
- block Elementor indefinitely.

Current startup-safe scripts:

```text
editor-bootstrap.js
export-target-sync.js
```

Heavy external exchange scripts are lazy-loaded after explicit user action and in a deterministic order.

`editor-bootstrap.js` uses one bounded Elementor-ready watchdog (currently 8000 ms). Timeout means Cresco becomes passive; do not add infinite retries.

Emergency rescue mode must remain available:

```text
&cresco_safe=1
```

If adding an editor feature, classify it first as:

```text
startup-safe | user-triggered | post-import verification | legacy/admin-only
```

Only genuine startup-safe work belongs on Elementor's critical startup path.

## 10. JavaScript Conventions

Browser assets generally use an IIFE + `'use strict'` and expose only intentional globals such as:

```text
window.CrescoLayerSafeBootstrap
window.CrescoLayerExactRuntimeExport
window.CrescoLayerExportDiagnostics
window.CrescoLayerAIPanel
```

Localized configuration uses lower-camel globals such as `window.crescoLayerEditor` / `window.crescoLayerAdmin`.

Rules:

- preserve the style of the existing asset (many runtime files intentionally use `var`/function syntax);
- do not add a framework or jQuery dependency for small behavior;
- prefer native browser APIs and event delegation;
- avoid repeated unbounded DOM queries and heavy scroll/resize handlers;
- use `requestAnimationFrame`, observers and passive listeners only when they solve a real problem and are bounded appropriately;
- avoid global variables except documented `CrescoLayer*` integration surfaces;
- never install multiple wrappers/listeners for the same concern without an idempotency guard.

### Fetch wrappers

Fetch wrapper load order is an architecture contract.

A wrapper must:

- capture the upstream fetch at load time;
- intercept only its exact Cresco endpoint(s);
- forward all other requests unchanged;
- clone a response before consuming its body;
- preserve relevant status/statusText/headers;
- avoid recursion;
- fail soft only for optional enrichment;
- preserve/augment diagnostic payloads rather than hiding the server error.

When adding a runtime JS file:

1. add it to `npm run lint:js`;
2. define lazy/enqueue dependency order;
3. add a contract test at minimum;
4. add the test to `npm run check`.

## 11. CSS / UI Conventions

Cresco plugin CSS and downstream website CSS are separate concerns.

### Cresco plugin UI

Confirmed plugin naming:

```text
.cresco-layer-*
.cresco-ai-*
.cresco-layer-component__part
.cresco-layer-component--variant
.is-active / .is-error / .is-warning / ...
```

Admin design tokens are scoped under `.cresco-layer-admin` using `--cl-*`, including surface, text, accent, status, radius, shadow and mono-font tokens. Reuse these tokens for new admin UI instead of creating a second admin token system.

Frontend widget classes currently include:

```text
.cresco-layer-heading
.cresco-layer-button-wrap
.cresco-layer-button
.cresco-layer-image
.cresco-layer-icon
.cresco-layer-divider
.cresco-layer-spacer
```

Rules:

- keep frontend base CSS minimal; Elementor controls should own configurable visual values;
- keep selectors low-specificity and component-scoped;
- preserve `:focus-visible` behavior;
- preserve `prefers-reduced-motion` behavior;
- prefer CSS custom properties, flex/grid and `gap` where useful;
- avoid new ID selectors for styling;
- avoid `!important` by default; use it only for a demonstrated Elementor/WordPress/third-party specificity need and explain why when non-obvious;
- do not mass-refactor minified/legacy CSS in an unrelated feature task;
- do not leak admin/editor styles into frontend output.

Do **not** rename Cresco plugin classes to `lisa-*`. The two namespaces serve different systems.

## 12. Elementor Custom Widgets

Registered Cresco widgets currently include:

```text
cresco-advanced-heading
cresco-advanced-button
cresco-smart-image
cresco-advanced-icon
cresco-divider
cresco-spacer
```

For new/changed widgets:

- use Elementor `Controls_Manager` / group controls / responsive controls rather than custom one-off state where possible;
- use selectors tied to the widget wrapper/component class;
- support dynamic fields only where Elementor supports them;
- escape rendered text/attributes;
- use Elementor link/icon helpers where applicable;
- keep accessible names/focus behavior intact;
- register through `WidgetRegistry`, not ad-hoc global hooks scattered across files.

## 13. AI Contracts / Schemas

Schemas are versioned API contracts. Examples currently used by the project include:

```text
cresco-layer-ai-package/v2
cresco-ai-context/v3
cresco-control-registry/v1
cresco-external-ai-package/v1
cresco-ai-bundle/v4
cresco-external-exchange-policy/v1
cresco-ai-mutation/v3
cresco-ai-mutation/v2
cresco-layer-patch/v1
cresco-layer-patch-validation/v2
cresco-site-settings/v1
cresco-fidelity-*/v1
```

Rules:

- prefer backward-compatible optional metadata over bumping a transport schema;
- if semantics/required fields change, intentionally version the contract;
- update normalizer/compiler/validator/applier/diff/package instructions/tests/docs together when a contract changes;
- do not add a patch operation only as a shortcut for a use case already expressible safely;
- element/subtree external AI favors semantic mutation; document-wide work favors document patch;
- preserve existing element IDs; new IDs must be collision-free and allocated by Cresco/Elementor-aware logic;
- do not require an external AI to echo internal data that Cresco can deterministically resolve itself.

## 14. Import / Patch Rules

Import trust chain:

```text
AI result
→ normalize/compile
→ JSON schema/sensitive-key validation
→ runtime capability validation
→ semantic guard
→ scope enforcement
→ preview/diff
→ apply
→ read-back verification
→ rendered/fidelity verification
```

Preview and Apply must share the same interpretation/compilation path.

Never bypass validation because an AI result "looks correct".

For new patch operations, update all of:

- `PatchValidator::ALLOWED_OPERATIONS`;
- shape/value validation;
- scope enforcement;
- applier;
- diff/details/history where relevant;
- package/output instructions;
- contract + behavior tests;
- schema/docs.

## 15. Site Settings / Global Design System

Site Settings is a separate engine for the active Elementor Kit.

Pipeline:

```text
semantic spec
→ validate
→ capability discovery
→ active Kit snapshot
→ adapter mapping
→ diff
→ no-op or Kit save
→ read-back normalization/verification
→ rollback on verification failure
→ cache invalidation
→ ownership bookkeeping
```

Rules:

- active Kit is the source of truth;
- adapters map semantic paths to actual Elementor controls;
- unsupported controls are skipped/reported, never written as invented keys;
- `no_op` is a valid successful result;
- rollback must also be verified;
- preserve user/third-party globals outside Cresco ownership;
- custom Elementor global IDs are stable via ownership bookkeeping, not by title alone;
- managed Global Custom CSS may only replace the Cresco-owned marker block, never unrelated user CSS;
- use `clamp()`/custom units only when capability discovery proves the control supports them and the CSS expression passes the allowlist validator.

## 16. Design System / Responsive Rules

Within Cresco's design-standard engine:

- fluid scaling and structural breakpoints are distinct concerns;
- use `clamp()` for safe continuous scaling when supported;
- use breakpoints for real structural layout changes;
- do not invent a new breakpoint because one spacing value looks slightly off;
- account for container roles so global padding does not create nested double-gutters;
- contrast logic must preserve WCAG-oriented checks already encoded in `DesignSystem/ContrastRatio.php`.

## 17. Downstream Website Foundation (`lisa-*`)

**Source status: user-supplied project convention; not found in this plugin repository. Verify against the actual website/theme/Elementor source before changing site code.**

When Cresco or an AI result is being used to modify that downstream site, preserve these conventions unless the actual site source proves they were superseded:

### Naming

Use `lisa-*` for the website's custom classes. Do not create a parallel site naming system.

Recommended component shape:

```css
.lisa-component {}
.lisa-component__element {}
.lisa-component--variant {}
```

### Typography

- Semantic H1–H6 hierarchy; never choose heading level for visual size only.
- Responsive heading sizing prefers `clamp()`.
- Semantic HTML and visual classes are separate concerns.

### Breadcrumb

- uppercase;
- 14px baseline unless current source demonstrates a newer foundation.

### Hero

```text
Desktop: 190px top padding
Tablet/mobile: 110px top padding
```

Prefer an existing fluid implementation if the live site already replaced the fixed values safely.

### Buttons

Existing site variants:

```text
.lisa-button--rose
.lisa-button--gold
.lisa-button--outline
```

Baseline:

```text
border-radius: 6px
hover: transform: translateY(-3px)
```

Reuse before adding a variant. New variants keep the `lisa-button--*` structure, interaction consistency, focus visibility and reduced-motion behavior.

### Paragraphs / Forms

- reuse the existing last/only-paragraph margin cleanup;
- extend the existing Elementor form foundation for fields, labels, submit, focus/error/success and reduced motion;
- do not create page-specific form CSS when shared behavior already exists;
- placeholders do not replace accessible labels.

### Layout

Existing site layout classes:

```text
.lisa-section
.lisa-content
```

Known widths:

```text
standard max-width: 82rem
reading max-width: 48rem
```

Use the existing full-bleed / standard / reading patterns before inventing a new container width.

### Spacing / Utilities

- reuse the existing gap scale from `2xs` through `section`;
- avoid arbitrary magic values such as `37px`, `23px`, `71px` unless the layout truly requires them;
- existing utilities include `.lisa-card-title` and `.lisa-text__accent`;
- search site source before adding a new utility/component abstraction.

### Elementor globals

Prefer existing Elementor Global Colors, Global Typography/Fonts and CSS variables. Do not hard-code a duplicate color/font/size when an existing token is the intended source.

## 18. Accessibility

Accessibility is part of correctness.

For plugin UI and downstream site work:

- keyboard operation must remain possible;
- focus must remain visible (`:focus-visible` preferred);
- never remove outline without an equivalent visible focus treatment;
- maintain accessible names/labels;
- distinguish links (navigation) from buttons (actions);
- keep logical heading hierarchy;
- respect `prefers-reduced-motion`;
- avoid interaction that depends solely on hover;
- target WCAG 2.2 AA for site-facing design decisions;
- preserve contrast checks and form validation/error feedback.

## 19. Performance

Do not optimize by moving work onto Elementor's startup critical path.

Project-specific performance rules:

- lazy-load detailed runtime capability;
- keep export/runtime scans bounded;
- do not traverse the DOM/catalog without a budget;
- avoid duplicate capability hydration (reuse server detail first);
- cache within request/session only when safe;
- keep verification timeouts bounded;
- Fidelity's element budget is a safety ceiling, not a target to fill;
- do not add heavy third-party browser dependencies for small UI behavior;
- prefer `transform`/`opacity` for animation over layout-triggering properties.

For downstream website work also protect LCP, CLS and INP; size/lazy-load images according to rendered use and do not lazy-load the LCP image when that makes LCP worse.

## 20. Refactoring / Deletion / Over-Engineering

Patch before rewrite.

Refactor only when it improves at least one of:

- maintainability;
- reusability;
- accessibility;
- performance;
- consistency;
- reliability.

Before a large refactor record:

```text
Problem
Root cause
Proposed change
Affected contracts/components
Regression risk
Verification plan
```

Before deleting code:

1. search PHP/JS/CSS usage;
2. search Elementor/runtime references;
3. search tests/docs/contracts;
4. account for dynamically generated markup/registered hooks;
5. if uncertain, keep it and record technical debt.

Do not create a new framework, token system, utility framework or abstraction layer just to make one use case look cleaner.

## 21. Comments

Comments should explain **why**, especially for:

- Elementor limitations/version compatibility;
- startup safety;
- browser/runtime quirks;
- security boundaries;
- ownership/preservation rules;
- non-obvious fail-closed behavior.

Do not narrate obvious syntax.

## 22. Testing / Quality Gate

Standard local quality command:

```bash
npm run check
```

Architecture check:

```bash
php scripts/check-architecture.php
```

PHP files must pass `php -l`. JS runtime files must pass `node --check` through `lint:js`.

When changing behavior:

- update/add a static contract test for architectural/schema presence;
- add behavior coverage for happy path and failure/fail-closed path;
- do not make tests depend on network;
- mock the minimum WordPress/Elementor surface needed;
- add real Elementor manual/integration verification for runtime-dependent behavior.

Recommended runtime matrix when relevant:

```text
Elementor Free
Elementor Pro
Hello Theme
non-Hello theme
classic widgets
container/flex/grid
Atomic/V4 when available
third-party addon sample
published document + autosave
responsive device modes
```

If CI cannot start because of billing/runner/infrastructure, report **CI unavailable**. Never report it as test pass.

## 23. Pre-Change Checklist

Before editing:

```text
[ ] Read PROJECT_RULES.md and relevant docs/tests.
[ ] Identify the layer: startup, export, import, Site Settings, widget, admin, fidelity, etc.
[ ] Search existing class/function/control/schema/component before creating one.
[ ] Confirm the Elementor runtime assumption from code/runtime, not memory.
[ ] Confirm editable scope and persistence owner.
[ ] Check whether an existing contract/operation/token already solves the problem.
[ ] Check startup/editor impact.
[ ] Check responsive/accessibility/security impact.
[ ] Check which contract + behavior tests must change.
[ ] Plan the smallest patch that preserves existing behavior.
```

For downstream website work additionally check:

```text
[ ] Search `lisa-*` foundation/components/utilities first.
[ ] Prefer Elementor globals/tokens before hard-coded design values.
[ ] Confirm desktop/tablet/mobile behavior.
```

## 24. Post-Change Checklist

After editing:

```text
[ ] PHP syntax passes for changed/new PHP.
[ ] JS syntax passes for changed/new runtime JS.
[ ] `npm run check` passes when environment permits.
[ ] `php scripts/check-architecture.php` passes for architecture-sensitive work.
[ ] No new console/PHP errors.
[ ] Elementor editor still opens; Safe Mode remains usable.
[ ] No new unbounded polling/observer/fetch interception on startup.
[ ] Export target sync still fails safely on stale state.
[ ] Scope cannot escape during preview/apply.
[ ] Unknown persisted data remains lossless.
[ ] Read-back verification still reflects persisted truth.
[ ] No-evidence cannot become Fidelity PASS.
[ ] Docs/schema/version updated when contract meaning changed.
```

For visual/site-facing changes also verify desktop/tablet/mobile, keyboard/focus, hover, reduced motion, horizontal overflow, buttons/forms/cards/header/footer as applicable.

## 25. Known Constraints

- Elementor/addon control availability varies by runtime; capability discovery is mandatory.
- Elementor Pro is not guaranteed to be active.
- Atomic/V4 behavior must remain forward-compatible and lossless when metadata is unknown.
- Full Fidelity/raster evidence may require same-origin access to the Elementor preview iframe.
- Published content may be edited through an autosave/working document; do not assume `postId === workingPostId`.
- Runtime/client/autosave state can temporarily diverge; use Target Sync rather than raw client payload persistence.
- External AI is untrusted input even when the package/result was generated by ChatGPT.

## 26. Technical Debt / Needs Verification

Do not silently "fix" these in unrelated tasks; verify and address deliberately:

- Root `README.md` still labels the release as 0.24.0 while plugin/package code is 0.24.3.
- Some technical docs have older version headers (for example architecture docs describing 0.23) even when concepts remain relevant.
- `scripts/check-architecture.php` still searches for the legacy `cresco-context-resolver/v1` token while the current `ContextResolver` reports `cresco-context-resolver/v3`; run/align this checker when next touching architecture tests.
- `tmp-cresco-create-test.txt` exists at repository root; ownership/purpose is not confirmed. Do not delete solely because it looks temporary.
- Active theme, child theme, site `functions.php`, site-wide `lisa-*` source, actual Elementor Global IDs/tokens and current website breakpoints are **Not confirmed in this plugin repository** and must be inspected in the real site/runtime before site-specific changes.

## 27. AI Coding Agent Instructions

Every AI Coding Agent must:

1. read this file before changing code;
2. inspect current source/tests before assuming behavior;
3. search before creating;
4. reuse before duplicating;
5. patch before rewriting;
6. preserve behavior/scope/data before refactoring;
7. use runtime evidence for Elementor capabilities;
8. keep user-triggered heavy work off Elementor startup;
9. validate and verify after changes;
10. explicitly label anything not confirmed from source/runtime.

If a direct user instruction conflicts with this file, the direct instruction has priority, but warn about concrete regression/security/architecture risk before implementing when appropriate.

Do not redesign the project opportunistically. Make the smallest reliable change that fits the existing architecture.
