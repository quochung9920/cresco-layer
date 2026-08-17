# Cresco Layer AI Patch v1

Schema:

```text
cresco-layer-patch/v1
```

Cresco giữ schema identifier ổn định nhưng dùng **checksum-free AI patch contract**. AI patch xác định WordPress post + editable Elementor scope; Cresco kiểm target/scope/runtime ở Preview/Apply. Checksum không phải một phần bắt buộc của AI exchange contract.

## Envelope bắt buộc

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

`base.postId` bắt buộc và phải khớp document đang edit. Không cần document checksum. Patch cũ còn checksum vẫn có thể được nhận, nhưng validator bỏ field đó và không dùng làm apply precondition.

## Scoped patch: widget / subtree / selection

Với export có `editableScope`, patch chỉ copy identity của scope:

```json
{
  "schema": "cresco-layer-patch/v1",
  "base": { "postId": 123 },
  "scope": {
    "mode": "subtree",
    "rootElementId": "abc123",
    "elementIds": ["abc123"]
  },
  "label": "Upgrade hero",
  "operations": []
}
```

Không có freshness checksum phải refresh. Scope vẫn bị sandbox ở server:

- `postId` phải đúng document;
- editor import có thể yêu cầu root khớp selected Elementor element;
- target phải còn tồn tại lúc Preview/Apply;
- mutation phải nằm trong editable IDs;
- descendant mới chỉ insert dưới editable parent;
- page-setting/full-document operations bị reject ngoài `document` scope;
- widget-only scope không insert/move children.

## Native Elementor control policy

Active Elementor installation là source of truth. AI đọc control từ Exact Runtime, `runtimeCapabilities`, `widgetCatalog`, `elementCatalog`, `relevantCapabilities`, `elementStates`.

Với layout/style thông thường:

- ưu tiên native setting;
- responsive suffix chỉ dùng khi base control responsive;
- tuân unit/option/range/device support;
- không invent setting key;
- ưu tiên parent Container `gap`/responsive gap cho sibling rhythm;
- `custom_css` chỉ là fallback khi native control không biểu đạt được.

Unknown persisted setting của addon/future Elementor có thể được preserve losslessly. Việc nó tồn tại không có nghĩa AI được tạo một unknown setting mới.

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

Đây là operation ưu tiên cho thay đổi nhỏ vì setting không nhắc tới được giữ nguyên.

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

Thay toàn bộ persisted `settings` object của một existing element. Dùng rất hạn chế.

```json
{
  "operation": "replace-settings",
  "elementId": "abc123",
  "settings": {}
}
```

Semantic guard phải reject replacement làm rơi global references hoặc unknown persisted settings một cách âm thầm.

### `replace-element`

Thay hoàn chỉnh một Elementor element object nhưng vẫn bảo toàn safe unknown fields.

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

Replacement ID phải bằng `elementId`. Trong `widget` scope, children hiện có luôn được preserve. Muốn redesign descendants, dùng subtree scope.

### `insert-element`

`parentId` chỉ được rỗng ở document scope.

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

ID mới phải unique trong working document; settings node mới được đối chiếu runtime capability.

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

Không được move element vào descendant của chính nó. Scoped patch không được move ra ngoài editable scope.

### `update-page-setting`

```json
{
  "operation": "update-page-setting",
  "setting": "background_color",
  "value": "#ffffff"
}
```

Chỉ document scope.

### `remove-page-setting`

```json
{
  "operation": "remove-page-setting",
  "setting": "background_color"
}
```

Chỉ document scope.

### `replace-document`

Dành cho full page rebuild có chủ đích; không hợp lệ ở widget/subtree/selection.

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

Cresco validate full tree, reject duplicate IDs và vẫn persist qua Elementor Document API, không ghi trực tiếp `_elementor_data`.

## Effective-change validation

Patch syntactically valid chưa chắc có tác dụng. Semantic guard phát hiện các case như:

- `update-setting` bằng đúng persisted value hiện tại;
- remove setting vốn không tồn tại;
- responsive suffix trên non-responsive control;
- unit/option/range không hợp lệ;
- setting mới không có trong capability catalog;
- destructive replacement làm mất global/unknown fields;
- custom CSS khai báo synthetic variable nhưng không consume.

Ví dụ bị reject:

```css
selector {
  --padding-top: 40px;
  --min-height: auto;
}
```

vì variable không được dùng bằng `var(...)`. Nếu Elementor có native padding/min-height control, phải dùng native setting.

Custom CSS trùng chức năng native control được đánh dấu warning/fallback để reviewer có thể chuyển về native control.

## Preview, Apply và Rollback

Preview resolve patch với **current Elementor working document**:

```text
validate post/scope/target
→ validate operation boundaries
→ diff + semantic audit
→ user review
```

Không reject chỉ vì hash của một export cũ đã đổi.

Sau save, Cresco đọc lại working data và tạo `verification` summary.

Ba mức cần phân biệt:

```text
accepted patch  = qua validation
saved patch     = Elementor save thành công
verified patch  = read-back khớp reviewed operations
```

Internal hashes vẫn có thể dùng cho history/rollback/diagnostics nhưng không phải AI freshness gate.

## Lossless Elementor data

Element object có thể chứa field hiện tại/tương lai:

```text
settings
styles
interactions
editor_settings
classes / variables / Atomic data
addon-specific metadata
```

Cresco preserve unknown safe fields. Export → unchanged round trip không được xóa config chỉ vì Cresco chưa hiểu field mới.

## Safety limits

- Tối đa 1,000 operations/patch.
- `base.postId` phải đúng document.
- Element ID phải dùng safe identifier syntax.
- Reject duplicate IDs.
- Reject active markup, JavaScript URL và inline event handlers nguy hiểm.
- Reject key giống credential/password/API key/private key/token/authorization/nonce/secret.
- Scoped patch không được escape target.
- Dùng native runtime metadata cho semantic validation khi có.
- Phát hiện visual no-op/unsafe semantic operation trước Apply.
- Verify reviewed operation bằng reloaded Elementor working data.
- Published/private document dùng working/autosave để review; Cresco không tự publish.
- Không yêu cầu patch freshness checksum.

## Rule cho AI

1. Đọc `editableScope`, `elementStates`, `runtimeCapabilities`/`relevantCapabilities`, `instructions` trước.
2. Trả `base.postId`; không emit checksum field nếu contract không yêu cầu.
3. Preserve existing IDs.
4. Ưu tiên `update-setting` cho thay đổi nhỏ.
5. Native controls trước `custom_css`, kể cả responsive.
6. Ưu tiên Container `gap` cho sibling spacing.
7. Chỉ dùng `custom_css` khi native controls không đủ; không invent unused CSS variables.
8. Tránh no-op bằng cách so với `rawSettings` và `effectiveWithDefaults`.
9. `replace-element` chỉ khi complete replacement là chủ đích.
10. Preserve Dynamic Tags, globals, responsive settings, Atomic/V4, classes, variables và unknown fields nếu không chủ đích thay.
11. Không invent control key; tuân option/unit/range/condition của exact runtime.
12. Ưu tiên Elementor Kit/global design values hiện có.
13. Không trả credential, nonce, API key, auth data hoặc executable JavaScript.
14. Khi user cần file importable, trả JSON thuần theo contract.