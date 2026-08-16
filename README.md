# Cresco Layer

**Lớp trí tuệ, giao tiếp AI an toàn và kiểm chứng độ trung thực hiển thị cho Elementor + Elementor Pro.**

Cresco Layer giữ **Elementor là nguồn sự thật duy nhất** cho cấu trúc trang, control, responsive, render, history và persistence. Plugin không tạo page builder thứ hai. Nhiệm vụ của Cresco Layer là biến Elementor đang chạy thành một ngôn ngữ mà AI có thể đọc, chỉnh sửa và kiểm chứng một cách có giới hạn.

Phiên bản hiện tại: **0.23.0 — Fidelity Foundation**.

## Mục tiêu sản phẩm

Cresco Layer giải quyết bốn vấn đề chính khi đưa AI vào quy trình Elementor:

1. **AI phải biết control thật đang tồn tại** thay vì đoán tên setting.
2. **AI chỉ được sửa đúng phạm vi đã export** thay vì có quyền sửa cả document.
3. **Import phải được kiểm tra bằng runtime Elementor thật** trước khi lưu.
4. **Kết quả render phải được đo và chấm điểm**, không chỉ kết luận rằng JSON đã hợp lệ.

Luồng mục tiêu của hệ thống:

```text
Elementor runtime
  → Runtime Discovery
  → Control Registry
  → AI Package
  → AI Patch / Semantic Mutation
  → Schema & Safety Validation
  → Runtime Capability Validation
  → Scope Validation
  → Elementor save
  → Rendered Verification
  → Fidelity Score & Verification Gate
```

## Yêu cầu hệ thống

- WordPress 6.6+
- PHP 8.1+
- Elementor
- Elementor Pro nếu sử dụng các integration chỉ có trong Pro
- Trình duyệt có thể truy cập Elementor preview iframe cùng origin để dùng Fidelity Engine

## Nguyên tắc kiến trúc

Elementor chịu trách nhiệm cho:

- editor và canvas;
- document model;
- widget/container registry;
- responsive controls;
- render frontend/editor;
- history;
- autosave/draft;
- Update/Publish cuối cùng.

Cresco Layer bổ sung:

- runtime discovery;
- normalized control registry;
- AI context/package;
- scope contract;
- semantic design reasoning;
- patch validation;
- runtime control validation;
- diff, preview, history và rollback;
- Site Settings engine;
- computed visual snapshot;
- geometry graph;
- fidelity scoring;
- rendered verification gate.

## Workflow AI trong Elementor

Trong Elementor Editor, chọn widget hoặc container rồi dùng công cụ Cresco để export/import.

Các scope chính:

- **Widget**: chỉ chỉnh element được chọn; khi replace phải giữ ID và bảo toàn children theo contract.
- **Subtree**: chỉnh root và các descendant được phép; không được thoát sang vùng khác của document.
- **Selection**: backend hỗ trợ danh sách ID được chọn rõ ràng.
- **Document**: dùng cho redesign toàn trang hoặc workflow cần thay toàn bộ document.

### Export

Package lõi dùng schema:

```text
cresco-layer-ai-package/v2
```

Package có thể chứa:

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

Trong **Exact Runtime**, Cresco đọc registry thật của Elementor và addon đang active. AI không cần dựa vào danh sách widget hard-code.

Từ 0.23.0, export trong Elementor Editor còn được enrich thêm:

```text
fidelityPolicy
visualContext
```

`visualContext.snapshot` được lấy trực tiếp từ preview iframe và chứa geometry/computed styles của breakpoint đang được xem.

> 0.23.0 chỉ tuyên bố snapshot chính xác cho **breakpoint hiện tại của Elementor preview**. Hệ thống không tự bịa số đo cho breakpoint chưa được capture.

## Normalized Control Registry

Schema:

```text
cresco-control-registry/v1
```

Registry chuẩn hóa metadata Elementor thành contract AI dễ xử lý hơn:

- tên control;
- type;
- source;
- responsive;
- Dynamic Tag support;
- units;
- options;
- range/min/max/step;
- condition/conditions;
- selectors;
- Atomic binding/prop type.

Khi AI gửi patch, server kiểm tra lại control với runtime đang chạy. Một setting không tồn tại, responsive suffix sai, unit sai, option sai hoặc range sai sẽ bị từ chối trước khi persistence.

## Patch contract

Transport schema hiện tại vẫn là:

```text
cresco-layer-patch/v1
```

Giữ schema này giúp tương thích với workflow hiện có. Lớp validation mới được mô tả bằng:

```text
cresco-layer-patch-validation/v2
```

Các operation gồm:

```text
update-setting
remove-setting
replace-settings
replace-element
insert-element
remove-element
move-element
update-page-setting
remove-page-setting
replace-document
```

Cresco ưu tiên operation nhỏ nhất có thể. `update-setting` tốt hơn `replace-element` nếu chỉ cần sửa một control.

## Runtime fail-closed validation

Import không chỉ kiểm JSON. Khi Elementor runtime khả dụng, Cresco kiểm tra:

- target element có tồn tại;
- operation có nằm trong scope;
- control có được đăng ký;
- responsive suffix có hợp lệ với control đó;
- unit có được hỗ trợ;
- option có hợp lệ;
- numeric range có hợp lệ cho unit đang dùng;
- global reference có trỏ vào control hợp lệ;
- giá trị có chứa nội dung active/unsafe;
- replacement có giữ ID khi contract yêu cầu.

Unknown field đã tồn tại trong Elementor được bảo toàn theo hướng **lossless**; AI không được tự phát minh unknown field mới để lách control registry.

## Fidelity Foundation 0.23

0.23 bổ sung lớp đo kết quả render thật.

### Computed Visual Snapshot

Schema:

```text
cresco-fidelity-snapshot/v1
```

Mỗi element được capture có thể gồm:

- `x`, `y`, `width`, `height`, `right`, `bottom`;
- vị trí tương đối so với parent;
- client/scroll dimensions;
- display/flex/grid/overflow/z-index/transform;
- margin/padding/gap;
- font family, size, weight, line-height, letter-spacing, alignment;
- color, background, border, radius, shadow, opacity, visibility;
- dấu hiệu horizontal/vertical overflow;
- trạng thái hidden hoặc geometry không hợp lệ.

### Geometry Graph

Schema:

```text
cresco-geometry-graph/v1
```

Graph mô tả:

- parent → child;
- thứ tự sibling;
- previous/next sibling;
- child IDs;
- geometry của từng node.

Điều này giúp AI hiểu **quan hệ gây ra layout**, thay vì chỉ nhìn từng setting rời rạc.

### Fidelity Score

Schema:

```text
cresco-fidelity-report/v1
```

Score mặc định gồm sáu nhóm:

| Nhóm | Trọng số |
|---|---:|
| Geometry | 30% |
| Spacing | 18% |
| Typography | 18% |
| Color | 12% |
| Structure | 12% |
| Quality | 10% |

Threshold mặc định: **96/100**.

Ngoài overall score, mỗi category còn có floor riêng. Vì vậy một trang không thể dùng điểm Color cao để che một lỗi Structure nghiêm trọng.

### Verification Gate

Schema:

```text
cresco-fidelity-gate/v1
```

Gate bị block khi có lỗi như:

- element cần kiểm tra bị mất;
- parent relationship drift;
- horizontal overflow ngoài ý muốn;
- target trở thành invisible;
- geometry không hợp lệ;
- không có đủ rendered evidence để kiểm chứng.

Trường hợp **không có bằng chứng** được xử lý fail-closed, không được tính là 100 điểm.

### Giới hạn của Fidelity Foundation

Fidelity trong Cresco có nghĩa:

> **Cấu trúc xác định + sai số render nằm trong tolerance đã định nghĩa.**

Nó **không** tuyên bố mọi pixel raster phải giống tuyệt đối trên mọi browser, OS, font rasterizer hoặc GPU. Đây là chủ đích kỹ thuật để tránh một cam kết không thể kiểm chứng ổn định.

## Rendered Verification

`assets/visual-verification.js` tiếp tục kiểm tra semantic intent trên DOM render thực tế, ví dụ:

- flex direction/alignment/wrap;
- gap, width, min-height, max-width;
- border radius, opacity;
- font size/line-height/letter-spacing/font-weight;
- text/background color;
- ARIA/decorative semantics;
- touch target;
- horizontal overflow.

0.23 bổ sung `fidelity-verification.js` để tự chấm điểm kết quả sau apply và hiển thị:

```text
Fidelity Score: xx.x/100
Gate PASS | BLOCKED
geometry · spacing · typography · color · structure · quality
```

Verification có retry ngắn để chờ preview iframe render lại sau khi Elementor save.

## Elementor Site Settings Engine

Cresco có pipeline riêng cho global design system:

```text
cresco-site-settings/v1
  → validate
  → resolve active Kit
  → capability discovery
  → snapshot
  → adapt
  → diff
  → write through Elementor Kit API
  → read-back verify
  → rollback nếu verification fail
```

Site Settings và element patch là hai miền tách biệt:

- `cresco-site-settings/v1`: global colors, fonts, Theme Style, layout foundation, Hello/optional surfaces;
- `cresco-layer-patch/v1`: page/container/widget operations.

Cresco không ghi Kit data bằng đường tắt khi có thể dùng Elementor Document/Kit API.

## Responsive Foundation

Nguyên tắc thiết kế:

- `clamp()` cho scaling liên tục khi phù hợp;
- breakpoint cho structural change;
- không tạo override chỉ vì có breakpoint;
- nested container không nên nhân đôi global page gutter;
- responsive suffix chỉ được dùng khi runtime control thật sự responsive.

Fidelity snapshot 0.23 đo **device mode đang hiển thị**. Multi-breakpoint automated capture là bước tiếp theo của Fidelity Engine.

## Global style và Dynamic Tags

Khi export, Cresco cố gắng bảo toàn:

- `__globals__`;
- Global Colors / Fonts;
- Dynamic Tags;
- Atomic/V4 data;
- addon-specific persisted fields;
- classes, variables, interactions và metadata Elementor không thuộc Cresco.

AI được hướng dẫn tái sử dụng global style/design system thay vì tạo local value gần giống.

## History và rollback

Patch apply ghi lại history trước thay đổi trong giới hạn storage budget. Khi snapshot đủ điều kiện restore, Cresco có thể rollback qua Elementor Document API.

Rollback cũng được ghi thành history entry mới để không tạo dead-end.

## An toàn

Các lớp bảo vệ chính:

```text
Serializable Sanitizer
→ Patch Schema Validator
→ Sensitive-key Guard
→ Active Markup Guard
→ Runtime Control Validator
→ Semantic Patch Guard
→ Scope Enforcement
→ Elementor Persistence
→ Post-Apply Verification
→ Rendered Fidelity Gate
```

Các key giống credential/API token/password/nonce không được phép đi qua AI patch contract.

## Local AI và deterministic skills

Repo có Local AI manager/context compiler/planner contract và deterministic widget skill runtime. Skill runtime dùng runtime control metadata để tạo command có thể kiểm chứng; nó không cần chatbot cho việc resolve command deterministic.

## Admin tools

Cresco Layer có các công cụ quản trị phục vụ inspection và vận hành:

- Elementor Configuration & Full Runtime Snapshot;
- runtime widget/element catalog;
- lazy control detail;
- export snapshot;
- Site Settings preview/import/verify;
- patch history/rollback;
- design-standard analysis;
- Local AI settings.

## Kiểm thử

Quality command:

```bash
npm run check
```

Các nhóm kiểm thử bao gồm:

- PHP syntax;
- architecture invariants;
- capability scanner/runtime discovery;
- context resolver;
- patch validator/runtime control contract;
- semantic guard;
- mutation compiler/import;
- Site Settings;
- editor JS contracts;
- visual verification;
- Fidelity Foundation.

Fidelity có contract tests riêng:

```text
tests/js/fidelity-foundation-contract-test.mjs
tests/php/fidelity-policy-contract-test.php
```

## Tài liệu tiếng Việt

Bộ tài liệu chuẩn mới nằm trong [`docs/README.md`](docs/README.md):

- [Kiến trúc hệ thống](docs/KIEN-TRUC-HE-THONG.md)
- [AI Export & Import](docs/AI-EXPORT-IMPORT.md)
- [Fidelity Engine](docs/FIDELITY-ENGINE.md)
- [Elementor Site Settings](docs/SITE-SETTINGS.md)
- [Schema Reference](docs/SCHEMA-REFERENCE.md)
- [Phát triển & Kiểm thử](docs/PHAT-TRIEN-KIEM-THU.md)

Các tài liệu kỹ thuật tiếng Anh cũ trong `docs/` vẫn được giữ để tham chiếu lịch sử/implementation; bộ tài liệu trên là tài liệu tiếng Việt ưu tiên cho 0.23+.

## Lộ trình gần

Sau Fidelity Foundation 0.23, hướng nâng cấp tiếp theo là:

1. capture tự động nhiều breakpoint;
2. reference-image/raster diff có tolerance;
3. property ownership/dependency graph;
4. correction planner;
5. vòng lặp AI → preview → score → correction có budget;
6. rollback candidate khi score giảm;
7. golden regression corpus cho các mẫu Elementor thực tế.

Mục tiêu cuối cùng không phải để AI “đoán CSS hay hơn”, mà để Cresco Layer trở thành **protocol + compiler + verifier** giúp AI điều khiển Elementor bằng dữ liệu runtime thật và bằng chứng render có thể đo được.
