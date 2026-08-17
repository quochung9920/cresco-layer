# Widget Intelligence

Cresco Layer 0.18 bổ sung `cresco-widget-intelligence/v1` vào external AI context.

Nó trả lời một câu hỏi khác với Exact Runtime:

- **Exact Runtime:** widget đã cài này thật sự expose control nào?
- **Widget Intelligence:** widget đã cài nào phù hợp về mặt semantic cho phần giao diện đang cần tạo?

Cả hai đều cần thiết. Semantic recommendation không được tạo capability mà active Elementor runtime không chứng minh.

## Runtime-first selection

Intelligence layer xây index từ:

```text
runtime.widgets
runtime.elements
```

Sau đó candidate theo semantic role chỉ được giữ nếu type tồn tại trong index đó.

Ví dụ:

```text
headline → ưu tiên heading
CTA      → ưu tiên button
```

nhưng chỉ khi các type này có trong current runtime.

Third-party Elementor addon cũng có thể tham gia nếu registered type xuất hiện trong runtime metadata. Pro-only widget không được recommend khi installation hiện tại không đăng ký nó.

## Các semantic family phổ biến

Deterministic families có thể bao gồm:

- layout;
- heading;
- text;
- button;
- icon;
- list;
- image/media;
- form;
- navigation;
- query/loop;
- carousel;
- disclosure;
- video;
- commerce;
- code/HTML fallback.

Ví dụ role record:

```json
{
  "headline": {
    "preferredWidget": "heading",
    "alternatives": [],
    "avoidWidgets": ["text-editor", "html"],
    "reason": "Render semantic headings or short prominent text with native heading level and typography controls.",
    "runtimeProven": true
  }
}
```

## Quan hệ với Semantic Scene

`semanticScene` phân tích selected subtree hiện tại và gán role hint deterministic + confidence.

`constructionPlan` kết hợp task wording + Widget Intelligence để đề xuất structure có runtime support.

Thứ tự reasoning nên là:

```text
user task / reference
  -> existing semantic scene
  -> desired semantic roles
  -> Widget Intelligence recommendation
  -> Exact Runtime controls
  -> Active Kit / responsive rules
  -> semantic mutation
```

## Server enforcement

Recommendation không chỉ là prompt text.

Khi mutation tạo/rebuild node, compiler phải validate `widgetIntent`/element type với active `CapabilityScanner` catalog.

Nếu AI invent widget type không có trong runtime:

```text
reject before internal patch
```

Điều này chặn lỗi “model hiểu đúng vai trò UI nhưng chọn widget không tồn tại trên site”.

## Protected families

Một số widget kết hợp presentation với behavioral/external configuration.

Ví dụ setting cần preserve trong visual task:

- form submission destination;
- webhook;
- query/template source;
- navigation source;
- transactional/payment setting;
- code-like content.

Visual request vẫn có thể style widget qua native controls nhưng không được âm thầm đổi nó submit/query/execute/purchase cái gì.

## Custom CSS

Widget Intelligence không làm Custom CSS thành preferred path.

Nếu:

```text
semantic role → native widget tồn tại
AND runtime có native control phù hợp
```

thì AI phải dùng native path.

`custom_css` chỉ là fallback cho effect runtime controls không biểu đạt được và vẫn phải qua `SemanticPatchGuard`.

## Nguyên tắc cốt lõi

```text
semantic suitability
∩ runtime availability
= candidate hợp lệ
```

Một widget “có vẻ đúng về ý nghĩa” nhưng không registered trong Elementor runtime hiện tại không phải candidate hợp lệ.