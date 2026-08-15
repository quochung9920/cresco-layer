# Cresco Layer AI Patch v1

Schema identifier: `cresco-layer-patch/v1`.

Cresco Layer keeps the patch schema identifier stable while using a **checksum-free AI patch contract**. AI patches identify the WordPress post and editable Elementor scope, while Cresco validates the current target/scope and runtime capabilities at preview/apply time. Checksums are not part of the AI exchange contract.

## Required envelope

```json
{
  "schema": "cresco-layer-patch/v1",
  "base": {
    "postId": 123
  },
  "label": "Human-readable change label",
  "operations": []
}
```

`base.postId` is required and must match the document being edited. Do not include a document checksum. Older patches that still contain checksum fields are accepted, but the validator strips those fields and does not use them as an apply precondition.

## Scoped widget / subtree / selection patches

Packages exported with `widget`, `subtree` or `selection` scope contain `editableScope`. Copy only the scope identity into the AI patch:

```json
{
  "schema": "cresco-layer-patch/v1",
  "base": {
    "postId": 123
  },
  "scope": {
    "mode": "subtree",
    "rootElementId": "abc123",
    "elementIds": ["abc123"]
  },
  "label": "Upgrade hero",
  "operations": []
}
```

There is no freshness checksum to copy or refresh. This keeps visual iteration simple: export the runtime context, generate patches, preview them and apply them without re-exporting just because the Elementor working document changed.

Scoped patches are still sandboxed:

- the requested `postId` must match the current document;
- editor-native import can require the patch root to match the currently selected Elementor element;
- the scoped target must still exist when preview/apply runs;
- element mutations must target an editable ID in the current scope;
- new descendants may only be inserted below an editable parent;
- page-setting and full-document operations are rejected outside `document` scope;
- widget-only scope cannot insert or move children.

## Native Elementor control policy

The current Elementor installation is the source of truth for controls. AI should use control names and metadata from Exact Runtime / `runtimeCapabilities`, `widgetCatalog`, `elementCatalog`, `relevantCapabilities` and `elementStates`.

For normal layout/style changes:

- prefer a native Elementor setting whenever the target element exposes one;
- use responsive suffixes only when the base control is responsive, for example `padding_tablet`, `padding_mobile`, `min_height_tablet` or `min_height_mobile`;
- obey the control's options, units, ranges and device support;
- do not invent setting keys;
- use parent Container `gap`/responsive gap for sibling rhythm instead of stacking margins where practical;
- use `custom_css` only as a fallback for an effect that cannot be represented by the exposed native controls.

Cresco semantically validates these rules before an AI patch can be applied. Existing persisted addon/future settings that are not currently described by the capability catalog can still be preserved and explicitly modified, but Cresco reports that native metadata validation is unavailable for them.

## Operations

### `update-setting`

```json
{
  "operation": "update-setting",
  "elementId": "abc123",
  "setting": "title_color",
  "value": "#6d28d9"
}
```

Prefer targeted updates because settings omitted by the patch remain unchanged, including responsive values, Dynamic Tags and global references.

Responsive example:

```json
{
  "operation": "update-setting",
  "elementId": "abc123",
  "setting": "padding_tablet",
  "value": {
    "unit": "px",
    "top": "40",
    "right": "32",
    "bottom": "40",
    "left": "32",
    "isLinked": false
  }
}
```

### `remove-setting`

```json
{
  "operation": "remove-setting",
  "elementId": "abc123",
  "setting": "title_color"
}
```

### `replace-settings`

Replaces the persisted `settings` object for one existing element. Use sparingly; targeted updates are safer.

```json
{
  "operation": "replace-settings",
  "elementId": "abc123",
  "settings": {}
}
```

Cresco's semantic guard rejects a replacement that would silently drop existing global references or unknown persisted settings. Remove such settings explicitly if their removal is intentional.

### `replace-element`

Losslessly replaces a complete Elementor element object. Safe unknown fields are preserved by the validator instead of being reduced to a hard-coded field allowlist.

```json
{
  "operation": "replace-element",
  "elementId": "abc123",
  "preserveChildren": true,
  "element": {
    "id": "abc123",
    "elType": "container",
    "settings": {},
    "styles": {},
    "interactions": {},
    "editor_settings": {},
    "elements": []
  }
}
```

The replacement ID must equal `elementId`. In `widget` scope, existing children are always preserved even if the AI omitted them. Use subtree scope when children are intentionally redesigned.

### `insert-element`

`parentId` may be empty only for document-level patches.

```json
{
  "operation": "insert-element",
  "parentId": "container123",
  "position": 0,
  "element": {
    "id": "new12345",
    "elType": "widget",
    "widgetType": "heading",
    "settings": {
      "title": "New heading"
    },
    "elements": []
  }
}
```

Inserted element IDs must be unique across the working document. New element settings are checked against the current runtime capability catalog.

### `remove-element`

```json
{
  "operation": "remove-element",
  "elementId": "abc123"
}
```

### `move-element`

```json
{
  "operation": "move-element",
  "elementId": "abc123",
  "parentId": "target456",
  "position": 2
}
```

Moving into an element's own descendant is rejected. Scoped patches cannot move elements outside their current editable scope.

### `update-page-setting`

```json
{
  "operation": "update-page-setting",
  "setting": "background_color",
  "value": "#ffffff"
}
```

Document scope only.

### `remove-page-setting`

```json
{
  "operation": "remove-page-setting",
  "setting": "background_color"
}
```

Document scope only.

### `replace-document`

Used when an AI is intentionally generating/replacing an entire Elementor page from a reference design. It is not allowed in widget/subtree/selection scope.

```json
{
  "operation": "replace-document",
  "content": [
    {
      "id": "root1234",
      "elType": "container",
      "settings": {},
      "elements": []
    }
  ],
  "pageSettings": {}
}
```

Cresco validates the complete tree, rejects duplicate IDs and still writes through Elementor's document persistence layer rather than directly updating `_elementor_data`.

## Effective-change validation

A syntactically valid patch is not necessarily a useful patch. Cresco analyzes operations before apply and reports whether they are likely to have an effective Elementor change.

The semantic guard detects cases including:

- an `update-setting` that already equals the persisted value;
- removing a setting that is already absent;
- a responsive suffix used on a non-responsive control;
- a value outside a control's supported options, units or numeric range;
- a newly invented setting that is not in the target capability catalog;
- destructive replacements that would drop global references or unknown persisted settings;
- custom CSS that declares synthetic layout variables such as `--padding-top` or `--min-height` but never consumes them with `var(...)`.

That last rule prevents a class of visual no-op AI patches. For example, this is rejected:

```css
selector {
  --padding-top: 40px;
  --min-height: auto;
}
```

because those declarations do not change layout by themselves. If Elementor exposes native padding/min-height controls, use those settings instead.

Direct custom CSS that duplicates a related native Elementor control is reported as a fallback warning so the patch can be reviewed and rewritten with native settings where practical.

## Preview, apply and rollback

Preview resolves the patch against the **current** Elementor working document. Cresco validates the requested post, selected scope, target existence and operation boundaries, then shows the diff and semantic audit. It does not reject the patch because an earlier export hash changed.

After Elementor saves a reviewed patch, Cresco reads working data back and verifies the requested operations. The apply response contains a `verification` summary with passed/failed operation counts and per-operation details.

This distinguishes:

- **accepted patch** — the request passed validation;
- **saved patch** — Elementor accepted the document save;
- **verified patch** — reloaded Elementor working data matches the reviewed operations.

Cresco may still compute internal document hashes for history, rollback integrity and diagnostics. Those hashes are not exported to AI and are not patch freshness gates.

The user still reviews the visual result in Elementor and chooses Update/Publish.

## Lossless Elementor data

Element objects may contain current and future Elementor fields such as:

- `settings`
- `styles`
- `interactions`
- `editor_settings`
- classes / variables / Atomic data
- addon-specific element metadata

Cresco preserves unknown safe fields. This is deliberate: an export → unchanged AI round trip must not erase configuration simply because Cresco does not yet understand a newly introduced Elementor field.

## Validation and safety

- Maximum 1,000 operations per patch.
- `base.postId` must match the requested Elementor document.
- Element IDs use safe identifier syntax.
- Duplicate IDs are rejected.
- Unsafe active markup, JavaScript URLs and inline event handlers are rejected.
- Keys resembling credentials, passwords, API keys, private keys, tokens, authorization data, nonces and secrets are rejected.
- Scoped patches cannot escape their current target.
- Native Elementor control metadata is used for semantic validation where available.
- Visual no-op and unsafe semantic operations are detected before apply.
- Reviewed operations are verified against reloaded Elementor working data after save.
- Published/private documents use Elementor working/autosave data for review; Cresco does not publish the post.
- Patch freshness checksums are deliberately not required.

## AI rules

1. Read `editableScope`, `elementStates`, `runtimeCapabilities`/`relevantCapabilities` and `instructions` from the export package first.
2. Return `base.postId`; do not emit checksum fields.
3. Preserve existing element IDs.
4. Prefer `update-setting` for small changes.
5. Use native Elementor controls before `custom_css`, including native responsive settings.
6. Prefer Container `gap`/responsive gap for sibling spacing instead of margin-based rhythm.
7. Use `custom_css` only for effects the exposed native controls cannot represent; never invent unused CSS variables as a substitute for Elementor settings.
8. Avoid no-op operations by comparing requested values with `elementStates.rawSettings` and `effectiveWithDefaults`.
9. Use `replace-element` only when a complete element replacement is intentional.
10. Preserve Dynamic Tags, globals, responsive settings, Atomic/V4 fields, classes, variables and unknown fields unless intentionally changing them.
11. Use names, options, units, ranges and conditions from the exact runtime capability catalog; do not invent Elementor control keys.
12. Prefer existing Elementor Kit/global design values.
13. Never return credentials, nonces, API keys, authentication data or executable JavaScript.
14. Return JSON only when the user asks for an importable Cresco patch.
