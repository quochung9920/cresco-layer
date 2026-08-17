# Cresco Layer

**Cầu nối file-based, lossless và runtime-aware giữa Elementor ↔ ChatGPT/AI bên ngoài.**

Phiên bản hiện tại: **0.24.3 — External AI Bridge**.

Cresco Layer không cố biến Elementor thành một chatbot và không thay Elementor bằng page builder riêng. Plugin giữ Elementor là nguồn sự thật cho document, widget, control, breakpoint, Global Styles, render, history và persistence; sau đó đóng gói những dữ liệu này thành một package mà AI bên ngoài có thể hiểu và trả kết quả về an toàn.

## Workflow chính

```text
Elementor
→ Cresco Export for ChatGPT
→ ZIP/JSON package tự mô tả
→ người dùng upload sang ChatGPT
→ người dùng mô tả giao diện muốn tạo/chỉnh trong ChatGPT
→ ChatGPT trả file JSON
→ Cresco Import AI Result
→ Preview + Validation
→ Apply vào Elementor working document
→ Read-back verification
→ Rendered verification
→ Fidelity Score / Gate
```

**Prompt thiết kế không cần nhập trong Elementor.** Yêu cầu thiết kế được viết trong cuộc trò chuyện với ChatGPT sau khi package đã được export.

## Trải nghiệm người dùng

Trong Elementor Editor, Cresco cung cấp một panel chính với đúng hai tab:

- **Export to ChatGPT**
- **Import AI Result**

### Export to ChatGPT

Người dùng chọn một scope:

- **Selected element** — chỉ element đang chọn;
- **Selected subtree** — root và descendants thuộc subtree;
- **Entire page** — toàn document, không bắt buộc chọn element.

Sau đó bấm:

```text
Export for ChatGPT
```

Cresco tự chuẩn bị Exact Runtime, Full Context, Global Styles, breakpoints, control metadata, layout context và visual evidence. Không có bước “Prepare prompt” hay “Create/Edit bằng AI” trong Elementor.

Hai dạng export:

```text
cresco-chatgpt-bundle-<target>.zip     # khuyến nghị
cresco-chatgpt-package-<target>.json   # single JSON fallback
```

### Làm việc trong ChatGPT

Upload file Cresco và nói yêu cầu, ví dụ:

```text
Đây là package Cresco Layer của hero hiện tại.
Hãy thiết kế lại theo phong cách luxury tối giản,
giữ nguyên nội dung chính và tối ưu responsive.
Trả file JSON để tôi import lại vào Cresco Layer.
```

Package đã chứa contract kỹ thuật nên người dùng không phải giải thích setting key, unit, responsive suffix, ID hay control availability.

### Import AI Result

Contract kết quả phụ thuộc scope:

```text
Selected element/subtree → cresco-ai-mutation/v3 (ưu tiên)
Entire page              → cresco-layer-patch/v1 + scope.mode=document (ưu tiên)
```

Lý do: semantic mutation v3 được thiết kế quanh một Elementor root target cụ thể. Document là scope rộng hơn, nên Cresco dùng document patch contract để edit/insert/move toàn trang mà không giả lập một container root không tồn tại.

Cresco vẫn nhận các format tương thích khi phù hợp:

```text
cresco-ai-mutation/v3
cresco-ai-mutation/v2
cresco-layer-patch/v1
cresco-layer-ai-result/v1
```

Trong tab **Import AI Result**, người dùng kéo thả file, bấm **Preview Changes**, xem diff/warning, rồi mới bấm **Apply to Elementor**.

## External AI Package

Single JSON package dùng schema:

```text
cresco-external-ai-package/v1
```

Các phần chính:

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

`context` giữ Cresco AI Context v3 để AI bên ngoài có dữ liệu runtime đầy đủ. External Exchange Policy chuẩn hóa `resultContract` ở lớp ngoài cùng, vì vậy ChatGPT phải ưu tiên contract này nếu một template legacy nằm sâu trong context có khác biệt.

## External Exchange Policy

Schema:

```text
cresco-external-exchange-policy/v1
```

Policy quyết định output contract theo scope:

```text
widget/subtree → semantic-target-mutation → cresco-ai-mutation/v3
document       → document-patch          → cresco-layer-patch/v1
```

Với document scope, Cresco cho phép top-level `insert-element` bằng `parentId: ""`. Element mới có thể dùng temporary ref như `$new:hero`; Cresco sẽ cấp ID Elementor collision-free khi import. `replace-document` chỉ nên dùng khi người dùng thực sự yêu cầu rebuild toàn trang và toàn bộ content tree phải hợp lệ.

## Full Bundle v4

ZIP bundle dùng schema:

```text
cresco-ai-bundle/v4
```

Bundle có thể chứa:

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

`README-FOR-CHATGPT.md` là entrypoint rõ ràng cho AI; `cresco-package.json` là package machine-readable chính.

## Vì sao external export dùng Full Context?

Prompt thiết kế được viết sau khi file đã rời Elementor, nên Cresco không thể dựa vào task hint nhập trước để biết chính xác widget nào ChatGPT sẽ cần. External export vì vậy vẫn dùng **Full Context profile**, nhưng “Full” hiện có nghĩa là **full runtime awareness**, không phải hydrate toàn bộ control stack của mọi widget trong một REST request.

Cụ thể, Cresco giữ **toàn bộ registry index** để AI biết những widget/element type nào thực sự tồn tại trong runtime, đồng thời hydrate detailed capability theo một budget an toàn. Target, editable types và read-only context types là bắt buộc và không được silently truncate; các construction candidate quan trọng được ưu tiên. Dynamic Tags và module runtime dùng metadata/summary compact để tránh làm package hoặc PHP request phình không kiểm soát.

Exact Runtime ở browser reuse những capability detail server đã cung cấp và chỉ fetch phần còn thiếu trong bounded worker/fetch budget. Capability bắt buộc của target/context vẫn **fail-closed**; capability construction phụ có thể fail-soft và được ghi rõ trong coverage report. Cách này giữ độ chính xác runtime mà tránh double-enrich và giảm rủi ro timeout/out-of-memory khi Elementor/Pro/addon đăng ký catalog lớn.

## Runtime Control Registry

Cresco đọc registry Elementor thật đang chạy thay vì dùng danh sách widget hard-code.

Normalized registry:

```text
cresco-control-registry/v1
```

Metadata có thể bao gồm:

- control name/type/label;
- default;
- responsive support;
- Dynamic Tag support;
- units;
- select/choose options;
- min/max/range/step;
- condition/conditions;
- selectors;
- Atomic/V4 binding metadata.

AI được yêu cầu dùng registry này làm nguồn sự thật và import sẽ kiểm lại một lần nữa bằng runtime server-side.

## Scope safety

Cresco tách rõ dữ liệu AI được **đọc** và dữ liệu AI được **sửa**.

Các scope hỗ trợ:

```text
widget
subtree
selection
document
```

UI external 0.24 tập trung vào widget, subtree và document. Selection vẫn tồn tại ở backend contract cho workflow nâng cao.

Khi import, `expectedScope` được gửi cùng result. Patch/semantic mutation không được thoát phạm vi đó.

Nếu result khai báo `target.id` khác element người dùng đang chọn, UI chặn trước preview để giảm nguy cơ áp kết quả vào sai target.

## Runtime fail-closed validation

Một output AI không được apply chỉ vì JSON parse thành công. Cresco kiểm tra:

- target/scope;
- registered control;
- responsive capability;
- unit;
- option;
- numeric range;
- global reference;
- unsafe value;
- structure/preservation contract;
- semantic no-op hoặc visual no-op có thể phát hiện.

Unknown persisted Elementor fields được preserve losslessly khi không phải mục tiêu chỉnh sửa; AI không được tạo unknown field mới để lách runtime registry.

## Elementor persistence

Cresco không ghi một document model cạnh tranh với Elementor.

Patch cuối cùng được compile về operation nội bộ, preview, rồi apply qua lớp document/persistence của Elementor. Sau save Cresco đọc lại để xác minh requested values đã thực sự tồn tại.

Update/Publish cuối cùng vẫn do người dùng quyết định trong Elementor.

## Fidelity Foundation

Cresco 0.23+ đo kết quả render thật thay vì chỉ kiểm JSON/settings.

Các schema chính:

```text
cresco-fidelity-policy/v1
cresco-fidelity-snapshot/v1
cresco-geometry-graph/v1
cresco-fidelity-report/v1
cresco-fidelity-gate/v1
```

Snapshot có thể ghi lại geometry, parent-relative position, sibling graph, flex/grid, spacing, typography, color, border, radius, shadow, opacity, visibility và overflow từ browser preview thật.

Default Fidelity threshold hiện là `96/100`. Các blocker như missing element, parent drift, horizontal overflow, invisible target, invalid geometry hoặc không có verification evidence có thể chặn Gate dù tổng điểm cao.

Cresco không tuyên bố pixel-perfect tuyệt đối giữa mọi browser/OS/font stack. Mục tiêu là **deterministic structural fidelity + bounded rendered error**.

## Elementor Site Settings

Cresco còn có Site Settings Engine riêng cho global design system:

```text
cresco-site-settings/v1
```

Engine làm việc với active Elementor Kit, có capability discovery, diff, no-op detection, read-back verification, rollback và responsive foundation.

Element-level AI exchange và global Site Settings là hai contract khác nhau để giảm coupling khi Elementor thay đổi control.

## Yêu cầu hệ thống

- WordPress 6.6+
- PHP 8.1+
- Elementor
- Elementor Pro nếu dùng integration Pro
- Browser có thể truy cập Elementor preview iframe cùng origin nếu muốn capture Fidelity/raster đầy đủ

## Tài liệu

Bắt đầu tại:

```text
docs/README.md
```

Tài liệu quan trọng:

- `docs/EXTERNAL-AI-WORKFLOW.md` — workflow Elementor → ChatGPT → Elementor;
- `docs/AI-EXPORT-IMPORT.md` — contract AI/export/import chi tiết;
- `docs/FIDELITY-ENGINE.md` — computed snapshot, score và verification gate;
- `docs/KIEN-TRUC-HE-THONG.md` — kiến trúc tổng thể;
- `docs/SITE-SETTINGS.md` — Elementor Global Settings;
- `docs/SCHEMA-REFERENCE.md` — schema reference;
- `docs/PHAT-TRIEN-KIEM-THU.md` — phát triển và test.

## Nguyên tắc bất biến

1. Elementor là source of truth.
2. Cresco là bridge/validator, không phải page builder thứ hai.
3. AI bên ngoài không được invent capability.
4. AI chỉ được sửa editable scope.
5. Native Elementor controls ưu tiên hơn custom CSS.
6. Active Global Styles ưu tiên hơn local near-duplicate.
7. Persisted unknown fields được preserve khi không phải mục tiêu chỉnh sửa.
8. Save thành công chưa đủ; phải read-back verify.
9. Không có rendered evidence không được coi là Fidelity PASS.
10. Người dùng quyết định Update/Publish cuối cùng.
