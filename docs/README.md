# Tài liệu Cresco Layer

Bộ tài liệu này được chuẩn hóa theo hướng **Vietnamese-first** để developer và người vận hành dễ đọc hơn.

Tên schema, class, function, REST endpoint, JSON key, CSS selector và command vẫn giữ nguyên tiếng Anh để có thể đối chiếu trực tiếp với code/runtime.

## Nên đọc file nào trước?

Nếu bạn chỉ muốn hiểu Cresco hiện hoạt động như thế nào, đọc theo thứ tự:

1. [`../PROJECT_RULES.md`](../PROJECT_RULES.md) — quy tắc kiến trúc, coding, safety, testing và các invariant bắt buộc.
2. [`EXTERNAL-AI-WORKFLOW.md`](EXTERNAL-AI-WORKFLOW.md) — workflow chính Elementor → ChatGPT → Elementor từ 0.24.
3. [`AI-EXPORT-IMPORT.md`](AI-EXPORT-IMPORT.md) — chi tiết export package, scope, result contract và import pipeline.
4. [`EXPORT-RESILIENCE.md`](EXPORT-RESILIENCE.md) — bounded Full Context, auto recovery và diagnostics của 0.24.3.
5. [`FIDELITY-ENGINE.md`](FIDELITY-ENGINE.md) — computed snapshot, geometry graph, score và verification gate.
6. [`KIEN-TRUC-HE-THONG.md`](KIEN-TRUC-HE-THONG.md) — kiến trúc tổng thể của các module.
7. [`SCHEMA-REFERENCE.md`](SCHEMA-REFERENCE.md) — tra cứu schema/contract.
8. [`PHAT-TRIEN-KIEM-THU.md`](PHAT-TRIEN-KIEM-THU.md) — quy tắc development, test và release gate.

## Workflow hiện tại từ 0.24+

```text
Elementor Editor
→ Safe Bootstrap
→ user mở Cresco
→ Target Sync Preflight
→ Export for ChatGPT
→ Exact Runtime + bounded Full Context
→ cresco-external-ai-package/v1 hoặc cresco-ai-bundle/v4
→ ChatGPT nhận design request trong chat
→ JSON result theo resultContract
→ Import AI Result
→ normalize / compile
→ runtime + scope + semantic validation
→ Preview
→ Apply qua Elementor API
→ read-back verification
→ rendered/Fidelity verification
```

Không có bước bắt buộc nhập design prompt trong Elementor. Cresco là **file-based bridge**, không phải chatbot embedded trong Elementor.

## Tài liệu hiện hành quan trọng

### External AI / Export / Import

- [`EXTERNAL-AI-WORKFLOW.md`](EXTERNAL-AI-WORKFLOW.md) — luồng người dùng chính.
- [`AI-EXPORT-IMPORT.md`](AI-EXPORT-IMPORT.md) — package, scope, mutation/patch, import.
- [`EXPORT-RESILIENCE.md`](EXPORT-RESILIENCE.md) — giới hạn tài nguyên, fallback và diagnostics.
- [`TARGET-SYNC-PREFLIGHT.md`](TARGET-SYNC-PREFLIGHT.md) — autosave + Target Resolver trước export.
- [`SAFE-BOOTSTRAP.md`](SAFE-BOOTSTRAP.md) — startup fail-passive, lazy loading, `cresco_safe=1`.
- [`EXACT-RUNTIME-AI-EXPORT.md`](EXACT-RUNTIME-AI-EXPORT.md) — exact runtime capability enrichment.

### Schema / Compiler / Safety

- [`SCHEMA-REFERENCE.md`](SCHEMA-REFERENCE.md) — ma trận schema hiện dùng.
- [`AI-PATCH-SPEC.md`](AI-PATCH-SPEC.md) — `cresco-layer-patch/v1`.
- [`AI-MUTATION-V2.md`](AI-MUTATION-V2.md) — mutation v2 compatibility/intermediate contract.
- [`SEMANTIC-DESIGN-COMPILER.md`](SEMANTIC-DESIGN-COMPILER.md) — mutation v3 → runtime controls.
- [`SAFE-AI-EXCHANGE.md`](SAFE-AI-EXCHANGE.md) — read-only source context + delta-first mutation.

### Fidelity / Rendered Verification

- [`FIDELITY-ENGINE.md`](FIDELITY-ENGINE.md) — Fidelity Foundation.
- [`VISUAL-VERIFICATION.md`](VISUAL-VERIFICATION.md) — rendered semantic verification.

### Elementor Runtime / Site Settings

- [`ELEMENTOR-SNAPSHOT.md`](ELEMENTOR-SNAPSHOT.md) — full runtime snapshot dành cho admin/diagnostics.
- [`SITE-SETTINGS.md`](SITE-SETTINGS.md) — Elementor Kit / Global Settings engine.
- [`WIDGET-INTELLIGENCE.md`](WIDGET-INTELLIGENCE.md) — semantic widget selection dựa trên runtime.
- [`WIDGET-SKILLS.md`](WIDGET-SKILLS.md) — deterministic widget skill runtime.

### Design Intelligence

- [`DESIGN-INTELLIGENCE.md`](DESIGN-INTELLIGENCE.md) — design dials + quality priorities.
- [`DESIGN-REASONING.md`](DESIGN-REASONING.md) — product/page reasoning, hierarchy, composition, quality gates.

### Local AI

- [`LOCAL-AI.md`](LOCAL-AI.md) — Local AI Manager và provider boundary.
- [`SEMANTIC-AI.md`](SEMANTIC-AI.md) — semantic context + planning pipeline.
- [`ACCURACY-CORE.md`](ACCURACY-CORE.md) — evidence-checked, task-retrieved skill planning.

## Tài liệu lịch sử

Một số file mô tả các mốc kiến trúc cũ nhưng vẫn được giữ vì có giá trị giải thích quyết định thiết kế.

Các file này được gắn nhãn **Tài liệu lịch sử** ở đầu file khi behavior version-specific đã thay đổi:

- [`ARCHITECTURE.md`](ARCHITECTURE.md) — nền kiến trúc 0.5.x.
- [`AI-CONTEXT-RESOLVER.md`](AI-CONTEXT-RESOLVER.md) — resolver v1 lịch sử; current implementation đã lên v3.
- [`AI-CONTEXT-V3.md`](AI-CONTEXT-V3.md) — giai đoạn UX Create/Edit trước External AI Exchange.
- [`EDITOR-AI-UX.md`](EDITOR-AI-UX.md) — workflow “Edit with AI” cũ.
- [`AI-BUNDLE.md`](AI-BUNDLE.md) — bundle v3 lịch sử; external workflow hiện dùng v4.
- [`EXTERNAL-AI-INTELLIGENCE.md`](EXTERNAL-AI-INTELLIGENCE.md) — foundation 0.20.
- [`SEMANTIC-RUNTIME-COMPILER.md`](SEMANTIC-RUNTIME-COMPILER.md) — foundation 0.19.
- [`CRESCO-LAYER-TECHNICAL-REPORT.md`](CRESCO-LAYER-TECHNICAL-REPORT.md) — báo cáo rất chi tiết ở mốc 0.15.0.

Không xóa tài liệu lịch sử chỉ vì version cũ; thay vào đó phải ghi rõ phạm vi để tránh người đọc nhầm nó là contract hiện tại.

## Ba lớp dữ liệu cần phân biệt

### 1. Persisted Elementor data

Dữ liệu đang được Elementor lưu trong document/Kit.

### 2. Runtime capability

Widget/element/control thực sự được đăng ký trong installation hiện tại.

### 3. Rendered evidence

Geometry/computed style sau khi browser render Elementor preview.

Một AI result “đúng JSON” chưa đủ. Trust chain là:

```text
JSON parse được
→ đúng target/scope
→ đúng runtime capability
→ đúng unit/option/range
→ semantic-safe
→ Elementor save thành công
→ read-back đúng
→ render có evidence
→ Fidelity Gate
```

## Các schema quan trọng

```text
cresco-export-target-status/v1
cresco-external-ai-package/v1
cresco-ai-bundle/v4
cresco-external-exchange-policy/v1
cresco-ai-context/v3
cresco-layer-ai-package/v2
cresco-control-registry/v1
cresco-ai-mutation/v3
cresco-ai-mutation/v2
cresco-layer-patch/v1
cresco-layer-patch-validation/v2
cresco-site-settings/v1
cresco-fidelity-policy/v1
cresco-fidelity-snapshot/v1
cresco-geometry-graph/v1
cresco-fidelity-report/v1
cresco-fidelity-gate/v1
```

## Quy ước chất lượng

- Elementor luôn là source of truth.
- Cresco startup phải fail-passive.
- Heavy work chỉ chạy sau user action phù hợp.
- Target Sync dùng Elementor autosave, không tự ghi `_elementor_data`.
- Runtime capability phải được chứng minh; không invent control/suffix/unit/option.
- AI không được thoát editable scope.
- Native controls ưu tiên hơn Custom CSS.
- Global Styles ưu tiên hơn local near-duplicate.
- Unknown persisted Elementor fields phải được preserve nếu không phải mục tiêu sửa.
- `save()` thành công chưa đủ; cần read-back verification khi workflow yêu cầu.
- Không có rendered evidence không được coi là Fidelity PASS.
- Không tuyên bố pixel-perfect tuyệt đối giữa mọi browser/OS/font stack.
- Update/Publish cuối cùng vẫn do người dùng quyết định trong Elementor.

## Khi tài liệu mâu thuẫn nhau

Ưu tiên:

```text
1. code/runtime hiện tại
2. current contract/behavior tests
3. PROJECT_RULES.md
4. docs 0.24+
5. tài liệu lịch sử
```

Nếu architecture thay đổi có chủ đích, tài liệu liên quan phải được cập nhật cùng change.