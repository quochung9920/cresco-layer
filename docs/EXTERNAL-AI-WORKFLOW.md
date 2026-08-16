# Quy trình External AI của Cresco Layer

Tài liệu này mô tả workflow chính từ **Cresco Layer 0.24 — External AI Bridge** trở đi.

## 1. Triết lý sản phẩm

Cresco Layer **không phải chatbot nằm trong Elementor** và không yêu cầu người dùng viết prompt thiết kế trước khi export.

Vai trò của Cresco Layer là làm cầu nối file-based giữa Elementor và một AI bên ngoài như ChatGPT:

```text
Elementor
→ Cresco Export
→ file/package tự mô tả
→ ChatGPT xử lý giao diện
→ file JSON kết quả
→ Cresco Import
→ validation
→ preview
→ Elementor apply
→ read-back verification
→ rendered verification
→ Fidelity Gate
```

Elementor vẫn là nguồn sự thật cho document, widget, control, breakpoint, globals, render và persistence.

## 2. Trải nghiệm người dùng

### Bước 1 — Mở Cresco Export / Import

Trong Elementor, người dùng có thể mở workflow từ:

- nút nổi **Cresco Export / Import**;
- context menu của element: **Cresco - Export to ChatGPT**;
- context menu: **Cresco - Import AI Result**.

Các entrypoint legacy kiểu “Edit with AI”, “Edit section + children” và “AI selection” không còn là UX chính. Chúng được redirect/replace bằng External AI Exchange.

### Bước 2 — Chọn phạm vi export

Panel có ba scope:

- **Selected element** — chỉ chỉnh element đang chọn;
- **Selected subtree** — chỉnh root và descendants nằm trong subtree;
- **Entire page** — export toàn document.

Với Entire page, không bắt buộc phải chọn element.

### Bước 3 — Export cho ChatGPT

Bấm **Export for ChatGPT**.

Cresco tự thực hiện:

- đọc Elementor runtime thật;
- ép Exact Runtime;
- dùng Full Context profile cho external AI để không phụ thuộc task hint viết trong Elementor;
- export detailed capability của widget/element đã đăng ký;
- export normalized Control Registry;
- export active Kit/Global Colors/Global Fonts;
- export responsive breakpoints;
- export layout/relationship context;
- export Dynamic Tags và runtime metadata có thể serializable;
- enrich bằng computed visual context/Fidelity snapshot của preview hiện tại;
- cố gắng capture raster preview của target;
- chuẩn hóa output contract theo scope;
- đóng gói hướng dẫn tự mô tả cho ChatGPT.

Người dùng có hai lựa chọn:

- **Export for ChatGPT** — ZIP đầy đủ, khuyến nghị;
- **JSON only** — một file JSON tự mô tả, dùng khi không cần raster/reference image.

Không có trường bắt buộc “hãy mô tả AI cần làm gì” trong Elementor.

## 3. External AI Package

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

`context` giữ Cresco AI Context v3 để ChatGPT có nguồn dữ liệu kỹ thuật đầy đủ.

### `instructionsForAI`

AI được nhắc rõ:

- package/runtime là source of truth;
- yêu cầu thiết kế đến từ cuộc trò chuyện bên ngoài Elementor;
- không invent control, responsive suffix, unit, option, Dynamic Tag hoặc global reference;
- giữ scope/target;
- giữ existing IDs khi chỉnh element hiện có;
- với node mới, ưu tiên temporary ref để Cresco cấp final ID;
- native Elementor controls ưu tiên hơn custom CSS;
- chỉ trả intended delta;
- trả JSON thuần hoặc file JSON.

## 4. External Exchange Policy

Schema:

```text
cresco-external-exchange-policy/v1
```

Policy ở lớp ngoài cùng chuẩn hóa kết quả AI theo loại scope.

### Selected element / Selected subtree

Preferred output:

```text
cresco-ai-mutation/v3
```

Lý do:

- có root Elementor cụ thể;
- semantic design intent có thể được Cresco lower xuống control thật;
- Cresco có thể cấp ID cho node mới;
- model không cần viết setting-level patch nếu intent vocabulary đã biểu đạt được yêu cầu.

Mode:

```text
semantic-target-mutation
```

### Entire page

Preferred output:

```text
cresco-layer-patch/v1
```

Scope bắt buộc:

```json
{
  "mode": "document",
  "rootElementId": "",
  "elementIds": []
}
```

Lý do: `cresco-ai-mutation/v3` hiện được thiết kế quanh một root Elementor cụ thể. Một document không phải một Container giả có ID rỗng, nên Cresco không ép toàn trang vào semantic root model đó.

Mode:

```text
document-patch
```

Document patch có thể:

- update setting của bất kỳ existing element thuộc document;
- insert/move/remove element theo document scope;
- insert top-level element bằng `parentId: ""`;
- update page settings nếu contract cho phép;
- dùng `replace-document` chỉ khi người dùng thực sự yêu cầu full rebuild.

Với top-level insertion, AI có thể viết:

```json
{
  "operation": "insert-element",
  "parentId": "",
  "position": 999999,
  "element": {
    "ref": "$new:hero",
    "elType": "container",
    "settings": {},
    "elements": []
  }
}
```

Cresco sẽ cấp ID Elementor collision-free cho inserted subtree trong import pipeline.

## 5. Full ZIP Bundle

Bundle dùng schema:

```text
cresco-ai-bundle/v4
```

Tên file dạng:

```text
cresco-chatgpt-bundle-<target>.zip
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

### `README-FOR-CHATGPT.md`

Entrypoint dành cho AI. ChatGPT nên đọc file này trước, sau đó đọc `cresco-package.json`.

### `cresco-package.json`

Package machine-readable chính. `resultContract` ở đây là contract bên ngoài mà AI phải ưu tiên.

### `elementor-context.json`

Context kỹ thuật chi tiết. Có thể chứa template/contract nội bộ cũ phục vụ compiler. Nếu có khác biệt với `cresco-package.json.resultContract`, **resultContract lớp ngoài cùng thắng**.

### `current-preview.png`

Best-effort raster capture của target hiện tại. Nếu raster không thể tạo đáng tin cậy, bundle vẫn hợp lệ vì structured visual context và computed geometry vẫn còn.

### Reference image

Nếu người dùng chọn reference image khi export, ảnh được nhúng vào ZIP. Người dùng cũng có thể attach ảnh trực tiếp trong cuộc trò chuyện ChatGPT.

## 6. Người dùng làm gì trong ChatGPT?

Sau khi upload package, chỉ cần nói yêu cầu bằng ngôn ngữ tự nhiên.

Ví dụ element/subtree:

```text
Đây là package Cresco Layer của hero hiện tại.
Hãy thiết kế lại hero theo phong cách luxury tối giản.
Giữ nguyên nội dung chính, dùng Global Colors/Fonts nếu phù hợp,
tối ưu desktop/tablet/mobile và trả file JSON để tôi import lại vào Cresco Layer.
```

Ví dụ Entire page:

```text
Đây là package Cresco Layer của toàn bộ landing page.
Hãy cải thiện hierarchy, spacing và responsive của toàn trang.
Giữ các nội dung và integration hiện có nếu không cần thay đổi.
Ưu tiên delta thay vì replace-document và trả file JSON theo resultContract trong package.
```

Người dùng không cần giải thích setting key, responsive suffix, unit, control availability, schema chi tiết hay cách cấp ID.

## 7. ChatGPT phải trả gì?

Tên file gợi ý:

```text
cresco-ai-result-<target>.json
```

Preferred schema được ghi trực tiếp trong `resultContract`:

```text
widget/subtree → cresco-ai-mutation/v3
document       → cresco-layer-patch/v1
```

Các schema Cresco vẫn có thể normalize/import khi phù hợp:

```text
cresco-ai-mutation/v3
cresco-ai-mutation/v2
cresco-layer-patch/v1
cresco-layer-ai-result/v1
```

Cresco vẫn chịu được một số wrapper/code fence phổ biến từ AI, nhưng package yêu cầu JSON sạch để giảm lỗi.

## 8. Import trở lại Elementor

Mở tab **Import AI Result**.

1. Chọn lại target gốc nếu scope không phải Entire page.
2. Chọn Expected Scope tương ứng.
3. Kéo thả file JSON ChatGPT trả về.
4. Bấm **Preview Changes**.
5. Xem số lượng add/update/move/replace/remove, warnings và auto-repair.
6. Chỉ khi preview hợp lệ, nút **Apply to Elementor** mới được bật.

Nếu JSON có `target.id` và target đó khác element đang chọn, UI chặn import sớm và yêu cầu chọn đúng target gốc.

## 9. Các lớp validation khi import

External workflow không làm yếu safety pipeline:

```text
AI result normalization
→ semantic/design lowering nếu cần
→ internal patch compilation
→ ID allocation cho inserted subtree
→ schema/security validation
→ expected scope validation
→ runtime capability validation
→ semantic safety analysis
→ preview diff
→ apply qua Elementor document API
→ read-back verification
→ rendered verification
→ Fidelity Score / Gate
```

Một file parse JSON thành công chưa có nghĩa là được Apply.

## 10. Vì sao Full Context là mặc định?

Trước đây Cresco có thể dùng task hint nhập trong Elementor để lazy-load capability đúng với yêu cầu. External workflow cố ý bỏ prompt khỏi Elementor.

Nếu vẫn dùng task-aware Smart Context, ChatGPT có thể muốn thêm một widget chưa xuất hiện trong target nhưng package không có detailed control metadata của widget đó.

Vì vậy external export dùng **Full Context profile**.

Sau đó Exact Runtime lấy type set từ document/context **và toàn bộ `widgetCatalog` / `elementCatalog` đã được Full Context điền chi tiết**. Kết quả là bundle lớn hơn nhưng ít buộc ChatGPT phải đoán khi người dùng thay đổi yêu cầu sau khi file đã rời Elementor.

Trade-off có chủ đích:

- package lớn hơn;
- export có thể chậm hơn;
- nhưng capability coverage tốt hơn;
- file có thể tái sử dụng cho nhiều yêu cầu thiết kế hơn trong cùng trạng thái Elementor.

## 11. Fidelity sau import

Cresco 0.24 giữ nguyên Fidelity Foundation 0.23.

Sau Apply:

```text
Elementor render
→ computed geometry/style capture
→ visual verification
→ Fidelity Score
→ PASS / BLOCKED
```

Không có rendered evidence không được coi là PASS.

## 12. Quy tắc không thay đổi

Dù dùng ChatGPT, Claude, Gemini hay model khác:

- Elementor là source of truth;
- AI không được invent runtime capability;
- AI không được thoát editable scope;
- unknown persisted field phải được preserve nếu không phải mục tiêu sửa;
- native controls ưu tiên hơn custom CSS;
- Global Styles ưu tiên hơn local near-duplicate;
- destructive document replacement phải là chủ đích rõ ràng;
- không có rendered evidence thì không được coi là Fidelity PASS;
- Update/Publish cuối cùng vẫn do người dùng quyết định trong Elementor.
