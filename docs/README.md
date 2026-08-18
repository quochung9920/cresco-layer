# Tài liệu Cresco Layer

Tài liệu dùng **Vietnamese-first**. Tên schema, class, function, endpoint, JSON key và command giữ nguyên tiếng Anh để đối chiếu với code.

> **Baseline hiện hành: 0.24.6 — External AI Only + Target Sync Hard Gate.** Local AI runtime/provider/model endpoint trong WordPress/Elementor đã bị loại bỏ. AI generation/editing diễn ra qua workflow export file → ChatGPT/AI bên ngoài → import JSON. `Cresco Skills` vẫn được giữ vì đây là deterministic runtime, không gọi model.

## Nên đọc theo thứ tự

1. [`../PROJECT_RULES.md`](../PROJECT_RULES.md)
2. [`EXTERNAL-AI-WORKFLOW.md`](EXTERNAL-AI-WORKFLOW.md)
3. [`TARGET-SYNC-PREFLIGHT.md`](TARGET-SYNC-PREFLIGHT.md)
4. [`AI-EXPORT-IMPORT.md`](AI-EXPORT-IMPORT.md)
5. [`EXPORT-RESILIENCE.md`](EXPORT-RESILIENCE.md)
6. [`FIDELITY-ENGINE.md`](FIDELITY-ENGINE.md)
7. [`KIEN-TRUC-HE-THONG.md`](KIEN-TRUC-HE-THONG.md)
8. [`SCHEMA-REFERENCE.md`](SCHEMA-REFERENCE.md)
9. [`PHAT-TRIEN-KIEM-THU.md`](PHAT-TRIEN-KIEM-THU.md)

## Workflow hiện tại

```text
Elementor Editor
→ Safe Bootstrap
→ Target Sync Preflight
→ REST permission check
→ server Target Sync Hard Gate
→ Export for ChatGPT
→ Exact Runtime + bounded Full Context
→ ZIP/JSON package
→ ChatGPT / AI bên ngoài
→ JSON result theo resultContract
→ Import AI Result
→ synchronize Elementor working document
→ runtime + scope + semantic validation
→ Preview
→ synchronize lại trước Apply
→ Apply qua Elementor API
→ read-back verification
→ full Elementor Editor reload/rehydrate
→ rendered/Fidelity verification
```

Không có bước chạy model/provider AI bên trong Elementor.

## Target Sync 0.24.6

Scoped export không còn dựa duy nhất vào browser preflight. `ExportTargetGate` kiểm tra lại target sau REST permission check và ngay trước callback export. Target mismatch được phân loại rõ:

```text
ready
sync-required
sync-pending
stale-target
target-missing
```

Target chưa ready trả 409/410 thay vì generic 500 và không kích hoạt Full → Smart context recovery.

## Nhóm tài liệu hiện hành

- External exchange: `EXTERNAL-AI-WORKFLOW.md`, `AI-EXPORT-IMPORT.md`, `EXPORT-RESILIENCE.md`, `TARGET-SYNC-PREFLIGHT.md`, `SAFE-BOOTSTRAP.md`, `EXACT-RUNTIME-AI-EXPORT.md`.
- Schema/compiler/safety: `SCHEMA-REFERENCE.md`, `AI-PATCH-SPEC.md`, `AI-MUTATION-V2.md`, `SEMANTIC-DESIGN-COMPILER.md`, `SAFE-AI-EXCHANGE.md`.
- Fidelity: `FIDELITY-ENGINE.md`, `VISUAL-VERIFICATION.md`.
- Runtime/Site Settings/Skills: `ELEMENTOR-SNAPSHOT.md`, `SITE-SETTINGS.md`, `WIDGET-INTELLIGENCE.md`, `WIDGET-SKILLS.md`.
- Design: `DESIGN-INTELLIGENCE.md`, `DESIGN-REASONING.md`.

## Tài liệu lịch sử

`ARCHITECTURE.md`, `AI-CONTEXT-RESOLVER.md`, `AI-CONTEXT-V3.md`, `EDITOR-AI-UX.md`, `AI-BUNDLE.md`, `EXTERNAL-AI-INTELLIGENCE.md`, `SEMANTIC-RUNTIME-COMPILER.md`, `CRESCO-LAYER-TECHNICAL-REPORT.md` mô tả các mốc cũ. Chúng có thể nhắc tới subsystem đã bị loại bỏ; không dùng chúng làm contract hiện tại.

## Thứ tự nguồn sự thật

```text
1. code/runtime hiện tại
2. current contract/behavior tests
3. PROJECT_RULES.md
4. docs hiện hành
5. tài liệu lịch sử
```

Nguyên tắc bất biến: Elementor là source of truth; AI không invent capability hoặc thoát scope; scoped export phải qua target hard gate; native controls ưu tiên; `save()` cần read-back khi workflow yêu cầu; Apply import cần full editor rehydrate; không có rendered evidence thì không Fidelity PASS; Update/Publish cuối cùng do người dùng quyết định.
