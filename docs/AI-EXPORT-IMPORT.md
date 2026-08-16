# AI Export & Import trong Cresco Layer 0.24

Tài liệu này dành cho người xây agent, prompt, automation hoặc debug pipeline **Elementor → ChatGPT/AI bên ngoài → Elementor**.

Từ 0.24, workflow chính không còn yêu cầu prompt thiết kế trong Elementor.

```text
Elementor
→ External AI Package
→ ChatGPT nhận design request trong chat
→ JSON result
→ Cresco normalize/validate/preview/apply
→ Elementor render
→ Fidelity verification
```

## 1. Mục tiêu

AI không nên nhận một JSON Elementor trần rồi tự đoán:

- widget/element nào đang được đăng ký;
- control nào tồn tại;
- control nào responsive;
- unit/option/range nào hợp lệ;
- Global Style nào đang được dùng;
- parent/sibling nào đang ảnh hưởng layout;
- breakpoint nào active;
- browser thực tế đang render geometry/style ra sao;
- output nào được phép sửa trong scope hiện tại.

Cresco Layer đóng gói các bằng chứng này thành package tự mô tả và kiểm lại result bằng runtime thật trước khi Elementor lưu.

## 2. Hai file export chính

### ZIP đầy đủ

Tên dạng:

```text
cresco-chatgpt-bundle-<target>.zip
```

Manifest:

```text
cresco-ai-bundle/v4
```

Khuyến nghị khi cần:

- screenshot/current preview;
- reference image;
- nhiều file context riêng dễ đọc;
- ChatGPT có khả năng đọc ZIP/file bundle.

### Single JSON

Tên dạng:

```text
cresco-chatgpt-package-<target>.json
```

Schema:

```text
cresco-external-ai-package/v1
```

Dùng khi muốn một file duy nhất hoặc môi trường AI không xử lý ZIP tốt.

## 3. Chọn scope export

### Selected element (`widget`)

Chỉ sửa element đang chọn.

Phù hợp với:

- heading;
- button;
- image;
- một widget form;
- một container khi chỉ muốn chỉnh settings của root.

Contract quan trọng:

- existing root ID phải giữ nguyên;
- context bên ngoài là read-only;
- children không tự động trở thành editable chỉ vì AI nhìn thấy chúng.

### Selected subtree (`subtree`)

Root và descendants trong subtree được phối hợp.

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

Phù hợp cho redesign một section/cluster có cấu trúc.

### Entire page (`document`)

Toàn document được export và document scope được phép chỉnh.

Đây là scope quyền cao nhất. Không bắt buộc chọn element trước khi export.

UI external 0.24 tập trung vào ba scope trên. Backend vẫn giữ `selection` cho workflow nâng cao/legacy contract.

## 4. Vì sao external export dùng Full Context?

Trước đây Smart Context có thể dùng task hint nhập trong Elementor để chỉ load detailed capability liên quan đến yêu cầu.

0.24 cố ý chuyển design prompt sang ChatGPT **sau khi file đã rời Elementor**.

Nếu vẫn chỉ export task-aware subset, tình huống sau có thể xảy ra:

```text
1. User export hero.
2. Package chỉ có capability của widget đang tồn tại.
3. Trong ChatGPT user mới yêu cầu thêm Icon List/Carousel/Form.
4. AI không có detailed control metadata của widget mới.
5. Model buộc phải đoán hoặc không thể hoàn thành chính xác.
```

Vì vậy external panel gọi REST export với:

```text
context=full
```

Full Context yêu cầu `ContextResolver` lấy detailed capability cho toàn bộ widget/element đã đăng ký trong runtime hiện tại.

Trade-off:

- file lớn hơn;
- export có thể chậm hơn;
- nhưng capability coverage tốt hơn;
- package tái sử dụng được cho nhiều yêu cầu hơn trong cùng trạng thái Elementor.

## 5. Exact Runtime

External export đồng thời ép:

```text
Exact Runtime profile
```

Exact Runtime dùng detailed catalog do Full Context cung cấp để enrich AI Context bằng runtime capability chính xác.

Contract chính có thể gồm:

```text
runtimeCapabilities
capabilityLock
siteDesignContext
taskRuntimeDiscovery
```

Trong external workflow, `taskRuntimeDiscovery` vẫn có thể tồn tại nhưng **không phải nguồn duy nhất** của capability. Full `widgetCatalog`/`elementCatalog` đã được điền chi tiết trước đó.

`capabilityLock` yêu cầu:

- không invent control;
- không invent responsive suffix;
- validate unit/option/range/condition;
- custom CSS chỉ là fallback nếu native control không đủ;
- không tự tạo Dynamic Tag/global reference mà runtime không chứng minh.

## 6. AI Package lõi

Server package:

```text
cresco-layer-ai-package/v2
```

Các vùng quan trọng:

```text
manifest
editableScope
document
elementContext
elementStates
siteContext
designSystem
layoutContext
registryIndex
controlRegistry
patchContract
widgetCatalog
elementCatalog
relevantCapabilities
dynamicTags
templates
assets
capabilities
audit
instructions
```

### `editableScope`

Đây là ACL của AI.

```text
mode
rootElementId
elementIds
editableElementIds
pageSettingsEditable
preserveChildrenOnRootReplace
```

AI có thể được cho context để hiểu layout nhưng không có nghĩa context đó editable.

### `document`

Giữ raw Elementor data thay vì chuyển sang Cresco document model riêng.

Mục tiêu là preserve:

- settings;
- responsive values;
- Dynamic Tags;
- global references;
- Atomic/V4 data;
- addon-specific metadata;
- unknown persisted fields.

### `elementStates`

Có thể chứa:

```text
rawSettings
defaultSettings
effectiveWithDefaults
globalReferences
responsiveOverrides
unknownPersistedSettings
```

Unknown persisted field là dữ liệu cần preserve, **không phải giấy phép để AI invent control mới**.

### `layoutContext`

Mô tả parent/child/container roles và responsive foundation để AI không sửa từng node tách rời.

## 7. Control Registry

Normalized schema:

```text
cresco-control-registry/v1
```

Mỗi control có thể mô tả:

```text
name
type
label
source
responsive
dynamic
units
options
range
min
max
step
condition
conditions
selectors
bind
propType
```

AI nên dùng `controlRegistry`/detailed runtime capability làm nguồn sự thật cho setting-level quyết định.

## 8. Visual Context và Fidelity Context

Từ 0.23, editor enrich context bằng:

```text
fidelityPolicy
visualContext
```

`visualContext` có thể chứa:

```text
cresco-fidelity-snapshot/v1
cresco-geometry-graph/v1
```

Browser preview cung cấp bằng chứng mà raw settings không nói hết:

- x/y/width/height thật;
- parent-relative position;
- previous/next sibling;
- flex/grid computed properties;
- padding/margin/gap thật;
- font-size/line-height/letter-spacing thật;
- color/background/border/radius/shadow;
- opacity/visibility;
- horizontal overflow.

Structured visual context vẫn có giá trị ngay cả khi raster screenshot không capture được.

## 9. External AI Package

Lớp ngoài cùng:

```text
cresco-external-ai-package/v1
```

Top-level:

```text
schema
packageId
createdAt
producer
workflow
target
instructionsForAI
resultContract
contextQuality
context
```

### `instructionsForAI`

Nói rõ:

- design request đến từ external chat;
- runtime/package là source of truth;
- không invent capability;
- preserve scope/IDs;
- dùng native controls/global values;
- trả delta nhỏ nhất;
- trả JSON sạch.

### `resultContract`

Đây là output contract mà external AI phải ưu tiên.

Không được giả định một schema duy nhất cho mọi scope.

## 10. External Exchange Policy

Schema:

```text
cresco-external-exchange-policy/v1
```

### Element/subtree

Preferred:

```text
cresco-ai-mutation/v3
```

Mode:

```text
semantic-target-mutation
```

V3 cho phép AI nói bằng design intent; Cresco chịu trách nhiệm lower về runtime-proven controls và cấp ID mới.

### Document

Preferred:

```text
cresco-layer-patch/v1
```

Mode:

```text
document-patch
```

Scope:

```json
{
  "mode": "document",
  "rootElementId": "",
  "elementIds": []
}
```

Document không phải một fake container root, nên semantic v3 không phải transport mặc định cho toàn trang.

## 11. Semantic mutation v3

Ví dụ element/subtree result:

```json
{
  "schema": "cresco-ai-mutation/v3",
  "intent": "edit",
  "target": {
    "postId": 42,
    "id": "abc1234",
    "scope": "subtree"
  },
  "designChanges": [
    {
      "elementId": "abc1234",
      "layoutIntent": {
        "direction": "column",
        "gap": "32px"
      },
      "styleIntent": {
        "borderRadius": "20px"
      }
    }
  ]
}
```

Cresco compile:

```text
v3
→ SemanticDesignCompiler
→ v2
→ AIMutationCompiler
→ internal patch v1
```

## 12. Document patch v1

Ví dụ chỉnh existing element ở document scope:

```json
{
  "schema": "cresco-layer-patch/v1",
  "base": { "postId": 42 },
  "scope": {
    "mode": "document",
    "rootElementId": "",
    "elementIds": []
  },
  "label": "Improve page spacing",
  "operations": [
    {
      "operation": "update-setting",
      "elementId": "abc1234",
      "setting": "padding",
      "value": {
        "unit": "px",
        "top": "64",
        "right": "32",
        "bottom": "64",
        "left": "32",
        "isLinked": false
      }
    }
  ]
}
```

### Top-level insert

```json
{
  "operation": "insert-element",
  "parentId": "",
  "position": 999999,
  "element": {
    "ref": "$new:cta",
    "elType": "container",
    "settings": {},
    "elements": []
  }
}
```

`InternalPatchCompiler` có thể cấp final ID cho inserted subtree khi AI dùng ref/không cung cấp ID hợp lệ.

### Replace document

`replace-document` là destructive. Chỉ dùng khi user yêu cầu rebuild toàn trang. Cresco không tự suy diễn một yêu cầu “cải thiện giao diện” thành full document replacement.

## 13. AI Bundle v4

ZIP có thể gồm:

```text
README-FOR-CHATGPT.md
cresco-package.json
elementor-context.json
output-contract.json
widget-guide.json
visual-context.json
current-preview.png
reference-<filename>
manifest.json
```

`README-FOR-CHATGPT.md` là entrypoint cho model.

`cresco-package.json.resultContract` có authority cao hơn template legacy nằm sâu trong context nếu có khác biệt.

## 14. Import normalizer

Backend nhận raw result string, không bắt UI phải tự biến mọi format thành patch trước.

Các schema hỗ trợ:

```text
cresco-ai-mutation/v3
cresco-ai-mutation/v2
cresco-layer-patch/v1
cresco-layer-ai-result/v1
```

Normalizer còn chịu được một số:

- markdown code fence;
- JSON wrapper phổ biến;
- prose quanh object khi có thể trích object an toàn.

Mục tiêu vẫn là yêu cầu AI trả JSON sạch.

## 15. ID allocation

AI không nên mint final Elementor ID cho node mới khi contract hỗ trợ ref.

Ví dụ:

```text
$new:hero
$new:title
$new:cta
```

Cresco dùng `ElementorIdGenerator` để cấp ID 7-character hex không va chạm với document hiện tại cho inserted subtree.

Existing IDs vẫn là authoritative identity và phải được giữ khi chỉnh element hiện có.

## 16. Runtime validation v2

Sau generic schema/security validation, Cresco chạy:

```text
cresco-layer-patch-validation/v2
```

Các gate:

### Registered control

Setting phải resolve về runtime control.

### Responsive capability

`*_mobile`, `*_tablet`... chỉ hợp lệ nếu base control responsive.

### Unit

Unit phải thuộc runtime `size_units` hoặc contract custom tương ứng.

### Option

Select/choose/radio phải nhận option hợp lệ.

### Range

Range kiểm theo unit đang được ghi; không áp range `px` cho `vw/custom` một cách sai nghĩa.

### Global reference

`__globals__` phải trỏ vào base control có thật.

### Unknown persisted fields

Existing unknown field được preserve khi unchanged. AI không được tạo/chỉnh unknown field như một control mới.

## 17. Semantic Guard

Runtime-valid chưa chắc semantic-valid.

Guard có thể phát hiện:

- no-op;
- custom CSS khi native control đã biểu đạt được;
- CSS variable khai báo nhưng không consume;
- structural change không phù hợp scope;
- giá trị nhìn hợp lệ nhưng không tạo visual effect mong muốn.

## 18. Import Preview

External UI gửi raw JSON đến:

```text
POST /documents/{postId}/preview
```

cùng:

```text
selectedElementId
expectedScope
```

Preview có thể trả:

```text
diff
semantic warnings
normalization report
auto-repair count
scope
```

Nút Apply chỉ bật khi exact result/scope đã preview hợp lệ.

Nếu result khai báo target ID khác element đang chọn, UI chặn sớm trước server preview.

## 19. Apply và persistence

Apply:

```text
POST /documents/{postId}/apply
```

Pipeline:

```text
normalize external result
→ compile internal patch
→ validate
→ semantic guard
→ write working Elementor document
→ read back
→ verify requested operations
```

Save request thành công không đồng nghĩa requested values đã persist đúng, nên read-back verification là bắt buộc.

Update/Publish cuối cùng vẫn thuộc người dùng trong Elementor.

## 20. Rendered Verification

Sau Apply, preview được refresh và verifier đọc DOM thực tế.

Có thể kiểm:

- layout;
- typography;
- colors;
- spacing;
- accessibility intent;
- touch target;
- overflow;
- structure.

## 21. Fidelity Gate

Default overall threshold:

```text
96 / 100
```

Category hiện có:

```text
geometry
spacing
typography
color
structure
quality
```

Blockers có thể gồm:

```text
missing-element
parent-drift
horizontal-overflow
invisible-target
invalid-geometry
no-verification-evidence
```

Không có evidence:

```text
overall = 0
gate = BLOCKED
```

Không được biến “không đo được” thành pass.

## 22. Responsive

Package chứa breakpoint config và persisted responsive overrides.

Fidelity snapshot 0.23/0.24 capture preview mode hiện tại, không giả định desktop geometry là mobile geometry.

Nếu cần kiểm nhiều breakpoint ở workflow hiện tại:

1. chuyển Elementor preview sang breakpoint;
2. verify/capture;
3. lặp lại breakpoint quan trọng.

Multi-breakpoint automatic matrix là phase sau.

## 23. Checklist cho external AI

Trước khi trả result, agent nên tự kiểm:

```text
[ ] Đọc cresco-package.json / resultContract
[ ] Đúng postId
[ ] Đúng target/scope
[ ] Widget/subtree dùng v3 khi contract yêu cầu
[ ] Document dùng patch v1 khi contract yêu cầu
[ ] Chỉ sửa editable scope
[ ] Control có bằng chứng runtime
[ ] Responsive suffix hợp lệ
[ ] Unit/option/range hợp lệ
[ ] Không phá Global Styles ngoài ý muốn
[ ] Giữ existing IDs
[ ] Node mới dùng ref nếu contract hỗ trợ
[ ] Preserve unknown persisted fields
[ ] Dùng layoutContext + visualContext
[ ] Ưu tiên delta nhỏ
[ ] Không xuất secret/nonces/API key
[ ] Không yêu cầu publish trực tiếp
```

## 24. Khi import bị reject

Không bypass validator. Dùng error làm feedback cho vòng sửa tiếp theo.

Nhóm lỗi thường gặp:

- unsupported control;
- responsive mismatch;
- unsupported unit/option/range;
- scope mismatch;
- target drift;
- unsafe value;
- semantic no-op;
- duplicate/invalid ID;
- fidelity blocked sau render.

## 25. Triết lý chung

Pipeline mong muốn không phải:

```text
AI mạnh hơn → hy vọng JSON đúng
```

Mà là:

```text
runtime evidence đầy đủ
+ external self-describing package
+ scope-aware output contract
+ AI suy luận bên ngoài Elementor
+ runtime validator
+ Elementor persistence
+ rendered evidence
+ Fidelity Gate
= external AI workflow đáng tin cậy
```
