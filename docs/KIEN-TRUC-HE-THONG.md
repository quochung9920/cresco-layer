# Kiến trúc hệ thống Cresco Layer

Baseline: **0.24.4 — External AI Only**.

Cresco Layer là cầu nối giữa Elementor và AI bên ngoài. Elementor vẫn là source of truth.

```text
Elementor Editor
→ Target Sync
→ Runtime Discovery / Exact Runtime
→ External AI ZIP/JSON
→ ChatGPT / AI bên ngoài
→ AI Result
→ Validation + Preview
→ Elementor Document / Kit API
→ Read-back + Fidelity verification
```

Từ 0.24.4, plugin **không chạy Local AI/model/provider trong WordPress hoặc Elementor**. `Cresco Skills` vẫn được giữ vì là deterministic runtime và không gọi model.

## Các miền chính

- `includes/AI/`: export/import, runtime capability, mutation/patch, semantic safety, fidelity.
- `includes/Elementor/`: widget/control/breakpoint/Kit/Dynamic Tag/runtime discovery.
- `includes/Skills/`: deterministic widget skills.
- `includes/SiteSettings/`: Active Elementor Kit / Global Settings.
- `includes/Diagnostics/`: export diagnostics.
- `includes/Admin/`: External Exchange, Runtime Inspector, Site Settings, Design Standard, History.

Không còn Local AI settings/provider UI.

## Safe Bootstrap

Startup chỉ tải code tối thiểu. Heavy discovery, visual capture và exchange enrichment lazy-load sau user action. Rescue mode: `&cresco_safe=1`.

## Target Sync

Dùng Elementor autosave + `export-target-status` + bounded retry. Không copy client JSON trực tiếp vào persistence và không tự publish.

## Bounded Full Context

`full` giữ full registry awareness nhưng detailed capability có budget. Required target/context không được silently truncate. Exact Runtime reuse detail và chỉ fetch phần thiếu.

## Invariants

1. Không ghi `_elementor_data` trực tiếp.
2. Không chạy Local AI/model trong plugin.
3. Scope xác định trước persistence.
4. Runtime capability là authoritative; không invent control.
5. Unknown persisted data phải lossless.
6. Site Settings qua Kit/Document API.
7. Save cần read-back verify khi workflow yêu cầu.
8. Fidelity dựa trên preview DOM thật; no evidence không PASS.
9. Người dùng giữ Update/Publish cuối cùng.
