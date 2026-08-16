# Kiến trúc hệ thống Cresco Layer

Tài liệu này mô tả kiến trúc chuẩn của Cresco Layer 0.23.

## 1. Vai trò của Cresco Layer

Cresco Layer không phải page builder và không thay thế Elementor document model. Nó là lớp **intelligence + exchange + verification** nằm giữa Elementor và AI.

```text
Người dùng
   ↓
Elementor Editor
   ↓
Cresco Layer
   ├─ Runtime Discovery
   ├─ AI Context / Package
   ├─ Semantic Design
   ├─ Patch Validation
   ├─ Site Settings Engine
   ├─ History / Rollback
   └─ Fidelity Engine
   ↓
Elementor Document / Kit API
   ↓
Elementor renderer
```

Nguyên tắc quan trọng nhất: **Elementor vẫn là source of truth**.

## 2. Các miền chính

### 2.1 AI Exchange

Thư mục chính:

```text
includes/AI/
```

Các thành phần quan trọng:

- `PackageBuilder.php`: xây AI package từ document/scope đã chọn.
- `ElementLocator.php`: resolve element và phạm vi chỉnh sửa.
- `CapabilityScanner.php`: đọc widget/element/control từ runtime Elementor.
- `ContextResolver.php`: chọn capability cần thiết theo context profile.
- `ControlRegistry.php`: chuẩn hóa metadata control thành contract AI.
- `PatchValidator.php`: schema/safety validation cấp JSON.
- `PatchCapabilityValidator.php`: kiểm patch với runtime control thật.
- `SemanticPatchGuard.php`: kiểm logic semantic và các pattern có nguy cơ no-op/sai control.
- `PatchApplier.php`: preview/apply qua Elementor document API.
- `PatchHistory.php`: lưu audit/history/rollback snapshot trong budget.
- `Diff.php`: tóm tắt và chi tiết thay đổi.
- `FidelityPolicy.php`: policy server-side cho Fidelity Foundation.

### 2.2 Elementor Runtime Discovery

Thư mục:

```text
includes/Elementor/
```

Nhiệm vụ:

- đọc registered widgets/elements;
- đọc breakpoint runtime;
- đọc active Kit;
- đọc Dynamic Tags;
- đọc module/dependency state;
- tạo runtime snapshot phục vụ inspection/debug;
- hỗ trợ classic controls và Atomic/V4 metadata khi runtime cung cấp.

Cresco không dùng một danh sách widget cố định làm nguồn sự thật. Danh sách hard-code chỉ có thể đóng vai trò hint/candidate; capability cuối cùng phải được chứng minh bởi runtime.

### 2.3 Site Settings Engine

Thư mục:

```text
includes/SiteSettings/
```

Đây là pipeline riêng cho Elementor Kit/Global Settings. Nó không dùng chung element patch để tránh trộn hai miền dữ liệu.

Các lớp chính:

```text
Contract/
Discovery/
Adapter/
Gateway/
Diff/
Verify/
Validation/
Migration/
Registry/
Support/
```

Luồng:

```text
semantic spec
→ validate
→ capability discovery
→ snapshot active Kit
→ adapter map semantic path → Elementor control
→ diff
→ save bằng Kit API
→ read-back verify
→ rollback nếu cần
→ cache invalidation
```

### 2.4 Fidelity Foundation

Các file chính:

```text
includes/AI/FidelityPolicy.php
assets/fidelity-engine.js
assets/fidelity-export.js
assets/fidelity-verification.js
assets/visual-verification.js
```

Fidelity chạy ở browser vì geometry/computed style chỉ có ý nghĩa sau khi Elementor preview đã render.

Nó cung cấp:

- computed visual snapshot;
- geometry graph;
- scoring;
- category floors;
- blocking rules;
- verification gate;
- automatic post-apply rendered verification.

## 3. Luồng Export

### 3.1 Server package

`PackageBuilder` đọc document đang làm việc, không mặc định chỉ đọc bản published.

Luồng khái quát:

```text
postId
→ Elementor documents manager
→ main document / autosave
→ elements + page settings
→ resolve scope
→ layout context
→ context resolver
→ relevant runtime capabilities
→ control registry
→ AI package v2
→ SerializableSanitizer
```

### 3.2 Context profile

Cresco có profile context để kiểm soát kích thước package.

- `smart`: chỉ tải detailed capability cần cho editable/context/insertion candidates.
- `full`: tải rộng hơn khi cần construction/redesign lớn.
- Exact Runtime trong editor tiếp tục enrich package bằng capability detail thật theo task.

### 3.3 Browser enrichment

Trong Elementor Editor, chuỗi asset được nạp theo dependency để đảm bảo export cuối cùng có đủ context.

Khái quát:

```text
editor.js
→ exact-runtime-export.js
→ fidelity-engine.js
→ fidelity-export.js
→ ai-context-v3.js
→ external-ai-intelligence.js
→ design-intelligence.js
→ semantic design/reasoning
→ AI bundle
```

`fidelity-export.js` không thay đổi schema transport của package. Nó enrich thêm:

```text
fidelityPolicy
visualContext
capabilities.computedVisualSnapshot
capabilities.geometryGraph
capabilities.fidelityReport
capabilities.fidelityVerificationGate
```

## 4. Luồng Import

### 4.1 Patch validation nhiều tầng

```text
AI output
→ result normalization
→ patch schema validation
→ unsafe/sensitive key validation
→ runtime capability validation
→ semantic guard
→ scope enforcement
→ preview diff/audit
→ apply
→ read-back verification
→ rendered verification
→ fidelity gate
```

Mỗi tầng giải quyết một loại lỗi khác nhau.

### 4.2 PatchValidator

`PatchValidator` chịu trách nhiệm cho contract JSON chung:

- schema đúng;
- postId hợp lệ;
- scope hợp lệ;
- operation hợp lệ;
- element ID hợp lệ;
- setting key hợp lệ;
- depth/size giới hạn;
- chặn sensitive setting;
- chặn active HTML/JavaScript pattern nguy hiểm;
- giữ unknown safe element fields khi replacement hợp lệ.

### 4.3 PatchCapabilityValidator

Khi Elementor runtime đang chạy, patch tiếp tục được đối chiếu với capability thật:

- element/widget type có tồn tại;
- control có tồn tại;
- responsive suffix chỉ dùng cho responsive control;
- unit nằm trong allowed size units;
- option nằm trong allowed options;
- range đúng với unit;
- `__globals__` trỏ vào control có thật;
- unknown persisted setting chỉ được bảo toàn, không được dùng như control mới.

### 4.4 Scope enforcement

Một patch không có scope không được mặc nhiên có quyền sửa toàn page.

Document-wide permission phải được khai báo rõ ràng. Với widget/subtree/selection, operation phải nằm trong editable IDs hoặc descendant được phép insert.

### 4.5 Persistence

Cresco dùng Elementor Document API để save. Với published document, workflow có thể làm việc trên autosave/working document để người dùng vẫn giữ quyền Update/Publish cuối cùng.

Cresco không coi `save()` thành công là bằng chứng cuối cùng. Sau save, dữ liệu được reload và kiểm tra lại.

## 5. Lossless preservation

Elementor và addon có thể thêm field mới mà Cresco chưa biết. Vì vậy kiến trúc phải phân biệt:

```text
unknown persisted field
≠
unknown field do AI tự phát minh
```

Quy tắc:

- persisted unknown field hiện có: preserve nếu không sửa;
- AI tự tạo unknown setting: reject khi runtime validation chứng minh control không tồn tại;
- Atomic/V4/addon metadata: preserve nếu không phải mục tiêu chỉnh sửa.

Đây là nền tảng của forward compatibility.

## 6. Global Styles và effective values

Elementor setting có thể đến từ nhiều nguồn:

```text
raw local setting
→ responsive override
→ global reference
→ control default
→ parent/layout influence
→ browser computed result
```

Cresco hiện lưu cả raw/default/global reference và từ 0.23 có thêm computed rendered evidence.

Mục tiêu kiến trúc dài hạn là property ownership graph đầy đủ để xác định chính xác source nào đang sở hữu một pixel/style cuối cùng.

## 7. Fidelity architecture

### 7.1 Vì sao chạy trong browser

Server không có `getBoundingClientRect()` hoặc `getComputedStyle()`. Một setting `width: 50%` chỉ có thể biết số pixel cuối cùng khi parent, viewport, font, CSS và renderer đã tham gia.

Fidelity Engine do đó đọc preview iframe thật.

### 7.2 Snapshot

Mỗi element được capture ở bốn nhóm chính:

```text
geometry
layout + spacing
typography + visual
quality
```

Kèm quan hệ parent/children/sibling để tạo geometry graph.

### 7.3 Score

Score dùng weighted category thay vì một pixel diff duy nhất. Lý do:

- structure drift nghiêm trọng hơn khác biệt màu rất nhỏ;
- geometry/spacing ảnh hưởng bố cục nhiều hơn một property phụ;
- quality phải có blocking rule riêng;
- category floor ngăn một nhóm điểm cao che lỗi nghiêm trọng ở nhóm khác.

### 7.4 Gate

Gate pass khi đồng thời:

- overall ≥ threshold;
- không có blocking issue;
- không category nào thấp hơn floor tương ứng;
- có rendered evidence.

Không có evidence → blocked.

## 8. Asset dependency và fetch wrappers

Một số module enrich workflow bằng cách wrap `window.fetch`. Vì vậy thứ tự enqueue là contract kiến trúc, không phải chi tiết trang trí.

Mỗi wrapper phải:

- chỉ intercept endpoint của chính nó;
- forward mọi request khác nguyên vẹn;
- clone response trước khi đọc body;
- giữ status/statusText/headers cần thiết;
- fail soft ở lớp enrichment nếu lỗi đó không được phép làm mất response gốc;
- fail closed ở lớp safety/validation khi độ chính xác yêu cầu chặn.

## 9. Admin architecture

Admin screen phục vụ inspection/operations, không thay thế Elementor UI.

Các nhóm chức năng gồm:

- runtime catalog;
- full Elementor snapshot;
- Site Settings console;
- design standard;
- history/rollback;
- Local AI settings.

Nguyên tắc UI: nếu Elementor đã có editor tốt cho một giá trị, Cresco không tạo editor cạnh tranh. Cresco tập trung vào inspect, preview, sync, verify và automation contract.

## 10. Invariants cần giữ

1. Không ghi `_elementor_data` trực tiếp.
2. Không dùng `eval`, shell execution hoặc cơ chế thực thi code động.
3. Scope phải được xác định trước persistence.
4. Runtime capability là authoritative khi có thể đọc được.
5. Unknown persisted data phải được preserve losslessly.
6. Site Settings đi qua Kit/Document API.
7. Save phải được verify bằng read-back khi workflow yêu cầu độ chính xác.
8. Render fidelity phải dựa vào preview DOM thật.
9. Không có evidence không được pass.
10. Người dùng giữ quyền Update/Publish cuối cùng.

## 11. Hướng mở rộng kiến trúc

Các bước tiếp theo sau 0.23:

```text
multi-breakpoint snapshot matrix
→ raster/reference diff
→ property ownership graph
→ correction planner
→ bounded correction loop
→ regression corpus
```

Khi triển khai correction loop, mỗi iteration phải chứng minh score tăng. Candidate làm score giảm phải bị reject/rollback thay vì tiếp tục sửa ngẫu nhiên.
