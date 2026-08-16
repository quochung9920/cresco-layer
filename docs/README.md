# Tài liệu Cresco Layer

Bộ tài liệu này là tài liệu tiếng Việt ưu tiên cho **Cresco Layer 0.23+**.

Cresco Layer là lớp trung gian giữa AI và Elementor. Plugin không thay Elementor bằng một document model riêng; nó đọc runtime thật, đóng gói dữ liệu cần thiết cho AI, kiểm tra patch bằng capability thật, lưu qua API của Elementor và đo lại kết quả render.

## Bản đồ tài liệu

| Tài liệu | Dùng khi |
|---|---|
| [KIEN-TRUC-HE-THONG.md](KIEN-TRUC-HE-THONG.md) | Cần hiểu toàn bộ kiến trúc, module và luồng dữ liệu |
| [AI-EXPORT-IMPORT.md](AI-EXPORT-IMPORT.md) | Tích hợp AI, viết agent/prompt hoặc debug export/import |
| [FIDELITY-ENGINE.md](FIDELITY-ENGINE.md) | Làm việc với computed snapshot, geometry graph, score và verification gate |
| [SITE-SETTINGS.md](SITE-SETTINGS.md) | Import/sync Elementor Global Settings và responsive foundation |
| [SCHEMA-REFERENCE.md](SCHEMA-REFERENCE.md) | Tra cứu nhanh schema và contract quan trọng |
| [PHAT-TRIEN-KIEM-THU.md](PHAT-TRIEN-KIEM-THU.md) | Phát triển plugin, thêm module, chạy quality gate và test |

## Nguyên tắc đọc tài liệu

Cresco Layer có ba lớp dữ liệu cần phân biệt rõ:

1. **Persisted Elementor data** — dữ liệu Elementor lưu trong document/Kit.
2. **Runtime capability** — control/widget/element thật đang được đăng ký ở phiên bản Elementor hiện tại.
3. **Rendered evidence** — geometry/computed styles thật sau khi browser render preview.

Một kết quả chỉ “đúng JSON” chưa đủ. Độ tin cậy tăng dần theo chuỗi:

```text
JSON hợp lệ
→ đúng scope
→ đúng runtime control
→ Elementor lưu thành công
→ đọc lại đúng giá trị
→ render đúng intent
→ Fidelity Gate đạt chuẩn
```

## Các schema chính

```text
cresco-layer-ai-package/v2
cresco-control-registry/v1
cresco-layer-patch/v1
cresco-layer-patch-validation/v2
cresco-site-settings/v1
cresco-fidelity-policy/v1
cresco-fidelity-snapshot/v1
cresco-geometry-graph/v1
cresco-fidelity-report/v1
cresco-fidelity-gate/v1
```

## Quy ước chất lượng

- Không tự phát minh Elementor control.
- Không sửa element ngoài editable scope.
- Không ghi trực tiếp vào storage khi Elementor có Document/Kit API phù hợp.
- Không xem `save() === true` là đủ; cần read-back verification.
- Không coi “không có evidence” là pass.
- Không tuyên bố pixel-perfect tuyệt đối giữa các browser/OS khác nhau.
- Ưu tiên native Elementor controls trước custom CSS.
- Ưu tiên global design-system values trước local near-duplicate values.
- Preserve unknown persisted Elementor fields nếu chúng không phải mục tiêu chỉnh sửa.

## Tài liệu legacy

Các file kỹ thuật tiếng Anh cũ vẫn được giữ trong `docs/` vì chúng chứa lịch sử thiết kế và contract của từng giai đoạn. Khi có khác biệt giữa tài liệu cũ và bộ tài liệu 0.23+, ưu tiên:

1. code hiện tại;
2. contract test hiện tại;
3. bộ tài liệu tiếng Việt 0.23+;
4. tài liệu legacy.
