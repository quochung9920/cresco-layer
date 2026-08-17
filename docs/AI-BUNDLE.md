# Cresco AI Bundle v3

> **Tài liệu lịch sử:** mô tả AI Bundle ở giai đoạn Cresco Layer 0.21. External workflow 0.24 hiện dùng `cresco-ai-bundle/v4`; xem `EXTERNAL-AI-WORKFLOW.md` và `SCHEMA-REFERENCE.md` cho contract mới nhất.

Cresco Layer 0.21 đóng gói context đã chuẩn bị cho AI bên ngoài thành một ZIP local để model nhận đồng thời task, kiến thức runtime Elementor, design intelligence, professional design reasoning, output contract và visual reference trong một hand-off thống nhất.

Schema của giai đoạn này:

```text
cresco-ai-bundle/v3
```

## Các file mặc định

- `01-TASK.md` — mục tiêu, target, placement, brief sản phẩm/trang, rule widget/ID/output, decision order và quality priorities.
- `02-context.json` — `cresco-ai-context/v3` đã chuẩn bị đầy đủ.
- `03-widget-guide.json` — Widget Intelligence, Construction Plan, Semantic Bindings, Structure Grammar, vocabulary design intent và ví dụ control.
- `04-output-contract.json` — contract response, ưu tiên `cresco-ai-mutation/v3`.
- `05-design-intelligence.json` — design dials, hướng dẫn UI/UX, Active Kit, responsive context và mutation boundary.
- `06-design-reasoning.json` — reasoning theo product/page, hierarchy, composition, reference-image translation và machine-readable quality gates.
- `manifest.json` — metadata bundle và danh sách file thực tế.
- `current-desktop.png` — optional, best-effort raster của target trong Elementor preview.
- `reference-<filename>` — optional reference image người dùng chọn.

## Semantic design workflow

AI bên ngoài nên ưu tiên **design intent** trước implementation detail của Elementor:

```text
task + reference
  -> design reasoning
  -> widget/structure intelligence
  -> content/layout/style/responsive/accessibility intent
  -> cresco-ai-mutation/v3
  -> Cresco SemanticDesignCompiler
  -> active-runtime Elementor controls
  -> semantic mutation v2
  -> internal patch/v1
```

Mutation v2 và legacy result/patch vẫn được chấp nhận để tương thích, nhưng v3 là format ưu tiên cho design work mới ở giai đoạn này.

## Design Intelligence và Design Reasoning

Bundle mang hai lớp:

- `cresco-design-intelligence/v1` — design dials theo task, spacing intent và thứ tự ưu tiên chất lượng.
- `cresco-design-reasoning/v1` — objective theo product/page, hierarchy, composition, semantic design vocabulary, reference translation và quality gates.

Cả hai đều kết hợp user task với design system hiện tại của Elementor. **Active Kit vẫn là source of truth**, Cresco không tạo token system song song.

Workflow tham khảo các ý tưởng cấp cao từ dự án MIT `nextlevelbuilder/ui-ux-pro-max-skill`; Cresco ghi provenance trong exported context nhưng **không có runtime dependency** và không vendor dataset/tool Python lớn của dự án đó.

## Chuyển reference image thành design intent

Reference image là **design evidence**, không phải raw Elementor instruction. Model được yêu cầu phân tích:

- hierarchy;
- composition;
- proportions;
- spacing rhythm;
- typography character;
- color relationships;
- surface depth;
- component patterns.

Sau đó model phải chuyển các đặc tính này qua Active Kit, Widget Intelligence và Exact Runtime hiện tại.

Accessibility, behavior preservation và responsive correctness luôn ưu tiên hơn decorative similarity.

## Raster capture

Raster chỉ là best-effort, không được fabricate.

Luồng:

```text
resolve target trong same-origin preview iframe
→ clone subtree + computed styles
→ serialize bằng SVG foreignObject
→ paint lên canvas
→ export PNG
```

Capture có thể không khả dụng vì:

- cross-origin assets;
- browser không hỗ trợ/không serialize được;
- target geometry quá lớn;
- preview node không tồn tại.

ZIP vẫn hợp lệ nếu raster không có; manifest ghi:

```text
raster.status = "unavailable"
```

`visualSnapshot` và `layoutGraph` vẫn là structured context chính.

## ZIP implementation

Bundle writer dùng ZIP local tối giản, không nén, có CRC32 và không tải thư viện archive bên thứ ba. Mục tiêu là editor workflow self-contained và không có remote runtime dependency.

## Hướng dẫn cho AI bên ngoài

Model nên đọc file theo thứ tự số, đặc biệt `06-design-reasoning.json` trước khi chốt composition. Model phải:

- chỉ dùng widget/control đã được runtime chứng minh;
- ưu tiên `cresco-ai-mutation/v3`;
- preserve protected/global/dynamic bindings;
- chỉ trả semantic delta được yêu cầu;
- không tự cấp final Elementor ID cho node mới — Cresco chịu trách nhiệm phần này.