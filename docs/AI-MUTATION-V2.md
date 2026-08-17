# `cresco-ai-mutation/v2`

`cresco-ai-mutation/v2` là external-AI response contract ưu tiên trong Cresco Layer 0.18. Contract này giữ AI tập trung vào **semantic intent**, còn Cresco compile intent thành internal scoped `cresco-layer-patch/v1`.

> Ở workflow external mới hơn, `cresco-ai-mutation/v3` được ưu tiên cho element/subtree design work; v2 vẫn được giữ để tương thích và là tầng trung gian quan trọng trong compiler.

## Nguyên tắc cốt lõi

- Existing Elementor IDs là authoritative.
- Final ID của node mới do Cresco cấp.
- `widgetIntent` phải trỏ tới type đã được active Elementor runtime chứng minh.
- Exact Elementor `settings` vẫn qua `SemanticPatchGuard`.
- Visual edit không được âm thầm sửa protected behavioral/external settings.
- Add operation không được thoát selected editable scope.
- Full rebuild phải explicit và chỉ trong target.

## Add

```json
{
  "schema": "cresco-ai-mutation/v2",
  "intent": "add",
  "target": {
    "postId": 3,
    "id": "abc1234"
  },
  "placement": {
    "mode": "inside-end"
  },
  "nodes": [
    {
      "ref": "$new:headline",
      "role": "headline",
      "widgetIntent": "heading",
      "content": {
        "text": "A healthier home starts here",
        "semanticLevel": "h2"
      },
      "settings": {}
    }
  ]
}
```

Placement mode hỗ trợ ở 0.18:

```text
inside-start
inside-end
```

Nếu exported placement context đánh dấu `before-target`/`after-target` là `requiresWiderScope`, phải export/select parent Container thay vì ghi ra ngoài scope.

Nested nodes dùng `children` hoặc `elements` để tương thích:

```json
{
  "ref": "$new:card",
  "widgetIntent": "container",
  "settings": {},
  "children": [
    {
      "ref": "$new:title",
      "widgetIntent": "heading",
      "content": {
        "text": "Card title",
        "semanticLevel": "h3"
      }
    }
  ]
}
```

## Edit

Edit dùng exact existing element ID và exact runtime setting key.

```json
{
  "schema": "cresco-ai-mutation/v2",
  "intent": "edit",
  "target": {
    "postId": 3,
    "id": "abc1234"
  },
  "changes": [
    {
      "elementId": "def5678",
      "setting": "title",
      "value": "Updated heading"
    },
    {
      "elementId": "def5678",
      "setting": "typography_font_size",
      "value": {
        "unit": "px",
        "size": 48,
        "sizes": []
      }
    }
  ]
}
```

Remove một setting:

```json
{
  "elementId": "def5678",
  "setting": "margin",
  "remove": true
}
```

Không invent responsive suffix. Chỉ dùng `emittableKeys` đã export cho control/runtime thật.

## Protected behavioral edits

Generic visual mutation reject setting liên quan external/behavioral configuration như:

- form webhook/email routing;
- redirects;
- payments;
- query/template sources;
- code-like controls.

Chỉ explicit user request mới có thể opt-in:

```json
{
  "allowBehavioralChanges": true
}
```

Flag này **không bypass runtime hoặc semantic validation**.

## Move

```json
{
  "schema": "cresco-ai-mutation/v2",
  "intent": "move",
  "target": {
    "postId": 3,
    "id": "abc1234"
  },
  "elementId": "def5678",
  "placement": {
    "parentId": "abc1234",
    "position": 1
  }
}
```

Element được move và destination parent đều phải nằm trong exported editable scope.

## Remove

```json
{
  "schema": "cresco-ai-mutation/v2",
  "intent": "remove",
  "target": {
    "postId": 3,
    "id": "abc1234"
  },
  "elementIds": ["def5678"]
}
```

Selected root không được xóa qua narrow semantic contract này.

## Rebuild

```json
{
  "schema": "cresco-ai-mutation/v2",
  "intent": "rebuild",
  "target": {
    "postId": 3,
    "id": "abc1234"
  },
  "nodes": [
    {
      "widgetIntent": "container",
      "settings": {},
      "children": []
    }
  ]
}
```

Rebuild yêu cầu đúng một root. Root `elType` phải khớp live selected target; nếu target là widget thì widget type cũng phải giữ. Với structural redesign, nên chọn Container.

## Content shortcuts

Compiler có semantic content layer nhỏ, trong khi exact `settings` vẫn dùng được.

Ví dụ:

- heading-like: `content.text` → `title`, `content.semanticLevel` → `header_size`.
- text-editor-like: `content.html` / `content.text` → `editor`.
- button-like: `content.text` → `text`, `content.url` → `link`.
- image-like: `content.image` → `image`.
- icon-like: `content.icon` → `selected_icon`.

Explicit `settings` có ưu tiên cao hơn shortcut và vẫn phải khớp active runtime catalog.

## Chính sách ID

Với node mới, ưu tiên temporary reference:

```json
{
  "ref": "$new:cta-primary",
  "widgetIntent": "button"
}
```

Ref phải unique trong một answer. Cresco cấp final collision-free Elementor IDs từ current working document và xóa `ref` trước persistence. Duplicate ref → fail-closed, không merge hai node.

## Compilation và validation

```text
cresco-ai-mutation/v2
  -> AIMutationCompiler
  -> ElementorIdGenerator
  -> MutationNormalizer
  -> cresco-layer-patch/v1
  -> PatchValidator
  -> SemanticPatchGuard
  -> Preview / Apply / Verify
```

Semantic contract không phải validation bypass. Nếu widget/control/unit/option/responsive key/value không được live runtime hỗ trợ, mutation phải bị reject.