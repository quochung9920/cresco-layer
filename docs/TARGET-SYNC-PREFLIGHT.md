# Target Sync Preflight — Cresco Layer 0.24.1

Từ 0.24.1, Cresco Layer không còn xem lỗi `selected element not found` như một lỗi người dùng phải tự xử lý bằng cách chọn lại element nhiều lần.

## Vấn đề

Elementor Editor có hai phía trạng thái quan trọng:

1. **Editor client** — model đang tồn tại trong trình duyệt và người dùng đang nhìn thấy/chọn nó.
2. **Server working document** — document/autosave mà PHP đọc qua Elementor Documents API.

Một container vừa tạo, duplicate, move hoặc vừa thay đổi có thể tồn tại ở client trước khi autosave phía server cập nhật. Nếu Cresco export ngay lúc đó, client gửi đúng ID nhưng `get_doc_or_auto_save()` có thể chưa chứa ID này.

## Luồng mới

```text
User selects Elementor target
→ click Export for ChatGPT
→ Cresco passive click preflight
→ $e.run('document/save/auto', { force: true })
→ GET export-target-status
→ Target Resolver checks working/autosave + main document
→ bounded retry (max 4 checks)
→ READY
→ release the original Export click
→ Exact Runtime / Full Context / Bundle pipeline
```

Cresco không publish page và không tự gọi Update/Publish. Lệnh được dùng là autosave của Elementor để đồng bộ working data phục vụ export.

## Target status schema

Endpoint:

```text
GET /wp-json/cresco-layer/v1/documents/{postId}/export-target-status
```

Query:

```text
scope=widget|subtree|selection|document
selected=<element-id>
```

Response:

```text
cresco-export-target-status/v1
```

Các trạng thái:

- `ready` — target đã có trong working document mà export sẽ đọc.
- `sync-required` — target có trong main document nhưng working/autosave đang chậm hơn.
- `client-ahead` — target chưa có trong dữ liệu server; editor client nhiều khả năng đang đi trước autosave.

Resolver chỉ đọc dữ liệu. Nó không ghi `_elementor_data`, không gọi `Document::save()` và không dùng client JSON để thay thế dữ liệu canonical.

## UX

Người dùng chỉ cần:

```text
Select element
→ Export for ChatGPT
```

Trong lúc đồng bộ, nút tạm thời hiển thị:

```text
Synchronizing Elementor...
```

Nếu sync thành công, Cresco tự tiếp tục export.

Nếu sync không thể hoàn tất, Cresco dừng export thay vì gửi dữ liệu cũ cho ChatGPT. Thông báo nói rõ rằng element không bị thay đổi và gợi ý Save/Update một lần nếu Elementor autosave API không khả dụng hoặc bị lỗi.

## Safe Bootstrap

`assets/export-target-sync.js` được phép load cùng bootstrap vì nó chỉ cài **một delegated click listener**. Nó không:

- dùng `MutationObserver`;
- dùng `setInterval`;
- monkey-patch `window.fetch`;
- gọi REST khi editor vừa khởi động.

Autosave và status request chỉ chạy sau khi người dùng chủ động bấm một trong hai nút Export.

## Diagnostics

Trong console có thể xem trạng thái gần nhất bằng:

```js
window.CrescoLayerExportTargetSync?.getState()
```

Các trường chính:

```text
lastAutosave
lastTarget
lastScope
lastStatus.working
lastStatus.main
lastError
```

Nhờ đó có thể phân biệt chính xác:

```text
client target exists
working autosave missing
main document exists
```

thay vì chỉ nhận thông báo chung chung rằng element "không còn tồn tại".
