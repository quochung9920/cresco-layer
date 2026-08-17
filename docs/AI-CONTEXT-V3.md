# Cresco Layer AI Context v3

> **Lưu ý phiên bản:** file này mô tả thời điểm AI Context v3 được giới thiệu ở 0.16.0. Context schema vẫn quan trọng, nhưng UX “Create / Edit” trong Elementor đã được thay bằng External AI Exchange ở 0.24. Khi cần workflow hiện tại, xem `EXTERNAL-AI-WORKFLOW.md`.

Cresco Layer 0.16.0 giới thiệu lớp exchange AI-first với mục tiêu làm quy trình người dùng đơn giản hơn:

```text
chọn Elementor element
→ mô tả thay đổi
→ Prepare for AI
→ gửi JSON + optional reference image
→ Import Result
→ Preview
→ Apply
```

Người dùng không cần hiểu patch mechanics, scope internals hay runtime catalog.

## Nguyên tắc sản phẩm

1. Elementor là source of truth.
2. Exact Runtime được tự động hóa trong main AI workflow.
3. Existing Elementor content là read-only source context.
4. AI mutation mặc định delta-first.
5. Native Elementor controls ưu tiên hơn Custom CSS.
6. Parent Container `gap`/`row-gap`/`column-gap` nên sở hữu sibling spacing khi có thể.
7. Full replacement là explicit rebuild action, không phải shortcut.
8. Preview + server-side validation bắt buộc trước Apply.

## UX lịch sử của panel

Ở 0.16, panel Cresco AI có hai tab:

- **Create / Edit** — nhập task, chọn Auto/Edit/Add/Rebuild, optional reference image rồi prepare/copy/download context.
- **Import Result** — paste/drop AI response, preview rồi apply.

Các mode:

- **Auto** — chọn smallest safe delta.
- **Edit** — update/remove/move native settings của existing IDs.
- **Add** — chỉ insert subtree mới.
- **Rebuild** — destructive mode; chỉ cho phép full target replacement khi user chọn rõ.

> Từ 0.24, prompt thiết kế chuyển ra external chat và panel chính chỉ còn Export/Import.

## Schema `cresco-ai-context/v3`

Editor nhận scoped server package, để Exact Runtime enrich, sau đó compile context dành cho AI:

```json
{
  "schema": "cresco-ai-context/v3",
  "aiBrief": "# Cresco AI Task ...",
  "task": {},
  "target": {},
  "currentInterface": {},
  "visualSnapshot": {},
  "layoutGraph": {},
  "designSystem": {},
  "responsive": {},
  "runtime": {},
  "rules": {},
  "outputContract": {},
  "contextQuality": {},
  "sourceContext": {},
  "diagnostics": {}
}
```

Khác biệt quan trọng so với export cũ là **thứ tự thông tin**: task/constraint trước, visual/layout sau, exact control capability tiếp theo, rồi mới tới source/debug context cấp thấp.

## AI Brief

Mỗi package v3 bắt đầu bằng brief ngắn gồm:

- user goal;
- selected target;
- preserve/rebuild policy;
- source element count;
- context quality;
- native-control-first và gap-first rules;
- output contract cần trả.

Mục tiêu là để model hiểu nhiệm vụ trước khi đọc runtime metadata.

## Visual Snapshot

`visualSnapshot` là structured snapshot từ Elementor preview, không nhúng bitmap/base64 vào JSON.

Có thể gồm:

- live viewport width/height/device-pixel-ratio;
- target bounds;
- target computed CSS values;
- số visible Elementor nodes;
- optional reference-image metadata.

Nếu dùng reference image, user attach ảnh riêng trong AI chat. Điều này giữ JSON nhỏ và token-efficient.

## Layout Graph

`layoutGraph` kết hợp persisted Elementor tree với live preview geometry.

Mỗi node có thể ghi:

- `id`;
- parent ID;
- sibling index;
- depth;
- `elType` / `widgetType`;
- inferred container role;
- child count;
- important layout/typography settings;
- rendered bounds;
- computed display/flex/grid/gap/padding/typography/background/border.

AI nhờ đó hiểu cả semantic structure lẫn visual result.

## Runtime compaction

Exact Runtime vẫn là authority. `runtime` chỉ là AI-friendly compact representation của live capability.

Mỗi widget/element detail có thể giữ:

- exact control key;
- type;
- responsive flag;
- default;
- units;
- ranges;
- options;
- conditions;
- selectors;
- Atomic/binding metadata;
- detailed capability loaded status.

AI không được invent key không có trong runtime context.

## Context Quality

Mỗi v3 package có `contextQuality` với score + checks.

Các tín hiệu lịch sử:

- Exact Runtime availability;
- Active Elementor Kit/design system;
- layout graph;
- live target visual metrics;
- source tree;
- exchange safety policy.

Grade:

```text
95–100  Excellent
80–94   Good
65–79   Usable
<65     Incomplete
```

## Output contract

Context không yêu cầu AI tự đoán mutation strategy.

Ở giai đoạn 0.16:

- Add/Edit/Auto thường dùng delta `cresco-layer-patch/v1` như `insert-element`, `update-setting`, `move-element`.
- Rebuild explicit có thể dùng `cresco-layer-ai-result/v1` với `intent: "replace-target"`.

External workflow mới hơn ưu tiên schema theo `resultContract` lớp ngoài cùng; xem `SCHEMA-REFERENCE.md`.

Guard vẫn chặn placeholder:

```text
[TRUNCATED]
[REDACTED]
__cresco_truncated__
```

trước Preview/Apply.

## Import UX

Panel gửi raw AI response tới server normalizer thay vì bắt user tự xóa Markdown fence/wrapper.

Server authority cho:

- schema recognition;
- target/scope validation;
- semantic/runtime validation;
- placeholder blocking;
- internal patch compilation;
- preview diff;
- persistence;
- verification;
- rollback/history.

UI nên trình bày khái niệm dễ hiểu như:

```text
added
updated
moved
replaced
removed
warnings
risk
```

## Reference image

Trong thiết kế 0.16, panel ghi metadata reference image vào package; binary image được attach riêng trong AI conversation.

Flow:

```text
chọn reference image
→ Prepare for AI
→ copy/download JSON
→ gửi JSON + attach ảnh trong AI chat
```

## Compatibility

AI Context v3 được compile trong Elementor editor sau Exact Runtime enrichment nên server-side consumer của scoped package v2 không bị phá.

Legacy `cresco-layer-patch/v1` vẫn đi qua server normalizer.

## File chính

- `assets/ai-context-v3.js` — context compiler, layout graph, live visual metrics, capability compaction, quality score.
- `assets/ai-panel.js` — panel UX.
- `assets/ai-panel.css` — panel styling.
- `assets/exact-runtime-export.js` — live runtime enrichment.
- `includes/AI/ExchangeSafetyGuard.php` — source-context read-only, delta mutation và placeholder guard.
- `includes/Support/Assets.php` — script/style load order.

## Design intent

Người dùng nên nghĩ bằng **giao diện muốn tạo**. Cresco xử lý Elementor internals.

AI nên nghĩ bằng:

```text
visual intent
+ exact available controls
+ smallest required mutation
```

AI không nên reconstruct existing source data, invent settings hay dùng Custom CSS khi native control đã đủ.