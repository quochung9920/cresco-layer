# Ranh giới Safe AI Exchange của Cresco Layer

Phiên bản giới thiệu: **0.15.1**.

Tài liệu này giải thích ranh giới giữa:

- dữ liệu **đã tồn tại trong Elementor**;
- dữ liệu **AI thực sự được phép thay đổi**.

## Vì sao cần ranh giới này?

AI export chứa nhiều state hiện tại để model hiểu page:

- containers/widgets;
- settings;
- responsive values;
- Site Settings;
- live runtime controls.

Đó là **context để đọc**, không phải template phải copy ngược trở lại Elementor.

Trước 0.15.1, một yêu cầu nhỏ như “thêm marquee dưới hero” có thể bị AI thực hiện theo cách nguy hiểm:

```text
copy toàn bộ exported subtree
→ thêm node mới
→ replace-element root
```

Nếu exported context có field bị redact/truncate, placeholder đó có thể overwrite live value thật.

Vì vậy Cresco tách source context khỏi mutation output.

## Mental model

```text
LIVE ELEMENTOR
    |
    | export
    v
READ-ONLY SOURCE CONTEXT
    |
    | AI hiểu UI hiện tại
    v
DELTA MUTATION OUTPUT
    |
    | Cresco validate scope + runtime
    v
PREVIEW
    |
    v
APPLY TO LIVE ELEMENTOR
```

Rule quan trọng:

> **AI có thể đọc existing Elementor data nhưng không được echo lại chỉ để “giữ nguyên”. Result chỉ nên chứa thay đổi thật sự được yêu cầu.**

## Read-only source context

Các path điển hình:

```text
document.content
elementContext
elementStates
```

AI dùng chúng để hiểu content, ID, hierarchy, effective settings. Với task nhỏ, không copy entire existing subtree vào mutation result.

## Delta-first mutation

| User intent | Operation ưu tiên | Lý do |
|---|---|---|
| Thêm section/widget/container | `insert-element` | Existing content không bị chạm |
| Đổi một native control | `update-setting` | Chỉ setting đó thay đổi |
| Bỏ override | `remove-setting` | Elementor fallback về inherited/default |
| Reorder/move | `move-element` | Giữ dữ liệu element |
| Xóa element được yêu cầu rõ | `remove-element` | Destructive action có phạm vi nhỏ |
| Rebuild hoàn toàn target | `replace-element` | Chỉ dùng khi explicit full rebuild |

Các operation destructive:

```text
replace-element
replace-settings
remove-element
replace-document
```

không phải default cho visual tweak bình thường.

## Ví dụ: thêm marquee dưới hero

Đúng — chỉ insert phần mới:

```json
{
  "schema": "cresco-layer-patch/v1",
  "base": { "postId": 3 },
  "scope": {
    "mode": "subtree",
    "rootElementId": "3ed4781",
    "elementIds": ["3ed4781"]
  },
  "operations": [
    {
      "operation": "insert-element",
      "parentId": "3ed4781",
      "position": 3,
      "element": {
        "id": "new0001",
        "elType": "container",
        "settings": {},
        "elements": []
      }
    }
  ]
}
```

Không nên với incremental addition:

```text
copy subtree 3ed4781
+ append marquee
+ replace-element 3ed4781
```

Pattern thứ hai vô tình nhận ownership của toàn bộ heading/icon/form/dynamic value/unknown fields hiện có.

## Full-tree result được bảo vệ

`cresco-layer-ai-result/v1` có thể mô tả complete Elementor tree.

- Nếu construction target thật sự rỗng, Cresco có thể compile full tree thành replacement.
- Nếu target đã có settings/children/persisted data, implicit replacement phải bị chặn.

Complete rebuild cần explicit intent:

```json
{
  "schema": "cresco-layer-ai-result/v1",
  "intent": "replace-target",
  "target": {
    "postId": 3,
    "id": "3ed4781"
  },
  "element": {
    "id": "3ed4781",
    "elType": "container",
    "settings": {},
    "elements": []
  }
}
```

## Serialization integrity

Export sanitizer phải bảo vệ secret nhưng cũng không được truncate normal Elementor tree ở depth thông thường.

Safety có hai phía:

1. **Export:** legitimate Elementor data phải lossless trong budget.
2. **Import:** nếu hard-limit placeholder xuất hiện, Cresco phải chặn trước Preview/Apply.

Các marker bị block:

```text
[TRUNCATED]
[REDACTED]
__cresco_truncated__
```

Placeholder từ context không được trở thành real Elementor setting.

## Native-control policy

Safe exchange không làm yếu runtime validation.

AI vẫn phải:

- dùng live native controls khi có;
- ưu tiên Container `gap`/row-gap/column-gap cho sibling rhythm;
- không invent widget setting/responsive suffix;
- tuân unit/options/ranges/value shape;
- chỉ dùng `custom_css` khi runtime không có native path phù hợp.

## Exchange policy

Policy lịch sử mô tả separation:

```json
{
  "exchangePolicy": {
    "schema": "cresco-layer-ai-exchange-policy/v1",
    "separation": "source-context-is-read-only; mutation-output-is-delta-only-by-default",
    "sourceContext": {
      "mode": "read-only-reference",
      "paths": [
        "document.content",
        "elementContext",
        "elementStates"
      ],
      "echoBack": false,
      "copyExistingSubtreeIntoMutation": false
    },
    "mutationOutput": {
      "schema": "cresco-layer-patch/v1",
      "strategy": "delta-first",
      "preferredOperations": [
        "insert-element",
        "update-setting",
        "remove-setting",
        "move-element"
      ]
    }
  }
}
```

External workflow mới còn có `cresco-external-exchange-policy/v1`, nhưng nguyên tắc source-context read-only + delta-first vẫn không đổi.

## Kết quả mong muốn

```text
Select target
→ Export
→ AI đọc current source context
→ AI trả only intended delta
→ Placeholder guard
→ Runtime + scope validation
→ Preview
→ Apply
```

Một component mới không cần nhận ownership của toàn section. Điều này giảm accidental overwrite, duplicate reconstruction và corruption do context không đầy đủ.