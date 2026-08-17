# Workflow AI trong Elementor Editor

> **Tài liệu lịch sử:** file này mô tả UX giai đoạn Cresco Layer 0.5.1 với nút **Edit with AI**. Từ 0.24, UX chính đã chuyển sang **Cresco Export / Import** và external file exchange. Xem `EXTERNAL-AI-WORKFLOW.md` cho workflow hiện tại.

Cresco Layer 0.5.1 giữ exchange contract:

```text
cresco-layer-ai-package/v2
→ cresco-layer-patch/v1
```

nhưng giúp người dùng thao tác ngay trong Elementor mà không phải xử lý JSON thủ công ở normal case.

## Edit with AI — UX lịch sử

Toolbar từng có action **Edit with AI** với các scope:

- **This element only** → `widget`: chỉ settings của selected element; children được preserve.
- **This section + children** → `subtree`: root + descendants.
- **Selected elements** → `selection`: chỉ các element được chọn rõ; descendants không tự editable.

Nguyên tắc vẫn còn giá trị: **chọn scope nhỏ nhất đủ cho task**. Không export toàn page chỉ để đổi một button.

## AI selection

Non-contiguous selection từng được giữ trong editor bridge. Context menu có thể add/remove element khỏi AI selection, sau đó backend nhận `selection` scope cùng comma-separated IDs.

Backend `selection` contract vẫn có thể tồn tại cho workflow nâng cao dù external panel 0.24 tập trung vào widget/subtree/document.

## Tên file input/output

Editor export lịch sử dùng tên:

```text
cresco-ai-input-post<postId>-<target>-<scope>.json
```

File này là **AI input package**, không phải file import trở lại Cresco.

## Import AI result

Import UI nhận `.json` qua drag-and-drop/file picker; paste là fallback.

Các lỗi phổ biến được nhận diện trước REST request:

- `cresco-layer-patch/v1` → có thể là AI result hợp lệ.
- `cresco-layer-ai-package/v2` → đây là input package, không phải result.
- Elementor clipboard/export với `type: elementor` → không phải Cresco result.
- invalid/unknown JSON → reject trước server request.

Với scoped result, UI có thể so `target.id`/scope với current selected element và chặn mismatch trước Preview.

## Validation và Preview

Sau local checks, server vẫn chịu trách nhiệm:

```text
schema/normalization
→ runtime validation
→ semantic guard
→ scope validation
→ preview diff
```

Validation error nên hiển thị persistent trong UI và hỗ trợ copy diagnostics thay vì chỉ dùng transient toast.

## Apply

Apply phải:

- dùng Elementor document API;
- không tự publish;
- giữ history/undo semantics khi integration cho phép;
- sync/reload editor để người dùng thấy persisted working state;
- report khi cần reopen editor.

## Quan hệ với workflow 0.24

UX mới đã thay phần “Edit with AI” bằng:

```text
Elementor
→ Export for ChatGPT
→ external AI chat
→ Import AI Result
→ Preview
→ Apply
```

Nhưng các bài học của file này vẫn giữ nguyên:

- scope phải rõ;
- input package và output result phải phân biệt;
- target mismatch phải chặn sớm;
- server validation là authority;
- Apply không phải Publish.