# Target Sync Preflight — Cresco Layer 0.24.6

Từ 0.24.6, Cresco Layer coi việc đồng bộ target là **một safety boundary bắt buộc**, không chỉ là tiện ích UX ở browser. Một scoped export không được phép đi vào `PackageBuilder` nếu target chưa được chứng minh là sẵn sàng trong Elementor working document.

## Vấn đề

Elementor Editor có hai phía trạng thái quan trọng:

1. **Editor client** — model đang tồn tại trong trình duyệt và người dùng đang nhìn thấy/chọn nó.
2. **Server working document** — document/autosave mà PHP đọc qua Elementor Documents API.

Một container vừa tạo, duplicate, move, paste, rebuild hoặc vừa thay đổi có thể tồn tại ở client trước khi autosave phía server cập nhật. Ngược lại, một selection cũ có thể vẫn mang ID trong browser/context menu dù live Elementor model đã không còn element đó.

Trước 0.24.6, nếu browser preflight bị bỏ qua hoặc không chạy, `/export` vẫn có thể đi thẳng vào `PackageBuilder` và kết thúc bằng lỗi 500 kiểu:

```text
One or more selected Elementor elements no longer exist.
```

Đây là lỗi phân loại sai: target sync conflict không phải server crash.

## Luồng 0.24.6

```text
User selects Elementor target
→ click Export for ChatGPT
→ Cresco passive click preflight
→ xác nhận target còn tồn tại trong live editor
→ $e.run('document/save/auto', { force: true })
→ GET export-target-status + client_present evidence
→ Target Resolver checks working/autosave + main document
→ bounded retry (max 4 checks)
→ READY
→ release original Export click
→ /documents/{postId}/export
→ server ExportTargetGate kiểm tra lại một lần nữa
→ PackageBuilder chỉ chạy khi target READY
```

Browser preflight là lớp UX đầu tiên. `ExportTargetGate` ở `rest_pre_dispatch` là lớp fail-closed cuối cùng. Vì vậy direct/programmatic request cũng không thể bypass target synchronization.

## Endpoint trạng thái

```text
GET /wp-json/cresco-layer/v1/documents/{postId}/export-target-status
```

Query:

```text
scope=widget|subtree|selection|document
selected=<element-id>
client_present=1|0        # khi browser có thể xác định chắc chắn
```

Response schema vẫn là:

```text
cresco-export-target-status/v1
```

Các trạng thái hiện hành:

- `ready` — target đã có trong working document và có thể export.
- `sync-required` — target có trong main document nhưng working/autosave đang chậm hơn.
- `sync-pending` — live editor xác nhận target còn tồn tại, nhưng server working/main chưa có ID đó.
- `stale-target` — live editor xác nhận target đã không còn tồn tại; phải chọn lại target hiện tại.
- `target-missing` — server không có target và browser không cung cấp được evidence đủ chắc chắn.

`stale-target` không retry. `sync-required`, `sync-pending` và `target-missing` có thể retry bounded sau autosave.

Resolver chỉ đọc dữ liệu. Nó không ghi `_elementor_data`, không gọi `Document::save()` và không dùng client JSON để thay thế dữ liệu canonical.

## Server hard gate

`includes/AI/ExportTargetGate.php` chạy trước callback `/export`.

Nếu target chưa ready:

```text
sync-required / sync-pending / target-missing → HTTP 409
stale-target                                  → HTTP 410
```

Response dùng code:

```text
cresco_export_target_not_ready
```

và kèm `targetStatus` đầy đủ. Những lỗi này **không được** kích hoạt Full → Smart context recovery, vì đổi context profile không thể chữa target mismatch.

Nhờ đó lỗi target sync không còn bị báo như generic HTTP 500 và không còn retry vô ích bằng `context=smart`.

## UX

Người dùng bình thường chỉ cần:

```text
Select element
→ Export for ChatGPT
```

Trong lúc đồng bộ, nút hiển thị:

```text
Synchronizing Elementor...
```

Nếu target đang pending, Cresco force autosave và kiểm tra lại có giới hạn. Nếu target stale, Cresco dừng ngay và yêu cầu chọn lại element hiện tại thay vì export dữ liệu cũ.

Nếu sync không thể hoàn tất, Cresco dừng export thay vì gửi package dựa trên state không nhất quán.

## Safe Bootstrap

`assets/export-target-sync.js` vẫn được phép load cùng bootstrap vì nó chỉ cài **một delegated click listener**. Nó không:

- dùng `MutationObserver`;
- dùng `setInterval`;
- monkey-patch `window.fetch`;
- gọi REST khi editor vừa khởi động.

Autosave và status request chỉ chạy sau khi người dùng chủ động bấm Export.

`assets/export-error-diagnostics.js` có thể bổ sung `client_present` vào request `/export` đã có để server hard gate nhận cùng live-editor evidence, nhưng target-sync module bản thân vẫn không monkey-patch fetch.

## Diagnostics

Console API:

```js
window.CrescoLayerExportTargetSync?.getState()
window.CrescoLayerExportDiagnostics?.getLastError()
```

Target diagnostic mới có thể hiển thị:

```text
Stage: target-sync-gate
HTTP: 409
Target: sync-pending
```

hoặc:

```text
Stage: target-sync-gate
HTTP: 410
Target: stale-target
```

`crescoDiagnostic.postId` được resolve từ URL route parameter ngay ở `rest_pre_dispatch`, vì vậy route `/documents/3/export` phải ghi `postId: 3`, không còn `postId: 0` như diagnostic cũ.

Các trường hữu ích:

```text
lastAutosave
lastTarget
lastScope
lastStatus.state
lastStatus.clientPresent
lastStatus.working
lastStatus.main
lastError
```

Nhờ đó có thể phân biệt chính xác:

```text
live target exists + server missing       → sync-pending
live target gone                          → stale-target
main exists + working autosave missing    → sync-required
server/client evidence insufficient       → target-missing
```
