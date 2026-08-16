# Quy trình External AI của Cresco Layer

Tài liệu này mô tả workflow chính từ Cresco Layer 0.24 trở đi.

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
→ rendered verification
→ Fidelity Gate
```

Elementor vẫn là nguồn sự thật cho document, widget, control, breakpoint, globals, render và persistence.

## 2. Trải nghiệm người dùng

### Bước 1 — Chọn phạm vi trong Elementor

Mở trang bằng Elementor và mở **Cresco Export / Import**.

Người dùng chọn một trong ba scope:

- **Selected element** — chỉ chỉnh element đang chọn;
- **Selected subtree** — chỉnh root và các descendant nằm trong subtree;
- **Entire page** — export toàn document.

Với Entire page, không bắt buộc phải chọn element.

### Bước 2 — Export cho ChatGPT

Bấm **Export for ChatGPT**.

Cresco tự thực hiện:

- đọc Elementor runtime thật;
- dùng Full Context profile cho external AI để không phụ thuộc task hint viết trong Elementor;
- export detailed capability cho widget/element đã đăng ký;
- export normalized Control Registry;
- export active Kit/global colors/global fonts;
- export responsive breakpoints;
- export layout/relationship context;
- export Dynamic Tags và runtime metadata có thể serializable;
- enrich bằng computed visual context/Fidelity snapshot của preview hiện tại;
- cố gắng capture raster preview của target;
- đóng gói output contract và hướng dẫn cho ChatGPT.

Người dùng có hai lựa chọn:

- **Export for ChatGPT** — ZIP đầy đủ, khuyến nghị;
- **JSON only** — một file JSON tự mô tả, dùng khi không cần raster/reference image.

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

`context` giữ nguyên Cresco AI Context v3 để ChatGPT có toàn bộ nguồn dữ liệu kỹ thuật cần thiết.

### `instructionsForAI`

Đây là hướng dẫn machine-readable để AI biết rằng:

- package là nguồn sự thật cho Elementor runtime;
- yêu cầu thiết kế đến từ cuộc trò chuyện bên ngoài Elementor;
- không được invent control, responsive suffix, unit, option, Dynamic Tag hoặc global reference;
- phải giữ ID và scope;
- ưu tiên native Elementor controls;
- chỉ trả delta cần thiết;
- trả JSON thuần hoặc file JSON.

### `resultContract`

Khai báo:

- schema kết quả ưu tiên;
- các schema import được Cresco chấp nhận;
- filename gợi ý;
- quy tắc giữ target;
- quy tắc không bọc JSON bằng prose/markdown fence.

Schema kết quả ưu tiên hiện tại:

```text
cresco-ai-mutation/v3
```

Các schema vẫn được import để tương thích:

```text
cresco-ai-mutation/v3
cresco-ai-mutation/v2
cresco-layer-patch/v1
cresco-layer-ai-result/v1
```

## 4. Full ZIP Bundle

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

Đây là entrypoint dành cho AI. ChatGPT nên đọc file này trước, sau đó đọc `cresco-package.json`.

### `current-preview.png`

Best-effort raster capture của target hiện tại. Nếu raster không thể tạo đáng tin cậy, bundle vẫn hợp lệ vì structured visual context và computed geometry vẫn còn.

### Reference image

Nếu người dùng chọn reference image khi export, ảnh được nhúng vào ZIP. Người dùng cũng có thể bỏ qua bước này và attach ảnh trực tiếp trong cuộc trò chuyện ChatGPT.

## 5. Người dùng làm gì trong ChatGPT?

Sau khi upload package, người dùng chỉ cần nói yêu cầu bằng ngôn ngữ tự nhiên, ví dụ:

```text
Đây là package Cresco Layer của hero hiện tại.
Hãy thiết kế lại hero theo phong cách luxury tối giản.
Giữ nguyên nội dung chính, dùng Global Colors/Fonts nếu phù hợp,
tối ưu desktop/tablet/mobile và trả file JSON để tôi import lại vào Cresco Layer.
```

Người dùng không cần giải thích:

- Elementor setting key;
- responsive suffix;
- unit;
- control availability;
- schema chi tiết;
- ID nào cần giữ.

Package đã mang các contract đó cho AI.

## 6. ChatGPT phải trả gì?

Kết quả lý tưởng là một file như:

```text
cresco-ai-result-54c33fb8.json
```

Nội dung ưu tiên dùng:

```text
cresco-ai-mutation/v3
```

Cresco không yêu cầu người dùng sửa tay JSON do model thêm markdown fence hoặc wrapper thông dụng; server normalizer vẫn cố gắng nhận dạng các dạng output hợp lệ đã hỗ trợ. Tuy nhiên package hướng dẫn AI trả JSON sạch để giảm lỗi.

## 7. Import trở lại Elementor

Mở tab **Import AI Result**.

1. Chọn lại target gốc nếu scope không phải Entire page.
2. Chọn Expected Scope tương ứng.
3. Kéo thả file JSON ChatGPT trả về.
4. Bấm **Preview Changes**.
5. Chỉ khi preview hợp lệ, nút **Apply to Elementor** mới được bật.

Nếu JSON có `target.id` và target đó khác element đang chọn, UI chặn import sớm và yêu cầu chọn đúng target gốc.

## 8. Các lớp validation khi import

Cresco vẫn áp dụng toàn bộ safety pipeline hiện có:

```text
AI result normalization
→ semantic/design lowering nếu cần
→ internal patch compilation
→ schema/security validation
→ scope validation
→ runtime capability validation
→ semantic safety analysis
→ preview diff
→ apply qua Elementor document API
→ read-back verification
→ rendered verification
→ Fidelity Score / Gate
```

External workflow **không làm yếu validation**. Nó chỉ chuyển nơi AI suy luận giao diện từ Elementor sang ChatGPT bên ngoài.

## 9. Vì sao Full Context là mặc định cho external export?

Trước đây Cresco có thể dùng task hint nhập trong Elementor để lazy-load capability đúng với yêu cầu. External workflow cố ý bỏ prompt khỏi Elementor.

Nếu vẫn dùng task-aware Smart Context, ChatGPT có thể muốn thêm một widget chưa xuất hiện trong target nhưng package lại không có detailed control metadata của widget đó.

Vì vậy external export dùng **Full Context profile** để ưu tiên độ chính xác và khả năng tạo giao diện hơn kích thước package.

Đây là trade-off có chủ đích:

- package lớn hơn;
- export có thể tốn nhiều thời gian hơn;
- nhưng ChatGPT ít phải đoán hơn và có thể xử lý nhiều yêu cầu thiết kế hơn sau khi file đã rời Elementor.

## 10. Quy tắc không thay đổi

Dù dùng ChatGPT, Claude, Gemini hay model khác:

- Elementor là source of truth;
- AI không được invent runtime capability;
- AI không được thoát editable scope;
- unknown persisted field phải được preserve nếu không phải mục tiêu sửa;
- native controls ưu tiên hơn custom CSS;
- Global styles ưu tiên hơn local near-duplicate;
- không có rendered evidence thì không được coi là Fidelity PASS;
- Update/Publish cuối cùng vẫn do người dùng quyết định trong Elementor.
