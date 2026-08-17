# Tài liệu Cresco Layer

Tài liệu dùng **Vietnamese-first**. Tên schema, class, function, endpoint, JSON key và command giữ nguyên tiếng Anh để đối chiếu với code.

> **Từ 0.24.4, Cresco Layer là External AI Only.** Local AI runtime/provider/model endpoint trong WordPress/Elementor đã bị loại bỏ. AI generation/editing diễn ra qua workflow export file → ChatGPT/AI bên ngoài → import JSON. `Cresco Skills` vẫn được giữ vì đây là deterministic runtime, không gọi model.

## Nên đọc theo thứ tự

1. [`../PROJECT_RULES.md`](../PROJECT_RULES.md)
2. [`EXTERNAL-AI-WORKFLOW.md`](EXTERNAL-AI-WORKFLOW.md)
3. [`AI-EXPORT-IMPORT.md`](AI-EXPORT-IMPORT.md)
4. [`EXPORT-RESILIENCE.md`](EXPORT-RESILIENCE.md)
5. [`FIDELITY-ENGINE.md`](FIDELITY-ENGINE.md)
6. [`KIEN-TRUC-HE-THONG.md`](KIEN-TRUC-HE-THONG.md)
7. [`SCHEMA-REFERENCE.md`](SCHEMA-REFERENCE.md)
8. [`PHAT-TRIEN-KIEM-THU.md`](PHAT-TRIEN-KIEM-THU.md)

## Workflow hiện tại

```text
Elementor Editor
→ Safe Bootstrap
→ Target Sync Preflight
→ Export for ChatGPT
→ Exact Runtime + bounded Full Context
→ ZIP/JSON package
→ ChatGPT / AI bên ngoài
→ JSON result theo resultContract
→ Import AI Result
→ runtime + scope + semantic validation
→ Preview
→ Apply qua Elementor API
→ read-back verification
→ rendered/Fidelity verification
```

Không có bước chạy model/provider AI bên trong Elementor.

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

Nguyên tắc bất biến: Elementor là source of truth; AI không invent capability hoặc thoát scope; native controls ưu tiên; `save()` cần read-back khi workflow yêu cầu; không có rendered evidence thì không Fidelity PASS; Update/Publish cuối cùng do người dùng quyết định.
