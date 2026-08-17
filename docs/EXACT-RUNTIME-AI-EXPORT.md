# Exact Runtime AI Export

Exact Runtime là chế độ enrich AI export bằng capability thực tế của Elementor runtime đang mở trong editor.

Mục tiêu: AI không chỉ biết “đây là widget heading/button/container”, mà còn biết **control nào thật sự tồn tại trong installation hiện tại**.

## Exact Runtime bổ sung gì?

Export scoped bình thường được enrich bằng detailed capability của:

- editable/context types;
- construction set có giới hạn;
- các type được runtime chứng minh là đang đăng ký.

Metadata có thể gồm:

```text
exact setting keys
defaults
responsive flags
allowed units
ranges
options
conditions
selectors
Atomic bindings / prop schema
```

Exact Runtime không invent metadata từ trí nhớ hoặc từ một Elementor version khác.

## Các vùng dữ liệu chính

Package có thể được bổ sung:

```text
runtimeCapabilities
capabilityLock
siteDesignContext
```

### `runtimeCapabilities`

Detailed runtime entries đã được chứng minh.

### `capabilityLock`

Khóa các nguyên tắc:

- không invent control key;
- không invent responsive suffix;
- không dùng unit/option/range ngoài runtime metadata;
- `custom_css` chỉ là fallback khi native control không đủ.

### `siteDesignContext`

Tóm tắt Active Kit/global design context để AI reuse design language hiện tại.

## Fail-closed

Nếu capability **bắt buộc** của selected target/context đã registered nhưng không thể load đáng tin cậy, Exact Runtime phải fail-closed thay vì cho AI đoán.

Capability optional phục vụ construction có thể fail-soft khi contract hiện tại cho phép và phải được ghi rõ trong coverage/diagnostics.

## Resource safety từ 0.24.3

Implementation hiện tại không nên tải lại mọi detail đã có.

Pipeline mong muốn:

```text
server bounded context
→ reuse detailed capability server đã trả
→ xác định capability còn thiếu
→ fetch phần thiếu với worker/fetch budget
→ fail-closed required capability
→ fail-soft optional capability
```

Điều này giảm double-enrich, số REST request và nguy cơ PHP/browser bị quá tải.

## Vai trò

Exact Runtime trả lời câu hỏi:

> “Với Elementor/Core/Pro/addon đang chạy **ngay lúc này**, AI thực sự được phép dùng những control nào?”

Nó không thay Site Settings, không thay Widget Intelligence và không tự apply thay đổi. Nó là nguồn bằng chứng runtime cho các compiler/validator tiếp theo.