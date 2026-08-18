# Quy tắc dự án Cresco Layer

> Repository: `quochung9920/cresco-layer` — plugin WordPress Cresco Layer.  
> Baseline hiện hành: **0.24.6 — External AI Only + Target Sync Hard Gate**.  
> Khi tài liệu mâu thuẫn với code/runtime và current tests, code/runtime + tests là source of truth.

## 1. Vai trò sản phẩm

Cresco Layer là cầu nối **file-based, lossless, runtime-aware giữa Elementor và AI bên ngoài**.

```text
Elementor
→ Target Sync Preflight
→ server Target Hard Gate
→ Export for ChatGPT
→ ZIP/JSON package
→ ChatGPT / AI bên ngoài
→ JSON result
→ Import AI Result
→ Preview + Validation
→ Apply qua Elementor APIs
→ read-back verification
→ full editor rehydrate
→ rendered/Fidelity verification
```

Từ 0.24.4, Cresco **không có Local AI runtime, provider, model endpoint hoặc embedded chatbot** trong WordPress/Elementor. Không thêm lại Ollama/LM Studio/llama.cpp/OpenAI-compatible local inference nếu chưa có architecture decision mới, tests, docs và yêu cầu sản phẩm rõ ràng.

`Cresco Skills` vẫn được giữ vì đây là deterministic runtime engine, **không gọi AI/model/provider**.

## 2. Cấu trúc hiện tại

```text
cresco-layer.php                 bootstrap/version/autoloader
includes/Plugin.php              service wiring
includes/AI/                     export/import, target gate, capability, mutation/patch, fidelity
includes/Elementor/              runtime discovery/snapshot/widgets
includes/SiteSettings/           Elementor Kit / Global Settings
includes/DesignSystem/           design standards
includes/Audit/                  audit
includes/Diagnostics/            export diagnostics
includes/Skills/                 deterministic widget skills
includes/Admin/                  admin screen
includes/REST/                   REST controllers
includes/Support/                assets/requirements/serialization
assets/                          browser/admin/editor/frontend assets
tests/                           contract/behavior tests
scripts/                         architecture/lint checks
docs/                            tài liệu
```

Không tạo lại `includes/LocalAI/`, local-model assets, provider settings hoặc Local AI REST routes trong kiến trúc hiện tại.

## 3. Invariant bắt buộc

1. **Elementor là source of truth** cho document, widget, controls, breakpoint, Global Styles, renderer, history và persistence.
2. Không ghi trực tiếp `_elementor_data` để bypass Elementor Document API.
3. Site Settings phải đi qua active Elementor Kit/Document API.
4. Không dùng `eval`, `shell_exec`, `exec` hoặc dynamic execution shortcut tương đương.
5. Resolve target/scope trước persistence; patch không có quyền không được tự mở rộng thành document-wide.
6. Runtime capability là authoritative. Không invent control, responsive suffix, unit, option, Dynamic Tag hoặc global reference.
7. Unknown persisted Elementor/addon/Atomic data phải được preserve khi không phải mục tiêu sửa.
8. Native Elementor controls ưu tiên hơn `custom_css` khi runtime có thể biểu đạt yêu cầu.
9. Active Global Styles/Kit tokens ưu tiên hơn local near-duplicate.
10. `save()` thành công chưa đủ; workflow cần độ chính xác phải read-back verify.
11. Render/Fidelity chỉ PASS khi có rendered evidence thật. **No evidence ≠ PASS.**
12. Người dùng giữ quyết định Update/Publish cuối cùng.
13. Safety/validation uncertainty phải fail-closed; chỉ optional enrichment mới fail-soft.
14. Cresco hiện là **external-AI-only**; không chạy model/provider inference trong plugin.
15. Import AI phải đồng bộ Elementor client bằng Commands API trước Preview/Apply. Sau khi server Apply thành công, phải reload **toàn bộ Elementor editor** để browser model rehydrate từ working document/autosave mới; reload riêng preview iframe là không đủ.
16. Scoped Export chỉ được vào `PackageBuilder` khi `ExportTargetGate` xác nhận target `ready`. Target mismatch phải dừng bằng trạng thái sync cụ thể, không được rơi thành generic 500.
17. Target hard gate phải chạy **sau REST permission check và trước route callback**. Hook hiện hành là `rest_dispatch_request`; không dùng `rest_pre_dispatch` hoặc `rest_request_before_callbacks` để đọc document state cho gate này.

## 4. External AI Exchange

Primary path là file exchange, không phải chatbot trong editor.

```text
cresco-external-ai-package/v1
cresco-ai-bundle/v4
```

Preferred result:

```text
selected element/subtree → cresco-ai-mutation/v3
document                 → cresco-layer-patch/v1
```

Compatibility có thể giữ `cresco-ai-mutation/v2` và `cresco-layer-ai-result/v1` khi validator/compiler còn hỗ trợ.

Pipeline import bắt buộc:

```text
AI output
→ synchronize current Elementor working document
→ normalize/compile
→ schema/security validation
→ runtime capability validation
→ semantic guard
→ scope enforcement
→ Preview/Diff
→ synchronize again before Apply
→ Apply qua Elementor API
→ read-back verify
→ full Elementor editor reload/rehydrate
→ rendered/Fidelity verify
```

## 5. Runtime capability / Full Context

Không hard-code control chỉ vì một site/version có nó. `cresco-control-registry/v1` phải phản ánh runtime thật.

`Full Context` giữ **full registry awareness nhưng bounded detail**. Required target/context capability không được silently truncate. Exact Runtime phải reuse detail đã có và chỉ fetch phần thiếu.

## 6. Safe Bootstrap / Target Sync

Elementor startup là critical path. Không đưa trở lại startup full catalog scan, unbounded polling, document-wide observer, unnecessary fetch wrapper, visual capture hoặc model initialization.

Rescue mode:

```text
&cresco_safe=1
```

Target Sync dùng Elementor autosave/Commands API và bounded status checks. Không copy client JSON trực tiếp vào Elementor persistence để chữa mismatch.

Scoped export target states:

```text
ready          → working document có target, export được phép tiếp tục
sync-required  → main có target, working/autosave chưa theo kịp
sync-pending   → live editor có target, server chưa nhận ID
stale-target   → live editor xác nhận target đã biến mất
target-missing → server thiếu target và client evidence chưa đủ
```

`sync-required`, `sync-pending`, `target-missing` có thể retry bounded; `stale-target` không retry và yêu cầu re-select target.

Server hard gate:

```text
REST permission_callback
→ rest_dispatch_request
→ ExportTargetGate
→ ready ? PackageBuilder : HTTP 409/410
```

Target sync conflict không được kích hoạt Full → Smart recovery vì context profile không thể sửa target mismatch.

Import Sync cũng phải dùng:

```js
$e.run('document/save/auto', { force: true })
```

trước Preview và Apply. Sau server Apply/read-back, dùng full editor reload để loại bỏ stale browser model và tránh autosave cũ ghi đè dữ liệu Cresco vừa lưu.

## 7. Export diagnostics

Export failure cần giữ `errorId`, stage, HTTP status, elapsed time, memory/runtime context và fatal file/line khi có thể. Phải phân biệt lỗi package build với response serialization/fatal sau REST callback.

Target synchronization dùng stage:

```text
target-sync-gate
```

Diagnostic route `/documents/{postId}/export` phải resolve đúng `postId` ngay cả khi WordPress chưa populate URL params ở `rest_pre_dispatch`; route regex fallback là bắt buộc.

Target gate response phải giữ `targetStatus` để client/UI có thể hiển thị chính xác `sync-pending`, `stale-target`, v.v.

## 8. Cresco Skills

`includes/Skills/`, `assets/skills.js`, `assets/skills.css` là deterministic functionality và không được nhầm với Local AI.

- chỉ dùng controls/props runtime chứng minh;
- bounded command parser, không chatbot;
- không gọi model/provider;
- thao tác selected element trong widget-skill flow;
- validate options/units/ranges/responsive suffix từ runtime;
- giữ Elementor live settings + history/Undo/Redo.

## 9. Site Settings

Schema riêng:

```text
cresco-site-settings/v1
```

Active Elementor Kit là source of truth. Pipeline: validate → capability discovery → snapshot → semantic adapter → diff → Kit API save → read-back verify → rollback khi cần.

## 10. Fidelity

```text
cresco-fidelity-policy/v1
cresco-fidelity-snapshot/v1
cresco-geometry-graph/v1
cresco-fidelity-report/v1
cresco-fidelity-gate/v1
```

Fidelity phải dựa trên rendered preview thật. Không hứa universal 100% pixel identity; wording đúng là **deterministic structural fidelity + bounded visual error**.

## 11. Coding / REST / UI

- Namespace PHP: `CrescoLayer\`.
- Theo style module hiện hữu; ưu tiên patch nhỏ thay vì rewrite không cần thiết.
- Sanitize input, escape output, dùng WordPress/Elementor APIs.
- Mọi REST route phải có permission model rõ ràng.
- Không đưa secret/token/credential vào package hoặc diagnostics.
- CSS/plugin UI dùng `.cresco-layer-*`, `.cresco-ai-*` và foundation hiện hữu.
- Dùng `:focus-visible`, reduced motion khi phù hợp.

## 12. Downstream `lisa-*`

`lisa-*` là convention downstream do user cung cấp, không phải source plugin đã xác nhận. Khi làm website tương ứng có thể reuse semantic heading clamp, hero 190px/110px, button variants `lisa-*`, `.lisa-section`, `.lisa-content`, 82rem/48rem, focus-visible và Elementor globals; phải inspect site/runtime trước khi coi là fact.

## 13. Test / Git

Lệnh chuẩn:

```bash
npm run check
php scripts/check-architecture.php
```

Regression gates quan trọng:

```text
tests/php/no-local-ai-remnants-test.php
tests/js/ai-panel-contract-test.mjs
tests/js/export-target-sync-contract-test.mjs
tests/js/export-target-sync-behavior-test.mjs
tests/js/export-target-sync-stale-target-test.mjs
```

CI unavailable do billing/runner **không phải test pass**.

Trước khi cập nhật `main`: review diff → chạy gates khả dụng → compare base/head → yêu cầu `behind_by=0` → fast-forward `force=false` → verify main ref.

Checklist cuối:

```text
[ ] Không còn Local AI runtime/provider/model wiring
[ ] External AI Exchange vẫn giữ nguyên
[ ] Deterministic Skills vẫn giữ nguyên
[ ] Safe Bootstrap/Target Sync không regression
[ ] Scoped Export bị server hard-gate trước PackageBuilder
[ ] Target mismatch trả 409/410, không generic 500
[ ] Target sync failure không kích hoạt Full → Smart recovery
[ ] Diagnostic postId khớp route thật
[ ] Import Preview/Apply sync current Elementor autosave
[ ] Apply thành công reload full editor, không chỉ iframe
[ ] Scope/runtime validation không yếu đi
[ ] Site Settings vẫn dùng Kit API
[ ] Version/docs/tests đồng bộ
[ ] Elementor vẫn là source of truth
```
