# Cresco Layer — Safe AI Exchange Boundary

Version introduced: **0.15.1**

This document explains the boundary between **what already exists in Elementor** and **what the AI is allowed to change**.

## Why this boundary exists

An AI export contains a large amount of current Elementor state so the model can understand the page: containers, widgets, settings, responsive values, Site Settings and live runtime controls. That exported state is **context**, not a template that should be copied back into Elementor.

Before 0.15.1, a small request such as “add a marquee below this hero” could be implemented by copying the whole exported subtree, appending one new element and returning `replace-element` for the root. If any exported value had been shortened or redacted, that placeholder could then overwrite the real live value. A deep serializer limit made this visible as literal `[TRUNCATED]` values and broken widget controls.

0.15.1 separates those responsibilities explicitly.

## Mental model

```text
LIVE ELEMENTOR
    |
    | export
    v
READ-ONLY SOURCE CONTEXT
    |
    | AI reasons about existing UI
    v
DELTA MUTATION OUTPUT
    |
    | Cresco validates scope + runtime controls
    v
PREVIEW
    |
    v
APPLY TO LIVE ELEMENTOR
```

The important rule is:

> **Existing Elementor data may be read by AI, but it must not be echoed back merely to preserve it. Only the intended change should be returned.**

## Read-only source context

The export policy identifies these paths as read-only reference data:

- `document.content`
- `elementContext`
- `elementStates`

The AI may use these fields to understand current content, IDs, hierarchy and effective settings. It must not copy the existing subtree into the mutation result when the task only adds or changes a small part.

## Delta-first mutation model

For normal design work, prefer the smallest operation that expresses the requested change.

| User intent | Preferred operation | Why |
|---|---|---|
| Add a new section/widget/container | `insert-element` | Existing content is untouched |
| Change one native widget/container control | `update-setting` | Only that setting changes |
| Remove one setting override | `remove-setting` | Elementor can fall back to inherited/default value |
| Reorder/move an existing element | `move-element` | Existing element data is preserved |
| Delete an explicitly requested element | `remove-element` | Narrow destructive action |
| Fully rebuild an exact target | `replace-element` | Only for explicit complete rebuilds |

`replace-element`, `replace-settings`, `remove-element` and `replace-document` are classified as destructive operations. They are not the default way to make incremental visual changes.

### Example: add a marquee below an existing hero

Correct:

```json
{
  "schema": "cresco-layer-patch/v1",
  "base": { "postId": 3 },
  "scope": {
    "mode": "subtree",
    "rootElementId": "3ed4781",
    "elementIds": ["3ed4781"]
  },
  "operations": [
    {
      "operation": "insert-element",
      "parentId": "3ed4781",
      "position": 3,
      "element": {
        "id": "new0001",
        "elType": "container",
        "settings": {},
        "elements": []
      }
    }
  ]
}
```

Incorrect for an incremental addition:

```text
copy current 3ed4781 subtree
+ append marquee
+ replace-element 3ed4781
```

The second pattern unnecessarily takes ownership of every existing heading, icon, form setting, dynamic value and unknown persisted field.

## Full-tree AI results are protected

`cresco-layer-ai-result/v1` can still describe a complete Elementor tree. On an **empty construction target**, Cresco may compile that result to `replace-element` automatically.

On a target that already contains settings, children or other persisted data, Cresco 0.15.1 refuses an implicit full-tree replacement. A complete rebuild must be explicit:

```json
{
  "schema": "cresco-layer-ai-result/v1",
  "intent": "replace-target",
  "target": {
    "postId": 3,
    "id": "3ed4781"
  },
  "element": {
    "id": "3ed4781",
    "elType": "container",
    "settings": {},
    "elements": []
  }
}
```

Use this only when the user actually requested a complete rebuild of that exact target.

## Serialization integrity

The export sanitizer still removes secrets and protects against pathological payloads, but the normal recursion ceiling is now high enough for deeply nested Elementor structures. The old depth of 14 could reach a legitimate nested widget/control value and replace it with `[TRUNCATED]`; the ceiling is now 64.

The safety boundary is deliberately two-sided:

1. **Export side:** normal Elementor trees should remain lossless instead of being truncated at ordinary nesting depths.
2. **Import side:** if a hard-limit placeholder is ever present anyway, Cresco blocks it before Preview or Apply.

Blocked serialization markers:

```text
[TRUNCATED]
[REDACTED]
__cresco_truncated__
```

This means a placeholder from an AI context package cannot become a real Elementor setting value.

## Native-control policy remains authoritative

This safety change does not relax runtime validation.

AI-generated changes should still follow these rules:

- Use live Elementor native controls whenever the runtime exposes them.
- Use Container `gap`, row gap or column gap for sibling rhythm instead of margin chains.
- Do not invent widget settings or responsive suffixes.
- Respect allowed units, options, ranges and control value shapes.
- Use `custom_css` only when no native control in the current runtime can express the required result.

## Export contract added in 0.15.1

Every REST AI export is decorated with:

```json
{
  "exchangePolicy": {
    "schema": "cresco-layer-ai-exchange-policy/v1",
    "separation": "source-context-is-read-only; mutation-output-is-delta-only-by-default",
    "sourceContext": {
      "mode": "read-only-reference",
      "paths": [
        "document.content",
        "elementContext",
        "elementStates"
      ],
      "echoBack": false,
      "copyExistingSubtreeIntoMutation": false
    },
    "mutationOutput": {
      "schema": "cresco-layer-patch/v1",
      "strategy": "delta-first",
      "preferredOperations": [
        "insert-element",
        "update-setting",
        "remove-setting",
        "move-element"
      ]
    }
  }
}
```

The same contract is also appended to the human-readable AI instructions inside the export.

## Result

The intended workflow is now:

```text
Select target in Elementor
        |
        v
Export for AI
        |
        v
AI reads current source context
        |
        | returns only requested change
        v
Delta patch
        |
        v
Placeholder guard
        |
        v
Runtime + scope validation
        |
        v
Preview
        |
        v
Apply
```

A change in one new component no longer needs to take ownership of the complete existing section. This reduces accidental overwrites, duplicate reconstruction and corruption from incomplete exported context while retaining explicit full-rebuild support when that is actually the requested operation.
