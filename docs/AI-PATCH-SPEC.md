# Cresco Layer AI Patch v1

Schema identifier: `cresco-layer-patch/v1`.

Cresco Layer 0.2 keeps the patch schema identifier stable while adding optional **scoped exchange**, lossless element replacement and full-document replacement. Older document-level patches remain valid.

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

Inserted element IDs must be unique across the working document.

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
- Published/private documents use Elementor working/autosave data for review; Cresco does not publish the post.

## AI rules

1. Read `editableScope` and `instructions` from the export package first.
2. Preserve existing element IDs.
3. Prefer `update-setting` for small changes.
4. Use `replace-element` only when a complete element replacement is intentional.
5. Preserve Dynamic Tags, globals, responsive settings, Atomic/V4 fields, classes, variables and unknown fields unless intentionally changing them.
6. Use names, options, units, ranges and conditions from `widgetCatalog` / `elementCatalog`; do not invent Elementor control keys.
7. Prefer existing Elementor Kit/global design values.
8. Never return credentials, nonces, API keys, authentication data or executable JavaScript.
9. Return JSON only when the user asks for an importable Cresco patch.
