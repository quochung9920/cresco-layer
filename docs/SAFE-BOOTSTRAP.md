# Safe Bootstrap cho Elementor Editor

Tài liệu này mô tả cơ chế bảo vệ editor của Cresco Layer 0.24+.

## Mục tiêu

Elementor phải luôn có quyền khởi động trước Cresco. Cresco không được chạy runtime scanner, visual capture, AI context builder, verification, polling hoặc DOM observer trong giai đoạn Elementor đang dựng editor.

Luồng chuẩn:

```text
WordPress / Elementor editor request
→ nạp editor-bootstrap.js
→ nạp export-target-sync.js ở trạng thái passive (một click listener, không REST/polling)
→ chờ Elementor ready
→ tạo launcher Cresco + context-menu entry nhẹ
→ không nạp AI/runtime module nặng nào khác
→ người dùng bấm Cresco
→ lazy-load external exchange pipeline
→ mở Export / Import panel
```

## Những gì không còn chạy khi Elementor startup

Các module sau không còn được `wp_enqueue_script()` trực tiếp trong editor startup:

```text
editor.js
exact-runtime-export.js
fidelity-engine.js
fidelity-export.js
ai-context-v3.js
external-ai-intelligence.js
design-intelligence.js
semantic-design-contract.js
design-reasoning.js
ai-context-policy.js
ai-bundle.js
external-ai-exchange-policy.js
visual-verification.js
fidelity-verification.js
ai-panel.js
semantic-design-ui.js
skills.js
skills-accuracy.js
semantic-ai.js
```

Các file legacy vẫn được giữ trong repository để tương thích/test, nhưng không còn nằm trên critical startup path.

## Startup-safe scripts

Hai script được phép tồn tại trên editor shell trước khi user mở exchange:

```text
editor-bootstrap.js
export-target-sync.js
```

`export-target-sync.js` chỉ cài một delegated click listener cho hai nút Export. Trước khi user click Export, file này:

- không gọi REST;
- không gọi Elementor autosave;
- không dùng `MutationObserver`;
- không dùng `setInterval`;
- không monkey-patch `window.fetch`;
- không scan controls/computed styles.

Vì vậy Target Sync không đưa workload cũ quay lại critical startup path.

## `editor-bootstrap.js`

Bootstrap có các quy tắc bắt buộc:

- không `MutationObserver`;
- không `setInterval`;
- không monkey-patch `window.fetch`;
- không gọi REST export/import khi khởi động;
- không scan Elementor controls;
- không đọc computed styles;
- không tạo Skills profile;
- chỉ dùng `elementor/init`, trạng thái runtime hiện có và một watchdog timeout duy nhất;
- nếu Elementor không ready trong budget, Cresco chuyển sang `passive-timeout` và dừng tác động.

Mặc định budget:

```text
8000 ms
```

Đây không phải retry loop. Hết budget thì Cresco tự dừng.

## Lazy external exchange

Sau khi người dùng chủ động mở Cresco, bootstrap nạp các module external exchange theo thứ tự xác định. Chỉ lúc này Exact Runtime, Fidelity, AI Context, bundle và verification mới được cài đặt.

Điều này tách rõ:

```text
Elementor availability
!=
Cresco exchange availability
```

Nếu exchange module lỗi, Elementor vẫn phải tiếp tục dùng được.

## Emergency Safe Mode

Nếu cần mở Elementor mà không nạp bất kỳ editor JavaScript/CSS nào của Cresco, thêm:

```text
&cresco_safe=1
```

Ví dụ:

```text
/wp-admin/post.php?post=22&action=elementor&cresco_safe=1
```

Khi flag này tồn tại:

- Cresco không enqueue editor bootstrap;
- Cresco không enqueue export target sync guard;
- Cresco không enqueue AI panel CSS;
- không có runtime scanner/observer/timer/fetch wrapper từ Cresco trên editor shell;
- frontend widget CSS vẫn có thể tồn tại ở preview/front-end vì nó là lớp render widget, không phải editor bootstrap.

Safe Mode là đường cứu editor, không phải chế độ làm việc bình thường.

## Diagnostics

Khi bootstrap chạy bình thường:

```js
window.CrescoLayerSafeBootstrap.getState()
```

có thể trả các trạng thái:

```text
booting
ready
loading-exchange
exchange-error
passive-timeout
safe-mode
```

`passive-timeout` có nghĩa Cresco đã tự dừng để không cản Elementor.

Target Sync có diagnostics riêng:

```js
window.CrescoLayerExportTargetSync?.getState()
```

## Nguyên tắc phát triển tiếp theo

Mọi feature mới của Cresco phải được phân loại:

```text
startup-safe
user-triggered
post-import verification
legacy/admin only
```

Chỉ code thực sự `startup-safe` mới được phép enqueue trước khi người dùng mở Cresco. Code user-triggered có thể có một listener passive ở startup, nhưng mọi REST request, autosave, runtime scan, visual capture và AI processing phải chờ hành động rõ ràng của người dùng.
