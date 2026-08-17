# AI Context Resolver v1

> **Tài liệu lịch sử:** mô tả kiến trúc resolver ở Cresco Layer 0.5.0 với `cresco-context-resolver/v1`. Implementation 0.24.3 hiện đã dùng `cresco-context-resolver/v3` và bounded detail budget; xem `PROJECT_RULES.md`, `EXPORT-RESILIENCE.md` và code hiện tại khi cần behavior mới nhất.

Cresco Layer 0.5.0 dùng `cresco-context-resolver/v1` để biến kiến thức runtime Elementor rất lớn thành `cresco-layer-ai-package/v2` có giới hạn và phù hợp task.

## Vì sao cần Context Resolver?

Full Elementor Runtime Snapshot là artifact phục vụ diagnostics/knowledge-base. Nó có thể chứa:

- hàng trăm widget/element type;
- mọi serializable control;
- global options;
- templates;
- runtime records.

Gửi toàn bộ snapshot vào mỗi AI edit vừa tốn tài nguyên vừa làm model khó tập trung. Vì vậy normal export dùng runtime/snapshot làm source of truth nhưng chỉ mở rộng detailed capability cần cho task hiện tại.

## Các profile lịch sử

### `smart` — mặc định

`smart` gồm:

- Elementor data chính xác của editable element/scope;
- parent/sibling context dạng read-only cho scoped export;
- detailed controls của type xuất hiện trong editable scope;
- detailed controls của type xuất hiện trong read-only context;
- bounded set common insertion candidates cho document/subtree;
- compact `registryIndex` của mọi registered widget/element mà không mở toàn bộ control;
- active Kit/Site Settings, Global Colors/Fonts, active breakpoints, Dynamic Tags;
- dependency-aware Elementor Pro runtime information;
- `capabilityCoverage` để AI biết nguồn nào trusted/partial/unavailable.

Mục tiêu là package nhỏ hơn rất nhiều so với full runtime catalog.

### `full`

Trong thiết kế 0.5.0, `context=full` mở detailed capability metadata cho mọi registered widget/element. Full Runtime Snapshot vẫn là artifact riêng, không nhúng toàn bộ raw diagnostic data vào mọi AI edit.

> Ở 0.24.3, ý nghĩa vận hành đã thay đổi: Full giữ **full registry awareness** nhưng detailed hydration được giới hạn bằng resource budget; Exact Runtime bổ sung capability còn thiếu. Không dùng mô tả lịch sử này để suy ra behavior hiện tại.

## REST export

```text
GET /wp-json/cresco-layer/v1/documents/{postId}/export?scope=widget&selected={elementId}&context=smart
GET /wp-json/cresco-layer/v1/documents/{postId}/export?scope=subtree&selected={elementId}&context=full
```

Nếu bỏ `context`, `smart` là default trong thiết kế này.

## Contract trong AI package

Transport schema vẫn là:

```text
cresco-layer-ai-package/v2
```

Resolver thêm metadata thay vì đổi transport contract.

Các field quan trọng:

- `manifest.contextProfile` — `smart` hoặc `full`.
- `manifest.contextResolver` — version resolver.
- `registryIndex` — summary toàn bộ registered type.
- `widgetCatalog` / `elementCatalog` — detailed controls được chọn.
- `relevantCapabilities.roles` — lý do capability được đưa vào: `editable`, `read-only-context`, `insertion-candidate`, `full-profile`.
- `dynamicTags` — registered Dynamic Tags.
- `capabilityCoverage` — trạng thái controls, Active Kit, breakpoint, Dynamic Tags, Pro runtime modules.
- `contextResolver.stats` — số registered/expanded và scan error.
- `contextResolver.runtime.dependencies` — dependency signal và licensed-but-inactive Pro capability.

`designSystem` vẫn giữ Active Kit settings array để tương thích consumer cũ.

## Dynamic Tags discovery

Elementor Dynamic Tags manager lưu registry record quanh registered `instance` và class. Cresco 0.5.0 đọc đúng registry shape này thay vì coi record là tag object.

`get_tags()` cũng cho Elementor cơ hội chạy registration hook bình thường. Nếu Elementor Pro active nhưng registry vẫn rỗng, coverage được đánh dấu `partial` thay vì báo trusted empty catalog.

## Elementor Pro module discovery

Module manager expose tên module riêng với getter. Cresco enumerate:

```text
get_modules_names()
→ get_modules($name)
```

Không gọi `get_modules()` thiếu tên module. Điều này tránh `ArgumentCountError` trên Elementor Pro 4.x.

## Dependency-aware capability

Một Pro license có thể quảng bá feature nhưng dependency bên ngoài chưa active. Cresco phân biệt licensed potential với live runtime availability.

Dependency signal lịch sử gồm:

- WooCommerce;
- ACF;
- Pods;
- Toolset.

Ví dụ WooCommerce feature có license nhưng WooCommerce inactive → báo `dependency-inactive`, không invent widget live.

## Rule an toàn cho AI

Package yêu cầu AI:

- không invent Elementor setting name;
- chỉ sửa setting khi detailed capability chứng minh setting đó;
- coi `registryIndex` là discovery metadata, không phải quyền invent control;
- không dựa vào nguồn `partial` hoặc `unavailable` như dữ liệu chắc chắn;
- preserve IDs, unknown fields, Atomic/V4 data, Dynamic Tags và global references nếu không chủ đích thay;
- trả output theo contract được package yêu cầu.

Cresco vẫn là authority cuối qua schema validation, scope validation, runtime/semantic validation, preview, apply transaction và read-back verification.

## Quan hệ với Full Runtime Snapshot

```text
Elementor runtime
  -> Full Runtime Snapshot / registries
  -> Context Resolver
  -> task-specific AI package
  -> AI
  -> result/patch
  -> validation + preview + apply + verification
  -> Elementor
```

Snapshot dùng cho diagnostics/full-site inspection. Context-resolved package dùng cho AI editing bình thường.