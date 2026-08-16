# AI Export & Import trong Cresco Layer

Tài liệu này dành cho người xây agent, prompt, automation hoặc debug pipeline AI ↔ Elementor.

## 1. Mục tiêu

AI không nên nhận một JSON Elementor trần rồi tự đoán:

- widget nào đang được đăng ký;
- control nào tồn tại;
- control nào responsive;
- unit nào hợp lệ;
- global style nào đang được dùng;
- parent/sibling nào đang ảnh hưởng layout;
- giá trị cuối cùng browser đang render ra sao.

Cresco Layer đóng gói các thông tin này thành AI Package và yêu cầu AI trả về patch có scope rõ ràng.

## 2. Chọn scope export

### Widget

Dùng khi chỉ muốn sửa một element.

Phù hợp với:

- heading;
- button;
- image;
- một container đơn;
- một widget form cụ thể.

Quy tắc:

- root ID phải giữ nguyên;
- context ngoài scope là read-only;
- nếu replace element, phải preserve children khi contract yêu cầu.

### Subtree

Dùng khi element root có nhiều descendant cần phối hợp.

Ví dụ:

```text
Hero container
├─ content container
│  ├─ heading
│  ├─ paragraph
│  └─ buttons
└─ media container
   └─ image
```

Subtree cho phép AI sửa cấu trúc bên trong phạm vi này nhưng không được tác động footer/header/section khác.

### Selection

Dùng cho nhiều root cụ thể. Đây là scope chính xác, không có nghĩa mọi descendant của mọi root tự động có quyền như subtree trừ khi contract nói vậy.

### Document

Dùng khi redesign cả trang hoặc import page-level result. Đây là scope quyền cao nhất và phải được dùng có chủ đích.

## 3. AI Package v2

Schema:

```text
cresco-layer-ai-package/v2
```

### `manifest`

Chứa thông tin môi trường và document:

- plugin version;
- Elementor/Pro version;
- post ID;
- working post/autosave ID;
- document type;
- export time;
- scope;
- context profile.

### `editableScope`

Đây là quyền chỉnh sửa của AI.

Các trường quan trọng:

```text
mode
rootElementId
elementIds
editableElementIds
pageSettingsEditable
preserveChildrenOnRootReplace
```

Agent phải xem đây là ACL của patch.

### `document`

Chứa raw Elementor content và page settings trong phạm vi export.

Cresco không chuyển toàn bộ document sang một model riêng vì điều đó dễ làm mất field mới/addon-specific field.

### `elementContext`

Parent/sibling/context có thể được export để AI hiểu layout nhưng không mặc nhiên editable.

### `elementStates`

Mỗi element có thể có:

```text
rawSettings
defaultSettings
effectiveWithDefaults
globalReferences
responsiveOverrides
unknownPersistedSettings
```

`unknownPersistedSettings` không phải giấy phép để AI tạo setting lạ. Nó là dữ liệu cần preserve losslessly.

### `layoutContext`

Mô tả các vai trò container, responsive foundation và relationship cần thiết để AI không sửa từng element tách rời.

### `registryIndex`

Danh sách compact các widget/element type đang được đăng ký. Index không nhất thiết có detailed control metadata.

### `widgetCatalog` / `elementCatalog`

Detailed runtime capability cho các type liên quan đến task.

### `controlRegistry`

Schema:

```text
cresco-control-registry/v1
```

Đây là dạng normalized, ưu tiên cho agent khi cần quyết định setting nào được phép viết.

### `patchContract`

Mô tả transport schema và các validation rule server sẽ áp dụng.

### `designSystem`

Active Elementor Kit settings liên quan đến global design system.

### `dynamicTags`

Runtime-discovered Dynamic Tags. Agent không nên tự tạo tag syntax nếu runtime không chứng minh nó tồn tại.

### `templates` và `assets`

Catalog read-only hỗ trợ task, ví dụ media attachment hoặc Elementor template liên quan.

## 4. Exact Runtime

Exact Runtime có mục tiêu **không cho AI đoán control**.

Ở editor, Cresco lazy-load detailed capability cho:

- element đang sửa;
- context liên quan;
- construction candidates cần thiết;
- widget được phát hiện theo task hint/registry metadata.

Contract chính:

```text
runtimeCapabilities
capabilityLock
siteDesignContext
taskRuntimeDiscovery
```

`capabilityLock` yêu cầu:

- không invent control;
- không invent responsive suffix;
- validate units/options/ranges/conditions;
- custom CSS chỉ là fallback khi native control không thể biểu đạt yêu cầu.

## 5. Visual Context từ 0.23

Trong Elementor Editor, `fidelity-export.js` enrich package bằng:

```text
fidelityPolicy
visualContext
```

`visualContext` dùng schema:

```text
cresco-visual-context/v1
```

Nó chứa `cresco-fidelity-snapshot/v1` của preview hiện tại.

AI nên dùng visual context để trả lời các câu hỏi mà raw setting không đủ:

- container thực tế rộng bao nhiêu pixel;
- child đang lệch so với parent bao nhiêu;
- gap cuối cùng là bao nhiêu;
- overflow có xảy ra không;
- font-size/line-height cuối cùng browser tính ra sao;
- parent/child/sibling relationship thật trong DOM render.

## 6. Quy tắc cho AI khi sinh patch

### 6.1 Chỉ dùng control đã chứng minh

Sai:

```json
{
  "setting": "my_magic_padding",
  "value": "32px"
}
```

nếu registry không có control đó.

Đúng:

- tìm control native tương ứng;
- kiểm responsive flag;
- kiểm unit;
- tạo operation nhỏ nhất cần thiết.

### 6.2 Ưu tiên `update-setting`

Nếu chỉ đổi một giá trị, không replace cả element.

Lợi ích:

- ít rủi ro làm mất unknown field;
- diff dễ review;
- rollback nhỏ;
- validator có thể kiểm chính xác control/value.

### 6.3 Giữ ID

Existing element ID là identity quan trọng cho:

- scope;
- editor state;
- CSS selectors;
- history;
- references.

Chỉ inserted element mới được sinh ID mới.

### 6.4 Preserve global references

Nếu element đang dùng global color/font, không phá link thành local value trừ khi task thực sự yêu cầu.

### 6.5 Không bù lỗi bằng offset ngẫu nhiên

Khi visual snapshot cho thấy child lệch, AI phải tìm owner hợp lý:

- parent alignment;
- gap;
- padding;
- width/flex basis;
- responsive override;
- margin thật sự có chủ đích.

Không nên thêm transform/negative margin chỉ để khớp một frame nếu native layout control mới là nguyên nhân.

## 7. Patch schema

Transport schema:

```text
cresco-layer-patch/v1
```

Ví dụ tối giản:

```json
{
  "schema": "cresco-layer-patch/v1",
  "base": {
    "postId": 42
  },
  "scope": {
    "mode": "widget",
    "rootElementId": "abc123",
    "elementIds": ["abc123"]
  },
  "label": "AI Import",
  "operations": [
    {
      "operation": "update-setting",
      "elementId": "abc123",
      "setting": "title_color",
      "value": "#ffffff"
    }
  ]
}
```

## 8. Runtime validation v2

Sau generic schema validation, khi Elementor runtime có sẵn Cresco chạy:

```text
cresco-layer-patch-validation/v2
```

### Registered control

Setting phải resolve được về control runtime.

### Responsive capability

Ví dụ `padding_mobile` chỉ hợp lệ nếu base control `padding` hỗ trợ responsive.

### Unit

Nếu control chỉ hỗ trợ:

```text
px
%
em
rem
```

AI không được ghi một unit khác trừ khi control runtime cho phép `custom` hoặc unit đó.

### Options

Select/choose control phải nhận value nằm trong options runtime.

### Range

Range được kiểm theo unit đang ghi. Không áp range `px` cho `vw` hoặc raw custom CSS expression.

### Global references

`__globals__` chỉ được update cho control có thật.

### Unknown persisted values

Nếu existing document đã có unknown field, replacement có thể giữ nguyên nó. AI không được thay đổi/tạo unknown field như một control mới.

## 9. Semantic Guard

Runtime control hợp lệ vẫn chưa chắc thay đổi có ý nghĩa.

Semantic guard có thể phát hiện:

- no-op;
- custom CSS dùng property mà native control đã có;
- CSS variable khai báo nhưng không được consume;
- value không tương thích với mục tiêu semantic;
- cấu trúc không phù hợp với scope/role.

## 10. Preview

Trước apply, Cresco có thể trả:

```text
valid
scope
diff
diffDetails
auditBefore
auditAfter
willUseAutosave
```

Agent hoặc UI nên dùng preview để cho người dùng biết chính xác cái gì sẽ đổi.

## 11. Apply và persistence

Apply đi qua Elementor Document API.

Sau save, Cresco reload working data để xác minh. Điều này tách hai khái niệm:

```text
save request thành công
≠
requested values đã được persist đúng
```

## 12. Rendered verification

Sau apply, browser verifier đọc preview DOM và so semantic intent với computed result.

Các check gồm layout, typography, color, accessibility và UX quality.

## 13. Fidelity Gate

0.23 tự chấm các rendered checks bằng policy.

Default overall threshold:

```text
96 / 100
```

Ngoài threshold còn có category floors và blocking issue.

Một report không có check/evidence được đánh:

```text
overall = 0
gate = BLOCKED
rule = no-verification-evidence
```

Điều này tránh false-positive.

## 14. Responsive

### Export

Raw package có breakpoint config và responsive overrides.

### Visual snapshot

0.23 capture device mode hiện tại của Elementor preview.

Không nên suy diễn snapshot desktop thành mobile geometry.

Workflow hiện tại nếu cần kiểm kỹ nhiều breakpoint:

1. chuyển Elementor preview sang breakpoint cần kiểm;
2. export/capture hoặc verify ở breakpoint đó;
3. lặp lại cho các breakpoint active quan trọng.

Auto matrix sẽ được triển khai ở phase tiếp theo.

## 15. Prompt/agent checklist

Trước khi sinh patch, agent nên tự kiểm:

```text
[ ] Đúng postId
[ ] Đúng scope
[ ] Chỉ sửa editable IDs
[ ] Control tồn tại trong detailed runtime capability/controlRegistry
[ ] Responsive suffix hợp lệ
[ ] Unit hợp lệ
[ ] Option/range hợp lệ
[ ] Không phá global references ngoài ý muốn
[ ] Giữ existing IDs
[ ] Preserve unknown persisted fields
[ ] Tận dụng layoutContext + visualContext
[ ] Dùng operation nhỏ nhất
[ ] Không xuất secret/nonces/API key
[ ] Không yêu cầu publish trực tiếp
```

## 16. Khi import bị reject

Đừng tắt validator. Hãy dùng error để sửa patch.

Các nhóm lỗi thường gặp:

- `unsupported control`: AI dùng key không tồn tại;
- responsive mismatch: dùng suffix trên non-responsive control;
- unsupported unit/option/range;
- scope mismatch;
- target drift;
- unsafe value;
- semantic no-op;
- fidelity blocked sau render.

Mỗi nhóm cần sửa nguyên nhân, không bypass guard.

## 17. Triết lý chung

Pipeline tốt không phải là:

```text
AI thông minh hơn → hy vọng patch đúng
```

Mà là:

```text
AI được cấp bằng chứng đúng
+ contract rõ
+ quyền hạn nhỏ
+ validator runtime
+ render evidence
+ score/gate
= kết quả đáng tin cậy hơn
```
