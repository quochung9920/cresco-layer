# Cresco Layer

**Cầu nối file-based, lossless và runtime-aware giữa Elementor ↔ ChatGPT/AI bên ngoài.**

Phiên bản hiện tại: **0.24.5 — External AI Only + Editor Rehydrate**.

Từ 0.24.4, Cresco Layer **không còn Local AI runtime, provider, model endpoint hay suy luận AI bên trong WordPress/Elementor**. Mọi phần tạo/chỉnh thiết kế bằng AI diễn ra ở ChatGPT hoặc AI bên ngoài thông qua file Cresco export/import. Điều này làm plugin nhẹ hơn, giảm surface lỗi và giữ ranh giới sản phẩm rõ ràng.

Từ 0.24.5, Import AI Result đồng bộ Elementor client trước Preview/Apply và reload toàn bộ Elementor Editor sau khi Apply thành công để browser model rehydrate từ working document/autosave vừa lưu. Điều này tránh trường hợp server đã thêm giao diện nhưng editor canvas vẫn giữ model cũ hoặc autosave cũ ghi đè kết quả Cresco.

## Workflow chính

```text
Elementor
→ Cresco Export for ChatGPT
→ ZIP/JSON package tự mô tả
→ người dùng upload sang ChatGPT / AI bên ngoài
→ mô tả giao diện muốn tạo hoặc chỉnh
→ AI trả JSON theo contract của package
→ Cresco Import AI Result
→ synchronize Elementor working document
→ Preview + Validation
→ synchronize again before Apply
→ Apply vào Elementor working document
→ Read-back verification
→ full Elementor Editor reload/rehydrate
→ Rendered/Fidelity verification
→ người dùng Update/Publish trong Elementor
```

Cresco không chạy chatbot/model trong Elementor và không yêu cầu cấu hình Ollama, LM Studio, llama.cpp hay OpenAI-compatible local endpoint.

## Các thành phần vẫn được giữ nguyên

Việc bỏ Local AI không làm thay đổi các subsystem cốt lõi:

- External AI Exchange và bundle ZIP/JSON;
- Target Sync Preflight + Elementor autosave;
- Exact Runtime / runtime control discovery;
- bounded Full Context và export diagnostics;
- `cresco-ai-mutation/v3`, `cresco-layer-patch/v1` và import compatibility;
- runtime/scope/semantic validation;
- deterministic Cresco Skills — **không phải AI**, không gọi model;
- Site Settings Engine / Active Elementor Kit;
- History / rollback;
- Rendered Verification và Fidelity Foundation;
- Runtime Inspector / full Elementor snapshot.

## Export to ChatGPT

Trong Elementor Editor, Cresco cung cấp External AI Exchange với các scope chính:

- **Selected element** — element đang chọn;
- **Selected subtree** — root + descendants;
- **Entire page** — toàn document.

Dạng export khuyến nghị:

```text
cresco-chatgpt-bundle-<target>.zip
```

Fallback single-file:

```text
cresco-chatgpt-package-<target>.json
```

Package/bundle mang theo runtime context, Global Styles, breakpoint, control metadata, layout context, result contract và rendered evidence khả dụng để AI bên ngoài không phải đoán cơ chế Elementor.

## Import AI Result

Contract ưu tiên theo scope:

```text
Selected element/subtree → cresco-ai-mutation/v3
Entire page              → cresco-layer-patch/v1 + scope.mode=document
```

Các format tương thích vẫn được nhận khi phù hợp:

```text
cresco-ai-mutation/v3
cresco-ai-mutation/v2
cresco-layer-patch/v1
cresco-layer-ai-result/v1
```

Import luôn đi qua normalize/compile, runtime validation, scope enforcement, preview và verification. AI không được ghi trực tiếp database.

Trước **Preview Changes** và **Apply to Elementor**, Cresco gọi Elementor Commands API:

```js
$e.run('document/save/auto', { force: true })
```

để server làm việc trên trạng thái editor mới nhất. Sau server Apply/read-back, Cresco reload toàn bộ Elementor Editor thay vì chỉ reload preview iframe, vì preview iframe không cập nhật Backbone/model state đang giữ trong editor shell.

## External package và bundle

Single JSON package:

```text
cresco-external-ai-package/v1
```

ZIP bundle:

```text
cresco-ai-bundle/v4
```

Bundle có thể chứa `README-FOR-CHATGPT.md`, `cresco-package.json`, `elementor-context.json`, `output-contract.json`, `widget-guide.json`, visual context/preview, reference image và `manifest.json`.

## Runtime-first, không invent controls

Cresco đọc Elementor runtime thật thay vì dựa vào danh sách control hard-code. Normalized registry dùng `cresco-control-registry/v1`.

Nguyên tắc:

```text
AI quyết định design intent
→ Cresco quyết định Elementor mechanics
→ runtime registry xác nhận control thật
→ validator kiểm scope/value
→ Elementor API persistence
→ read-back/render verification
```

## Bounded Full Context

`full` giữ full registry awareness nhưng không hydrate control stack không giới hạn. Cresco ưu tiên detailed capability cho target/context/construction candidates và dùng resource budget. Exact Runtime reuse detail đã có và chỉ tải phần còn thiếu. Required target capability vẫn fail-closed; optional enrichment có thể fail-soft và được ghi vào coverage diagnostics.

## Target Sync và Safe Bootstrap

Trước export, Cresco có thể force Elementor autosave bằng Commands API rồi kiểm tra target ở working/autosave document. Nếu client đi trước server, Cresco chờ có giới hạn hoặc dừng an toàn thay vì export dữ liệu stale.

Import dùng cùng nguyên tắc source-of-truth: đồng bộ client trước Preview/Apply, sau đó full editor reload sau Apply để loại bỏ stale browser model.

Elementor startup chỉ giữ code tối thiểu. Heavy exchange/runtime work được lazy-load sau user action. Rescue mode:

```text
&cresco_safe=1
```

## Deterministic Cresco Skills

`Cresco Skills` được giữ lại vì đây **không phải Local AI**. Skill runtime biên dịch từ controls/props thực sự đang đăng ký, dùng bounded command parser, không gọi LLM/provider và chỉ apply native live setting operations trong selected element.

## Site Settings

Site Settings dùng schema riêng `cresco-site-settings/v1`. Active Elementor Kit là source of truth. Engine hỗ trợ capability discovery, diff, Kit API save, read-back verification, rollback và ownership bookkeeping.

## Fidelity Foundation

Các schema chính:

```text
cresco-fidelity-policy/v1
cresco-fidelity-snapshot/v1
cresco-geometry-graph/v1
cresco-fidelity-report/v1
cresco-fidelity-gate/v1
```

Fidelity đọc rendered preview thật trong browser. Không có evidence thì không được PASS. Cresco không hứa pixel-perfect tuyệt đối giữa mọi browser/OS/font stack; mục tiêu là deterministic structural fidelity + bounded rendered error.

## Yêu cầu hệ thống

- WordPress 6.6+
- PHP 8.1+
- Elementor
- Elementor Pro khi dùng integration Pro
- Node.js 20+ cho development/test

## Tài liệu

Bắt đầu tại `docs/README.md`. Các file quan trọng: `PROJECT_RULES.md`, `docs/EXTERNAL-AI-WORKFLOW.md`, `docs/AI-EXPORT-IMPORT.md`, `docs/EXPORT-RESILIENCE.md`, `docs/FIDELITY-ENGINE.md`, `docs/KIEN-TRUC-HE-THONG.md`, `docs/SITE-SETTINGS.md`, `docs/PHAT-TRIEN-KIEM-THU.md`.

## Invariants

1. Elementor là source of truth.
2. Cresco hiện là **external-AI-only bridge**; không thêm lại model/provider inference trong plugin nếu không có quyết định kiến trúc mới rõ ràng.
3. Không ghi trực tiếp `_elementor_data` để bypass Elementor API.
4. AI không được invent capability hoặc thoát editable scope.
5. Native Elementor controls ưu tiên hơn Custom CSS.
6. Active Global Styles ưu tiên hơn local near-duplicate.
7. Persisted unknown fields phải được preserve khi không phải mục tiêu sửa.
8. `save()` thành công chưa đủ; workflow chính xác cần read-back verify.
9. Không có rendered evidence không được Fidelity PASS.
10. Người dùng giữ quyền Update/Publish cuối cùng.
11. Import server-side thành công phải được full editor rehydrate trước khi người dùng tiếp tục chỉnh sửa.
