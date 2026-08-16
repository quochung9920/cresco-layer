# Elementor Site Settings trong Cresco Layer

Tài liệu này mô tả engine import/sync Global Settings của Cresco Layer.

## 1. Mục tiêu

Cresco cấu hình Elementor Site Settings nhưng **không tạo design-system UI cạnh tranh với Elementor**.

Sau import, designer tiếp tục vào Elementor → Site Settings để chỉnh như bình thường.

Cresco chịu trách nhiệm cho:

- semantic spec;
- capability discovery;
- mapping;
- diff;
- save;
- read-back verification;
- ownership bookkeeping;
- rollback khi cần.

## 2. Schema

```text
cresco-site-settings/v1
```

Schema này tách khỏi:

```text
cresco-layer-patch/v1
```

Lý do:

- Site Settings là Kit/global design system;
- patch là page/container/widget mutation;
- lifecycle và validation khác nhau;
- không nên để một thay đổi Elementor Kit control ảnh hưởng element patch contract.

## 3. Pipeline

```text
spec
→ validate
→ active Kit resolve
→ capability discovery
→ read current values
→ snapshot
→ adapter planning
→ diff
→ nếu no-op: dừng
→ save qua Kit API
→ read-back verification
→ rollback khi verification fail
→ cache invalidation
→ ownership persistence
```

## 4. Active Kit là source of truth

Cresco không nên xem một bản copy cấu hình riêng là truth.

Gateway phải resolve active Elementor Kit rồi làm việc qua API của Kit/Document khi có thể.

Nguyên tắc:

```text
Cresco semantic intent
→ adapter
→ Elementor control/value
→ Elementor Kit save
```

Không đi tắt bằng cách ghi raw meta tùy tiện.

## 5. Adapter

Adapter hiện tại cho classic Kit nằm trong:

```text
includes/SiteSettings/Adapter/ElementorClassicKitAdapter.php
```

Adapter chuyển semantic path thành control thật.

Ví dụ semantic intent:

```text
colors.primary
colors.accent
typography.h1
layout.container
```

không bắt upstream spec phụ thuộc trực tiếp vào tên internal control của Elementor.

Khi Elementor/Atomic model thay đổi lớn, hướng kiến trúc đúng là thêm adapter tương ứng thay vì nhét condition vào mọi layer.

## 6. Capability discovery

Trước khi ghi, engine hỏi runtime xem control có tồn tại không.

Kết quả có thể là:

```text
supported
unsupported_control
preserved_by_profile
tab_not_registered
optional surface unavailable
```

Một control không tồn tại phải được báo `skipped`, không được ghi key vô nghĩa rồi gọi là thành công.

## 7. Hello Theme và optional surfaces

Hello header/footer, Elementor Pro custom CSS, transitions hoặc các surface phụ không phải runtime nào cũng có.

Engine dùng capability discovery/bridge để quyết định.

Điều này cho phép:

- Elementor Free;
- Elementor Pro;
- Hello Theme;
- theme khác;
- addon khác

đi qua cùng pipeline mà không giả định mọi control đều có mặt.

## 8. Diff-first

Engine không save nếu semantic diff không có thay đổi.

Trạng thái `no_op` là kết quả thành công:

```text
spec đã đồng bộ với Kit hiện tại
```

Điều này quan trọng để:

- tránh write thừa;
- tránh invalidation thừa;
- giảm side effect;
- dễ retry/idempotent.

## 9. Verification sau save

`save()` trả true chưa phải bằng chứng dữ liệu cuối cùng đúng.

Elementor có thể normalize:

- số thành string hoặc ngược lại;
- slider thêm `sizes`;
- dimensions thêm `isLinked`;
- hex color đổi case;
- repeater thêm field;
- CSS đổi whitespace/formatting.

Verifier do đó so sánh **semantic normalized values**, không byte-equality mù quáng.

## 10. Value normalization

`ValueNormalizer` xử lý theo type, ví dụ:

```text
slider
dimensions
gaps
repeater
css expression
```

Noise của một type không được áp dụng cho type khác.

Ví dụ:

- `sizes` có thể là noise trong slider;
- `sizes` có thể là dữ liệu thật trong repeater row.

## 11. Verification report

Khi mismatch, report cần đủ thông tin để debug mà không phải chạy lại chỉ để đoán:

```text
semanticPath
Elementor control
control type
expected raw
actual raw
expected normalized
actual normalized
machine-readable reason
```

## 12. Rollback

Nếu save xảy ra nhưng verification thất bại:

```text
pre-write snapshot
→ restore
→ verify rollback
→ report rollback result
```

Rollback cũng phải được kiểm chứng.

## 13. Ownership Registry

Custom global của Elementor có opaque `_id`. Title không phải stable identity.

Cresco dùng registry bookkeeping để map:

```text
semantic key → Elementor global ID
```

Mục tiêu:

- rerun không tạo duplicate swatch/font;
- update đúng global do Cresco quản lý;
- nếu registry mất, có thể adopt global matching phù hợp thay vì nhân đôi.

Style thật vẫn nằm trong Elementor Kit.

## 14. Merge mode

Trong `merge`, engine ưu tiên bảo toàn dữ liệu người dùng/third party.

Các nhóm thường cần preserve:

- user custom globals ngoài ownership;
- custom breakpoints;
- Site Identity;
- CSS ngoài managed block;
- addon Kit settings;
- profile-preserved controls.

## 15. Responsive Foundation

Cresco dùng hai nguyên tắc:

### Fluid scaling

Dùng `clamp()` khi cần giá trị thay đổi liên tục theo viewport.

### Structural breakpoints

Dùng breakpoint khi layout thật sự đổi cấu trúc.

Không nên biến mọi kích thước thành một tập override desktop/tablet/mobile nếu một fluid expression an toàn đã đủ.

## 16. Custom unit và clamp

Khi Elementor control hỗ trợ `custom`, engine có thể ghi CSS expression trực tiếp.

Vì đây là raw CSS expression, phải đi qua allowlist validator.

Nguyên tắc validator:

- chỉ function được cho phép;
- custom property thuộc namespace Cresco;
- không có ký tự có thể phá declaration/rule;
- không chứa `javascript:`;
- parenthesis phải cân bằng.

Nếu control không hỗ trợ custom unit, fallback về native value phù hợp thay vì cố ghi expression không được Elementor hiểu.

## 17. Managed CSS block

Khi cần publish fluid tokens vào Global Custom CSS, Cresco chỉ sở hữu block có marker:

```text
/* CRESCO:FLUID-TOKENS:START */
...
/* CRESCO:FLUID-TOKENS:END */
```

CSS ngoài block phải được giữ nguyên.

Đây là nguyên tắc ownership theo vùng, tránh overwrite CSS người dùng.

## 18. Global container padding

Global container padding có thể tác động cả nested containers tùy Elementor version/layout model.

Cresco phải tránh một baseline làm gutter bị nhân đôi nhiều tầng.

Responsive foundation và `layoutContext.containerRoles` được dùng để phân biệt:

- outer section shell;
- inner content container;
- component/card container.

Vertical rhythm và horizontal page gutter không nhất thiết thuộc cùng một container.

## 19. Console vận hành

Cresco admin UI cho Site Settings tập trung vào:

```text
Preview
Import/Apply
Verify
Health/status
```

Không phải một form nhập từng color/font cạnh tranh với Elementor.

Trạng thái chính:

```text
updated
no_op
verification_failed
hard failure
```

## 20. REST endpoints

Các endpoint Site Settings nằm dưới namespace:

```text
/wp-json/cresco-layer/v1/site-settings/
```

Các action chính trong code gồm profile/preview/apply/health/verify tùy route hiện tại.

Quyền yêu cầu là quyền quản trị phù hợp vì Site Settings ảnh hưởng toàn site.

## 21. Khi gặp `unsupported_control`

Không nên hard-code thêm key ngay lập tức.

Debug theo thứ tự:

1. runtime hiện tại có đăng ký control không;
2. control nằm ở Kit tab/section nào;
3. theme/Pro module cần thiết có active không;
4. adapter mapping có đúng phiên bản không;
5. optional bridge có cần dùng không;
6. nếu surface không tồn tại thật, giữ `skipped` thay vì giả lập.

## 22. Invariants

1. Active Kit là source of truth.
2. Diff trước write.
3. Runtime capability trước mapping cuối.
4. Save qua Elementor Kit/Document API.
5. Read-back verify sau write.
6. Rollback nếu verification fail.
7. Ownership không đồng nghĩa overwrite dữ liệu ngoài ownership.
8. Optional control không tồn tại phải skip minh bạch.
9. Preview/Verify read-only không được tạo side effect.
10. Site Settings schema không trộn với page patch schema.
