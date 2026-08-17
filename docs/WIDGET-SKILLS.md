# Cresco Runtime Widget Skills

Cresco Layer 0.6 bổ sung deterministic skill runtime để chỉnh selected Elementor widget/container **không cần đặt chatbot/LLM phía sau editor**.

## Product contract

```text
Selected Elementor element
  -> active Elementor runtime metadata
  -> Cresco Skill Compiler
  -> element-specific skill registry
  -> deterministic command hoặc explicit skill params
  -> native Elementor setting operation
  -> Elementor live settings API + history
```

Runtime registry là source of truth. Một skill chỉ executable khi active Elementor installation expose control/Atomic prop tương ứng.

Cresco không invent setting chỉ vì một Elementor version/addon “thường có” setting đó.

## Coverage model

Skill runtime học từ registered widget/element managers hiện tại, bao gồm khi có:

- Elementor Free widgets/containers;
- Elementor Pro widgets/modules;
- Atomic/V4 elements + props;
- WordPress widgets qua Elementor;
- Cresco widgets;
- third-party addon widgets đăng ký qua Elementor runtime control APIs.

Classic controls compile từ `get_controls()`.

Atomic/V4 editability compile từ normalized:

```text
get_atomic_controls()
+ get_props_schema()
```

Persisted settings phải đọc từ selected document instance, không lấy current values từ registry prototype.

## Một compiled skill biết gì?

Skill record có thể chứa:

- exact control/prop binding;
- type/label/description;
- source (`classic-control`, `atomic-control`, Atomic prop schema);
- desktop/responsive current values;
- default value;
- active responsive devices;
- allowed units;
- select/choose options;
- min/max/range/step;
- Dynamic Tag capability;
- frontend/render metadata;
- selectors/selector dictionaries;
- control conditions/dependencies;
- group-control prefix/type;
- Atomic prop schema;
- semantic role/category;
- risk + execution mode.

## Expert knowledge layer

`ExpertProfiles` bổ sung domain guidance nhưng **không tạo control**.

Các family có thể gồm:

- container/flex/grid/layout;
- headings/text;
- buttons/actions;
- image/media/video;
- forms/fields/actions-after-submit;
- navigation/menu/dropdown;
- posts/query/loop/taxonomy/product;
- slides/carousels;
- tabs/accordion/nested disclosure;
- counter/progress/countdown/rating;
- social widgets;
- WooCommerce/commerce;
- HTML/code/shortcode;
- Atomic/V4.

Expert knowledge giải thích semantics; runtime metadata chứng minh capability.

## Skill mode và risk

### Execution mode

- `direct` — scalar/native control như color, dimensions, slider, select, switcher.
- `expert` — structured/sensitive config như repeater/gallery/code; cần explicit structured value.
- `read-only` — Elementor UI control không mang editable value.

### Risk

- `safe` — presentation/content thông thường.
- `conditional` — phụ thuộc setting khác.
- `structural` — repeater/gallery/structure data.
- `expert` — code/HTML/custom CSS-like.
- `external` — email/redirect/webhook/payment/credential-adjacent.

Secret-like setting key phải bị block khỏi generic skill execution.

## Conditions và prerequisite

Control có thể tồn tại nhưng chưa active do condition.

Compiler giữ condition và chỉ tự enable một số prerequisite **safe + provable** khi cả hai control có trong selected widget runtime registry.

Ví dụ background color có thể cần background type:

```json
[
  {
    "operation": "update-setting",
    "setting": "background_background",
    "value": "classic"
  },
  {
    "operation": "update-setting",
    "setting": "background_color",
    "value": "#07133F"
  }
]
```

Không synthesize prerequisite nếu runtime widget không expose setting đó.

## Responsive skills

Responsive variants đến từ:

- active Elementor breakpoints;
- runtime responsive flag của control.

Ví dụ responsive `padding`:

```text
padding
padding_tablet
padding_mobile
```

Chỉ device active/proven được expose. Non-responsive control không được ép thành responsive suffix.

## Deterministic command bar

Command bar là parser giới hạn, **không phải chatbot**.

Ví dụ:

```text
padding 24px
mobile padding 20px
width 50%
min height 480px
gap 24px
background #07133F
radius 16px
font size 36px
mobile font size 28px
text color #ffffff
hide mobile
show mobile
```

Parser chỉ map command vào semantic role đã tồn tại trong compiled registry. Không match được skill → fail, không đoán.

## Elementor editor integration

`assets/skills.js` từng/hiện cung cấp Cresco Skills palette tùy integration mode. Nguyên tắc runtime:

1. theo dõi selected element;
2. load compiled skill profile của element đó;
3. chỉ hiển thị skill derived từ actual controls/props;
4. nhận explicit params hoặc deterministic commands;
5. gửi live unsaved settings khi resolve condition/preview;
6. reject operation target element khác;
7. apply native settings qua `document/elements/settings`;
8. wrap bằng Elementor history để Undo/Redo dùng được.

Structural insert/remove/move không thuộc widget-only skill palette; chúng đi qua scoped patch workflow.

## REST API

Lấy skill của element:

```text
GET /wp-json/cresco-layer/v1/documents/{postId}/skills/{elementId}
```

Resolve explicit skill:

```json
POST /wp-json/cresco-layer/v1/documents/{postId}/skills/{elementId}/resolve
{
  "skillId": "control.padding",
  "params": {
    "device": "mobile",
    "top": "20",
    "right": "16",
    "bottom": "20",
    "left": "16",
    "unit": "px"
  },
  "liveSettings": {}
}
```

Resolve deterministic command:

```json
{
  "command": "mobile padding 20px",
  "liveSettings": {}
}
```

Resolver trả `cresco-layer-skill-resolution/v1` với native operations + before/after preview. Không cần gọi AI provider.

## Invariants

- Widget skill runtime không cần/không embed chatbot hoặc LLM.
- Không invent Elementor setting key.
- Không thêm responsive suffix cho non-responsive control.
- Validate option/unit/range bằng runtime metadata.
- Preserve Dynamic Tag/global/Atomic context nếu không explicit change.
- Một skill execution chỉ nằm trong selected element.
- Dùng Elementor live settings/history trong editor.
- Elementor chịu trách nhiệm final render, persistence và Update/Publish.