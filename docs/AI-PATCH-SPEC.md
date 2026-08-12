# Cresco Layer AI Patch v1

Schema identifier: `cresco-layer-patch/v1`.

Cresco Layer 0.3 keeps the patch schema identifier stable while adding **semantic Elementor validation** and post-apply verification on top of scoped exchange, lossless element replacement and full-document replacement. Older document-level patches remain valid.

## Required envelope

```json
{
  "schema": "cresco-layer-patch/v1",
  "base": {
    "postId": 123,
    "checksum": "64-character-sha256"
  },
  "label": "Human-readable change label",
  "operations": []
}
```

`base.checksum` is the checksum of the Elementor working document/autosave included in the export package.

## Scoped widget / subtree / selection patches

Packages exported with `widget`, `subtree` or `selection` scope contain `editableScope`. Copy the exact scope identity and checksum into the AI patch:

```json
{
  "schema": "cresco-layer-patch/v1",
  "base": {
    "postId": 123,
    "checksum": "document-sha256"
  },
  "scope": {
    "mode": "subtree",
    "rootElementId": "abc123",
    "elementIds": ["abc123"],
    "checksum": "scope-sha256"
  },
  "label": "Upgrade hero",
  "operations": []
}
```

The scope checksum is calculated only from the exported target. If an unrelated footer changes while an AI is editing a hero subtree, the patch can still be previewed/applied as long as the hero scope itself is unchanged. If the hero changes, the patch is rejected as stale.

Scoped patches are sandboxed:

- element mutations must target an editable ID in the exported scope;
- new descendants may only be inserted below an editable parent;
- page-setting and full-document operations are rejected outside `document` scope;
- widget-only scope cannot insert or move children;
- editor-native import can additionally require the patch root to match the currently selected Elementor element.

## Native Elementor control policy

The current Elementor installation is the source of truth for controls. AI should use control names and metadata from `widgetCatalog`, `elementCatalog`, `relevantCapabilities` and `elementStates`.

For normal layout/style changes:

- prefer a native Elementor setting whenever the target element exposes one;
- use responsive suffixes only when the base control is responsive, for example `padding_tablet`, `padding_mobile`, `min_height_tablet` or `min_height_mobile`;
- obey the control's options, units, ranges and device support;
- do not invent setting keys;
- use `custom_css` only as a fallback for an effect that cannot be represented by the exposed native controls.

Cresco 0.3 semantically validates these rules before an AI patch can be applied. Existing persisted addon/future settings that are not currently described by the capability catalog can still be preserved and explicitly modified, but Cresco reports that native metadata validation is unavailable for them.

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

Moving into an element's own descendant is rejected. Scoped patches cannot move elements outside their exported editable scope.

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

A syntactically valid patch is not necessarily a useful patch. Cresco 0.3 analyzes operations before apply and reports whether they are likely to have an effective Elementor change.

The semantic guard currently detects cases including:

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

## Post-apply verification

After Elementor saves a reviewed patch, Cresco 0.3 reads working data back and verifies the requested operations. The apply response contains a `verification` summary with passed/failed operation counts and per-operation details.

This distinguishes:

- **accepted patch** — the request passed validation;
- **saved patch** — Elementor accepted the document save;
- **verified patch** — reloaded Elementor working data matches the reviewed operations.

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
- Element IDs use safe identifier syntax.
- Duplicate IDs are rejected.
- Unsafe active markup, JavaScript URLs and inline event handlers are rejected.
- Keys resembling credentials, passwords, API keys, private keys, tokens, authorization data, nonces and secrets are rejected.
- Scoped patches cannot escape their exported target.
- Native Elementor control metadata is used for semantic validation where available.
- Visual no-op and unsafe semantic operations are detected before apply.
- Reviewed operations are verified against reloaded Elementor working data after save.
- Published/private documents use Elementor working/autosave data for review; Cresco does not publish the post.

## AI rules

1. Read `editableScope`, `elementStates`, `relevantCapabilities` and `instructions` from the export package first.
2. Preserve existing element IDs.
3. Prefer `update-setting` for small changes.
4. Use native Elementor controls before `custom_css`, including native responsive settings.
5. Use `custom_css` only for effects the exposed native controls cannot represent; never invent unused CSS variables as a substitute for Elementor settings.
6. Avoid no-op operations by comparing requested values with `elementStates.rawSettings` and `effectiveWithDefaults`.
7. Use `replace-element` only when a complete element replacement is intentional.
8. Preserve Dynamic Tags, globals, responsive settings, Atomic/V4 fields, classes, variables and unknown fields unless intentionally changing them.
9. Use names, options, units, ranges and conditions from `widgetCatalog` / `elementCatalog`; do not invent Elementor control keys.
10. Prefer existing Elementor Kit/global design values.
11. Never return credentials, nonces, API keys, authentication data or executable JavaScript.
12. Return JSON only when the user asks for an importable Cresco patch.
