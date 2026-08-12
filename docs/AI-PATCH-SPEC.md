# Cresco Layer AI Patch v1

Schema identifier: `cresco-layer-patch/v1`

## Required envelope

```json
{
  "schema": "cresco-layer-patch/v1",
  "base": {
    "postId": 123,
    "checksum": "sha256"
  },
  "label": "Human-readable change label",
  "operations": []
}
```

`base.checksum` must equal the checksum in the export package. A stale patch is rejected before preview/apply. The checksum represents the current Elementor working document/autosave for the current user when one exists.

## Operations

### update-setting

```json
{
  "operation": "update-setting",
  "elementId": "abc123",
  "setting": "title_color",
  "value": "#6d28d9"
}
```

### remove-setting

```json
{
  "operation": "remove-setting",
  "elementId": "abc123",
  "setting": "title_color"
}
```

### replace-settings

Replaces the settings object on one existing element. Use sparingly; targeted updates are safer.

```json
{
  "operation": "replace-settings",
  "elementId": "abc123",
  "settings": {}
}
```

### insert-element

`parentId` may be an empty string to insert at document root. New IDs must be unique.

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

Atomic fields such as `version`, `styles`, `interactions` and `editor_settings` are preserved when provided.

### remove-element

```json
{
  "operation": "remove-element",
  "elementId": "abc123"
}
```

### move-element

The applier prevents moving an element inside its own descendant.

```json
{
  "operation": "move-element",
  "elementId": "abc123",
  "parentId": "target456",
  "position": 2
}
```

### update-page-setting

```json
{
  "operation": "update-page-setting",
  "setting": "background_color",
  "value": "#ffffff"
}
```

### remove-page-setting

```json
{
  "operation": "remove-page-setting",
  "setting": "background_color"
}
```

## Validation and safety

- Maximum 1,000 operations per patch.
- Existing element IDs must use safe identifier syntax.
- Inserted subtrees may not contain duplicate IDs or IDs already present in the document.
- Moves into an element's own descendant are rejected.
- Keys resembling credentials, passwords, API keys, tokens, authorization data or nonces are rejected.
- Script/iframe/object/embed markup, JavaScript URLs and inline event-handler strings are rejected.
- Nested Atomic/V4 objects are preserved without forcing their internal keys into the classic Elementor setting-key grammar.

## AI rules

- Preserve existing element IDs.
- Generate new unique IDs only for inserted elements.
- Prefer existing Elementor Kit/global styles when the export makes them available.
- Respect the active Elementor breakpoints supplied by the export package.
- Use widget/control names from `widgetCatalog` where possible.
- Do not return credentials, nonces, API keys, authentication data or executable JavaScript.
- Keep the patch focused; do not replace the entire document when a small operation is sufficient.
- Cresco Layer does not publish a WordPress post from an AI patch. Published/private documents use Elementor working autosave data for review first; drafts remain drafts.
