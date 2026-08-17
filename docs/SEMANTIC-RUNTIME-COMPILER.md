# Cresco Layer 0.19 — Semantic Runtime Compiler

> **Tài liệu lịch sử:** mô tả các cải tiến ở 0.19. External workflow hiện tại đã lên 0.24+, nhưng các nguyên tắc runtime-first widget discovery và semantic binding vẫn là nền tảng quan trọng.

Cresco Layer 0.19 tập trung vào ba khoảng trống độ chính xác khi external AI tái tạo thiết kế trong Elementor:

1. chọn đúng widget đã được cài/đăng ký cho task;
2. map semantic content qua live widget controls thay vì giả định core-only;
3. đóng gói visual/context bundle đủ dùng.

## Task-aware runtime discovery

Exact Runtime xét cả current AI request khi chọn construction capabilities.

Nó bắt đầu từ:

- current document types;
- read-only context types;
- proven construction set;

sau đó chỉ bổ sung widget type nếu:

- type thực sự tồn tại trong live Elementor registry;
- task hint hoặc registry title/category/keyword cho thấy liên quan.

Export ghi thông tin này trong:

```text
cresco-task-runtime-discovery/v1
```

Không invent widget thiếu/unregistered.

Ví dụ task:

> “create an FAQ accordion”

có thể khiến Cresco tải Accordion/Nested Accordion/Toggle detail **nếu các type đó đang thực sự registered** dù chúng chưa xuất hiện trong selected subtree.

## Runtime semantic bindings

`cresco-ai-mutation/v2` content shortcut không được giả định mọi widget dùng cùng setting key.

Ví dụ semantic text:

```text
content.text
```

có thể map thành:

- Core Heading → `title`.
- Third-party heading → `headline` nếu runtime chứng minh control đó.

Semantic heading level chỉ emit khi runtime có control tương thích như:

```text
header_size
html_tag
tag
```

và option requested hợp lệ.

Button label/URL cũng chỉ bind vào control thật.

Explicit `settings` có ưu tiên cao hơn shortcut và vẫn qua `SemanticPatchGuard`.

## Widget không phải arbitrary container

Semantic widget có nested child nodes tùy ý phải bị reject.

Structural children nên đặt dưới runtime-proven structural element như Container.

Điều này tránh việc model nhìn DOM rồi tự invent internal storage model cho widget phức tạp.

## External AI Bundle ở 0.19

Editor từng cung cấp action `Export AI Bundle` sau context preparation.

ZIP lịch sử:

```text
cresco-ai-bundle-<target>.zip
```

có thể chứa:

```text
01-TASK.md
02-context.json
03-widget-guide.json
04-output-contract.json
manifest.json
current-desktop.png
reference image
```

External workflow mới hơn dùng bundle v4, nhưng nguyên tắc bundle self-contained vẫn còn.

## Raster capture

Raster là best-effort:

```text
selected target trong preview iframe
→ clone subtree + computed styles
→ SVG foreignObject
→ canvas
→ PNG
```

Có thể thất bại vì cross-origin asset hoặc unsupported rendering. Khi đó bundle vẫn hợp lệ và manifest phải ghi raster unavailable; Cresco không fabricate image.

ZIP writer local, uncompressed, không cần external archive dependency.

## Safety không thay đổi

- Elementor runtime là authority.
- Active Kit là design-system source of truth.
- External AI không sở hữu final Elementor IDs.
- Semantic mutation là delta-first.
- Form/query/navigation/commerce/code behavior được preserve nếu user không yêu cầu đổi.
- `MutationNormalizer` chỉ sửa deterministic, semantics-preserving issues.
- `SemanticPatchGuard` là runtime/semantic authority trước Apply.
- Legacy `cresco-layer-patch/v1` và `cresco-layer-ai-result/v1` vẫn có thể được support qua compatibility layer.

## Giá trị kiến trúc còn giữ tới hiện tại

Điểm quan trọng nhất của Semantic Runtime Compiler:

> **Semantic intent chỉ có giá trị khi cuối cùng bind được vào control thực sự tồn tại trong runtime hiện tại.**

AI không được phép biến semantic convenience thành một đường invent Elementor settings.