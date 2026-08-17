# Design Intelligence

Cresco Layer 0.20 giới thiệu lớp hướng dẫn thiết kế deterministic cho external-AI context. Cresco Layer 0.21 bổ sung thêm `cresco-design-reasoning/v1` để chuyển các nguyên tắc chung thành objective, hierarchy, composition, reference translation và quality gate phù hợp từng product/page.

Hai lớp này **không thay Elementor Site Settings** và không tạo token system song song. Active Elementor Kit và live runtime vẫn là source of truth.

## Nguồn tham khảo UI/UX Pro Max

Mô hình chất lượng được tham khảo từ dự án MIT công khai:

```text
nextlevelbuilder/ui-ux-pro-max-skill
revision: a38d04c3d5c298c851dbe5e6ee1965ee3de42cb5
```

Cresco không phụ thuộc runtime vào repo này và không import dataset lớn, Python search tool hay generated design systems của nó.

Các ý tưởng cấp cao được kế thừa gồm:

- hiểu product/page intent trước khi chọn style;
- accessibility trước decoration;
- touch/interaction safety;
- performance + layout stability;
- visual consistency;
- responsive/fluid layout;
- typography/color legibility;
- motion có mục đích và hỗ trợ reduced motion;
- form feedback + behavior preservation;
- predictable navigation;
- explicit variance/motion/density controls;
- quality checklist trước delivery.

Provenance nguồn tham khảo được ghi trong exported design metadata.

## Design dials

Panel có ba dial tùy chọn. `Auto` cho phép Cresco suy ra mức conservative từ task.

### Variance

Mức độ khác biệt của composition:

```text
minimal / structured
→ balanced
→ bold / asymmetric
```

### Motion

```text
subtle
→ standard
→ expressive
```

### Density

```text
spacious
→ standard
→ dense
```

Các dial chỉ là **design constraint** cho model. Chúng không tự ghi CSS hoặc Elementor setting.

## Context contracts

### `cresco-design-intelligence/v1`

Có thể chứa:

- product archetype;
- style keywords;
- design dial values/tiers;
- semantic spacing intent scale;
- ordered quality priorities;
- Active Kit availability;
- anti-patterns;
- core design principles.

### `cresco-design-reasoning/v1`

Bổ sung:

- page archetype;
- audience signals;
- product/page objective;
- ordered visual hierarchy;
- composition/proof strategy;
- semantic emphasis/surface/depth vocabulary;
- critical/high/advisory quality gates;
- reference-image adaptation rules;
- conflict-resolution order.

External model phải reuse design language của site trước khi invent local values. Global Colors, Global Fonts, Dynamic Tags và behavioral bindings đều preserve-by-default.

## Semantic design intent

`cresco-semantic-design-intent/v1` định nghĩa vocabulary mà `cresco-ai-mutation/v3` có thể dùng.

Các nhóm intent phổ biến:

- layout direction/alignment/gap/width/padding;
- typography;
- colors;
- responsive variants;
- accessibility intent.

Vocabulary cố ý nhỏ hơn toàn bộ Elementor control surface. Nếu semantic intent không đủ, model có thể dùng explicit runtime-proven `settings`; chúng vẫn phải qua semantic/runtime validation.

`Design Reasoning` quyết định **nên ưu tiên điều gì**. `SemanticDesignCompiler` quyết định **runtime hiện tại có biểu đạt được không**.

## Thứ tự chất lượng

Thứ tự mặc định:

```text
accessibility + behavior safety
  -> user task + hierarchy
  -> responsive layout
  -> Active Kit consistency
  -> widget/runtime fit
  -> typography + color
  -> depth + motion
  -> decorative polish
```

Không được đánh đổi contrast dễ đọc, keyboard focus, touch usability, reduced-motion hoặc form/query/navigation behavior để giống reference image hơn về mặt trang trí.

## Mục tiêu cuối

Design Intelligence + Design Reasoning giúp external AI:

- hiểu mục tiêu giao diện rõ hơn;
- tạo composition có chủ đích hơn;
- nhất quán với site hiện tại hơn;
- ít invent local design value hơn;
- nhưng vẫn để Elementor làm source of truth và Cresco quyết định runtime legality.