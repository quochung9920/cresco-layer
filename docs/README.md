# Tài liệu Cresco Layer

Bộ tài liệu tiếng Việt này áp dụng cho **Cresco Layer 0.24+**.

Cresco Layer là cầu nối file-based giữa Elementor và AI bên ngoài. Plugin không yêu cầu người dùng viết prompt thiết kế trong Elementor; nó export runtime/context ra file, để ChatGPT xử lý giao diện ở bên ngoài, sau đó import kết quả về và kiểm chứng bằng Elementor runtime thật.

## Bắt đầu từ đâu?

Nếu bạn là người dùng hoặc đang tích hợp ChatGPT, đọc theo thứ tự:

1. [EXTERNAL-AI-WORKFLOW.md](EXTERNAL-AI-WORKFLOW.md) — luồng Elementor → ChatGPT → Elementor, UX và file contract chính.
2. [AI-EXPORT-IMPORT.md](AI-EXPORT-IMPORT.md) — chi tiết AI Context, scope, mutation/patch và import pipeline.
3. [FIDELITY-ENGINE.md](FIDELITY-ENGINE.md) — computed snapshot, geometry graph, score và verification gate.

Nếu bạn phát triển plugin:

4. [KIEN-TRUC-HE-THONG.md](KIEN-TRUC-HE-THONG.md) — kiến trúc tổng thể và module.
5. [SCHEMA-REFERENCE.md](SCHEMA-REFERENCE.md) — tra cứu schema/contract.
6. [PHAT-TRIEN-KIEM-THU.md](PHAT-TRIEN-KIEM-THU.md) — quy tắc phát triển và quality gate.
7. [SITE-SETTINGS.md](SITE-SETTINGS.md) — Elementor Global Settings/Kit engine.

## Workflow chính từ 0.24

```text
Elementor
→ Export for ChatGPT
→ cresco-external-ai-package/v1 hoặc cresco-ai-bundle/v4
→ cresco-external-exchange-policy/v1 chọn output contract theo scope
→ ChatGPT nhận yêu cầu thiết kế trong cuộc trò chuyện
→ ChatGPT trả JSON
→ Import AI Result
→ Preview / runtime validation
→ Apply
→ read-back verification
→ rendered fidelity verification
```

Không có bước bắt buộc “Create / Edit bằng AI” trong Elementor.

## Ba lớp dữ liệu cần phân biệt

1. **Persisted Elementor data** — dữ liệu Elementor đang lưu trong document/Kit.
2. **Runtime capability** — widget/element/control thực sự được đăng ký ở môi trường đang chạy.
3. **Rendered evidence** — geometry/computed styles thực tế sau khi browser render preview.

Một output AI chỉ “đúng JSON” chưa đủ. Chuỗi tin cậy là:

```text
JSON hợp lệ
→ đúng target/scope
→ đúng runtime control
→ đúng unit/option/range
→ Elementor lưu thành công
→ đọc lại đúng dữ liệu
→ render có evidence
→ Fidelity Gate đạt chuẩn
```

## Các schema chính

```text
cresco-external-ai-package/v1
cresco-ai-bundle/v4
cresco-external-exchange-policy/v1
cresco-ai-context/v3
cresco-layer-ai-package/v2
cresco-control-registry/v1
cresco-ai-mutation/v3
cresco-ai-mutation/v2
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

- Elementor luôn là source of truth.
- External export dùng Full Context để không phụ thuộc prompt/task hint viết trong Elementor.
- `cresco-external-exchange-policy/v1` quyết định output contract theo scope: widget/subtree ưu tiên semantic mutation v3, document ưu tiên patch v1.
- Không invent Elementor control, responsive suffix, unit, option, Dynamic Tag hay global reference.
- Không sửa element ngoài editable scope.
- Không ghi trực tiếp vào storage khi Elementor có Document/Kit API phù hợp.
- Không xem `save() === true` là đủ; cần read-back verification.
- Không coi “không có rendered evidence” là pass.
- Không tuyên bố pixel-perfect tuyệt đối giữa mọi browser/OS/font stack.
- Ưu tiên native Elementor controls trước custom CSS.
- Ưu tiên Global Styles trước local near-duplicate.
- Preserve unknown persisted Elementor fields nếu chúng không phải mục tiêu chỉnh sửa.

## Tài liệu legacy

Một số file kỹ thuật cũ vẫn được giữ để lưu lịch sử thiết kế và contract từng giai đoạn. Khi có khác biệt, ưu tiên theo thứ tự:

1. code hiện tại;
2. contract test hiện tại;
3. bộ tài liệu tiếng Việt 0.24+;
4. tài liệu legacy.
