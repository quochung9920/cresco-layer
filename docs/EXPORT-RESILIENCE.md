# Export Resilience — Cresco Layer 0.24.3

Cresco Layer giữ nguyên workflow external-first:

```text
Elementor
→ Export
→ ChatGPT / AI bên ngoài
→ Import
```

Bản 0.24.3 tập trung làm export **nhẹ hơn, có khả năng phục hồi và dễ chẩn đoán hơn**.

## Những thay đổi chính

1. Server vẫn giữ toàn bộ Elementor registry dưới dạng **lightweight index**, nhưng detailed capability hydration chỉ tập trung vào target/context hiện tại + construction set có giới hạn.
2. Dynamic Tags export chỉ dùng metadata cần thiết; không gọi `get_controls()`/`get_editor_config()` hàng loạt cho mọi tag.
3. Module export đọc tên/số lượng từ manager thay vì instantiate toàn bộ module.
4. Exact Runtime reuse detailed capabilities server đã trả, chỉ fetch phần thiếu và dùng tối đa hai worker.
5. Capability bắt buộc của editable target/context vẫn **fail-closed**. Capability construction optional lỗi thì được ghi nhận và bỏ qua thay vì làm chết toàn package.
6. `export-target-status` được loại rõ khỏi Exact Runtime export interceptor.
7. Nếu read-only Full Context export gặp server 5xx, client retry **một lần** bằng bounded Smart server context. Exact Runtime vẫn enrich/validate retry thành công. Bundle ghi `manifest.exportRecovery`.
8. Export failure hiển thị diagnostic card ngay trong Cresco panel: stage, error ID, HTTP status, memory/fatal detail nếu có, cùng action **Copy diagnostics**.

## Safety rules

Recovery chỉ được phép khi:

```text
request method = GET
context = full
server response = 5xx
```

Các giới hạn:

- tối đa một recovery retry với `cresco_recovery=1`;
- không hạ tiêu chuẩn nếu Exact Runtime thiếu required target capability;
- AI chỉ được dùng element/widget type có trong `runtimeCapabilities`;
- existing/editable target type không được silently truncate.

## Vì sao cần bounded detail?

Full registry và full control hydration là hai việc khác nhau.

```text
Full registry awareness
→ AI biết type nào tồn tại

Detailed hydration
→ chỉ tải control stack khi cần
```

Nếu hydrate toàn bộ controls của Elementor Core + Pro + addon trong một PHP request, package có thể gặp:

- memory exhaustion;
- timeout;
- JSON serialization failure;
- browser/network overhead lớn.

0.24.3 giữ awareness rộng nhưng detail có budget để tránh các failure này.

## Auto recovery

Flow:

```text
Full Context request
→ server 5xx
→ retry một lần với bounded Smart context
→ Exact Runtime enrich/validate
→ nếu required capability đầy đủ: tiếp tục export
→ nếu không: dừng
```

Recovery thành công được ghi:

```text
recovered-smart-context
```

và giữ thông tin failure đầu tiên như stage/error ID để debug.

## Diagnostics

Console API vẫn có:

```js
window.CrescoLayerExportDiagnostics?.getLastError()
window.CrescoLayerExportDiagnostics?.getHistory()
window.CrescoLayerExportDiagnostics?.copyLastError()
window.CrescoLayerExactRuntimeExport?.getDiagnostics()
```

UI cũng phải hiển thị diagnostics để người dùng không cần mở Console trong normal support flow.

## Nguyên tắc thiết kế

Export resilience không được đạt được bằng cách cho AI đoán nhiều hơn.

Thứ tự ưu tiên:

```text
resource safety
+ exact required target capability
+ explicit diagnostics
+ bounded retry
```

Nếu không thể chứng minh capability bắt buộc, Cresco dừng export thay vì tạo package có vẻ thành công nhưng không đủ an toàn để AI sửa Elementor.