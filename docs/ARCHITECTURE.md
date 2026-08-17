# Kiến trúc Cresco Layer

> **Tài liệu lịch sử:** nội dung gốc mô tả kiến trúc giai đoạn 0.5.x. Các nguyên tắc nền tảng vẫn hữu ích, nhưng một số chi tiết như `cresco-context-resolver/v1`, checksum scope và cách hiểu `context=full` đã thay đổi ở 0.24.3. Khi cần behavior hiện tại, ưu tiên `PROJECT_RULES.md`, `KIEN-TRUC-HE-THONG.md`, `EXPORT-RESILIENCE.md` và code/test trên `main`.

## Ranh giới sản phẩm

Elementor vẫn là:

- editor;
- renderer;
- responsive engine;
- history owner;
- persistence source of truth.

Cresco Layer là **intelligence + interchange + validation layer**. Cresco không tạo page document model thứ hai.

Pipeline tổng quát:

```text
Elementor working document
  -> runtime knowledge + live registries
  -> Cresco Context Resolver
  -> scoped capability/context export
  -> external AI / local agent
  -> validated Cresco result/patch
  -> preview + scope guard
  -> Elementor Document API
  -> read-back verification
  -> user review
  -> Elementor Update/Publish
```

## AI package v2

Transport contract:

```text
cresco-layer-ai-package/v2
```

Package có thể chứa:

- Elementor/Pro version;
- raw Elementor element data;
- editable scope;
- read-only parent/sibling context;
- page settings;
- active Kit/design system;
- active breakpoints;
- compact `registryIndex` của registered widget/element types;
- detailed control metadata theo context budget;
- default/options/ranges/units/conditions/selectors/responsive/dynamic metadata;
- Dynamic Tags metadata;
- capability coverage;
- dependency-aware Pro runtime signals;
- template/media metadata;
- audit data;
- provider-neutral AI instructions.

Secrets phải được redact trước khi package rời WordPress.

### Smart và Full

Thiết kế lịch sử:

- `smart` mở detail cho editable/context types và một số insertion candidate.
- `full` từng mở detail cho mọi registered type.

Implementation 0.24.3 đã thay đổi để tránh request quá nặng:

```text
Full registry awareness
+ bounded detailed hydration
+ Exact Runtime reuse/fetch phần thiếu
```

Do đó không dùng mô tả cũ “Full = hydrate mọi control stack” làm giả định runtime hiện tại.

## AI Context Resolver

Resolver nối full runtime knowledge với task/scoped AI package.

Mục tiêu:

```text
runtime rất lớn
→ giữ toàn bộ type index
→ chọn detailed capability cần thiết
→ xuất context nhỏ hơn, chính xác hơn
```

`capabilityCoverage` cho AI biết nguồn nào:

```text
complete
partial
unavailable
```

AI không được invent control chỉ vì một type xuất hiện trong `registryIndex`.

## Full Elementor Runtime Snapshot

Schema:

```text
cresco-elementor-snapshot/v1
```

Đây là artifact diagnostics/admin riêng, không nhúng vào mọi AI edit.

Snapshot dùng lazy/fault-isolated REST requests và giữ hai representation:

- `normalized` — ổn định, dễ đọc cho người/AI.
- `raw` — representation gần nhất với runtime/post/meta nhưng vẫn serializable và an toàn.

Coverage có thể bao gồm:

- environment;
- Elementor global options;
- features/experiments;
- breakpoints;
- active Kit;
- Dynamic Tags;
- Core/Pro runtime modules;
- registered widgets/elements;
- Elementor-owned records như documents/templates/popups/custom fonts/icons/code.

`SerializableSanitizer` redact secret và report unsupported runtime values thay vì stringify mù quáng.

## Scope model

Các scope chính:

```text
document
widget
subtree
selection
```

Ý nghĩa:

- `document` — toàn page/template + page settings.
- `widget` — selected element settings; descendants mặc định được preserve/read-only.
- `subtree` — selected root + descendants.
- `selection` — nhiều selected root rõ ràng, không tự lấy descendants nếu contract không nói.

Scope phải được server enforce, không dựa vào việc AI “tự giác”.

## Lossless element contract

Raw Elementor data là authoritative. Cresco không ép element vào một schema riêng làm mất field mới.

Quy tắc:

```text
unknown persisted field hiện có
→ preserve nếu không sửa

unknown field do AI tự invent
→ reject nếu runtime không chứng minh
```

`replace-element` chỉ hợp lệ khi complete replacement thật sự là mục tiêu. Widget scope phải preserve children khi contract yêu cầu.

## Capability Scanner

Scanner đọc registered widget/element managers của Elementor runtime thật.

Metadata có thể gồm:

- type/label/description;
- defaults;
- options;
- responsive/dynamic flags;
- units/ranges;
- selectors;
- conditions;
- render metadata;
- Atomic/V4 controls + props schema khi có.

Classic entries dùng `get_controls()`; Atomic/V4 dùng metadata mà runtime expose. Không giả định fixed Elementor Pro catalog.

## Editor-native exchange

Cresco tích hợp vào Elementor editor nhưng không thay editor.

Nguyên tắc:

- export/import vẫn scoped;
- target hiện tại phải được resolve đúng;
- backend vẫn validate `expectedScope`/target trước Preview/Apply;
- UI editor chỉ là entrypoint; authority cuối nằm ở runtime validation + persistence layer.

Từ 0.24, UX chính là **External AI Exchange** thay vì workflow “Edit with AI” cũ.

## Persistence và publication safety

Cresco không ghi trực tiếp `_elementor_data`.

```text
reviewed patch/result
→ Elementor Document API
→ working/autosave data
→ read-back verify
→ user quyết định Update/Publish
```

Apply của Cresco không đồng nghĩa publish.

## Security

- check capability theo target post;
- full snapshot yêu cầu `manage_options`;
- REST dùng WordPress nonce;
- redact credential/secret;
- reject executable/unsafe markup;
- operation count/depth phải bounded;
- reject duplicate ID/cyclic move;
- server-side scope sandboxing là bắt buộc.

## Quality invariants

Quality gate phải bảo vệ ít nhất:

- không direct-write Elementor document meta;
- package/schema contract còn tồn tại;
- Context Resolver và registry/capability split còn đúng;
- runtime discovery không gọi API sai signature;
- snapshot coverage trung thực;
- scope guard còn hoạt động;
- unknown data vẫn lossless;
- Classic + Atomic capability discovery có regression coverage;
- editor integration không vô tình biến mất;
- PHP/JS syntax và standalone validator/scope tests vẫn chạy.

## Quy tắc đọc tài liệu này

Tài liệu này hữu ích để hiểu **tư duy kiến trúc**, nhưng khi chi tiết version-specific mâu thuẫn với code hiện tại:

```text
code/runtime
→ current tests
→ PROJECT_RULES.md
→ docs 0.24+
→ tài liệu lịch sử này
```

đó là thứ tự ưu tiên.