# Rendered Visual Verification

Cresco Layer 0.20 bổ sung lớp verification sau Apply cho semantic design mutation.

Persistence verification trả lời:

> **Elementor có lưu đúng reviewed settings không?**

Rendered verification trả lời câu hỏi khác:

> **Elementor preview hiện tại có render semantic design intent theo cách phù hợp không?**

Hai lớp verification bổ sung cho nhau, không thay thế nhau.

## Workflow

Sau semantic mutation Apply thành công, Cresco giữ:

- original `cresco-ai-mutation/v3` hoặc v2 payload;
- mapping temporary ref → final Elementor ID.

Verifier đọc same-origin Elementor preview iframe và so semantic intent với computed rendering.

Schema:

```text
cresco-visual-verification/v1
```

## Các check có thể đo deterministic

Version đầu tập trung vào property có thể kiểm mà không cần giả định machine vision:

### Layout

- flex direction;
- justify/alignment;
- wrapping;
- gap;
- width/min-height/max-width;
- padding/margin approximation;
- overflow.

### Visual/typography

- border radius;
- opacity;
- text alignment;
- font size;
- line height;
- letter spacing;
- font weight;
- background/text color khi browser normalize được.

### Accessibility/UX

- explicit ARIA label intent;
- decorative intent khi detect được;
- touch-target warning cho CTA/button-like node;
- horizontal overflow warning.

Kết quả có thể là:

```text
pass
partial
mismatch
unavailable
```

Mỗi check nên giữ expected + actual value để debug.

## Scope và giới hạn

Đây **không phải pixel-perfect screenshot diff**.

Các yếu tố như:

- CSS percentage;
- flex sizing;
- font metrics;
- media loading;
- browser layout;
- responsive context

có thể làm semantic value resolve thành computed value khác unit/representation.

Vì vậy một số check cần tolerance/warning thay vì hard fail.

Reference-image similarity vẫn chủ yếu là task của external model qua raster/reference asset trong AI Bundle. Local verifier là deterministic safety/quality check cho geometry + computed styles.

Nếu preview iframe hoặc rendered node không resolve được:

```text
status = unavailable
```

Cresco không invent PASS.

## Safety

Verifier là **read-only**.

Nó không auto-repair page.

Nếu phát hiện mismatch:

```text
verification mismatch
→ report
→ user/AI tạo mutation sửa mới
→ Preview
→ validation
→ Apply
```

Repair vẫn phải đi qua runtime validation, `SemanticPatchGuard`, Preview và persistence verification.

## Quan hệ với Fidelity Engine

Rendered Visual Verification là foundation thấp hơn của hệ Fidelity sau này.

Fidelity Foundation mở rộng ý tưởng này bằng:

- structured snapshot;
- geometry graph;
- weighted categories;
- category floors;
- blocking rules;
- `no-verification-evidence` fail-closed;
- post-apply Fidelity Gate.

Nguyên tắc không đổi:

> **Không có rendered evidence thì không được coi kết quả là đã verify thành công.**