# Quy tắc dự án Cresco Layer

> Phạm vi repository: `quochung9920/cresco-layer` — plugin WordPress Cresco Layer.  
> Xác minh gần nhất với `main` tại `f55e4b0d1b0f635ce08acd5ef7f5ec446ed5e419`, phiên bản plugin/package `0.24.3`, ngày 2026-08-17.  
> Tài liệu này là chính sách kỹ thuật mặc định cho developer và AI Coding Agent. Chỉ dẫn trực tiếp của người dùng có ưu tiên cao hơn. Nếu mô tả trong tài liệu mâu thuẫn với code/runtime hiện tại, **code + contract/behavior test hiện tại là nguồn sự thật** và tài liệu phải được cập nhật cùng thay đổi đó.

## 1. Cresco Layer là gì?

Cresco Layer là **cầu nối file-based, lossless và runtime-aware giữa Elementor và AI bên ngoài**. Plugin không thay Elementor bằng một page builder khác và không tạo document model cạnh tranh với Elementor.

Workflow chuẩn:

```text
Elementor
→ Cresco Export for ChatGPT
→ ZIP/JSON package
→ ChatGPT / AI bên ngoài
→ Cresco Import AI Result
→ Preview + Validation
→ Apply qua Elementor API
→ Read-back verification
→ Rendered/Fidelity verification
```

Nguyên tắc nền tảng: **Elementor là source of truth** cho document, widget, control, breakpoint, Global Styles, rendering, history và persistence.

Yêu cầu hệ thống đã được xác nhận trong repo:

- WordPress 6.6+
- PHP 8.1+
- Node.js 20+ cho development/test
- Elementor bắt buộc
- Elementor Pro không bắt buộc để plugin boot; chỉ cần khi dùng integration Pro như Dynamic Tags, Pro Forms hoặc Theme Conditions

### Phạm vi plugin và phạm vi website

Repository này là **repo plugin**, không phải toàn bộ website WordPress.

Không được coi là đã xác nhận từ repo này:

- active theme thực tế;
- child theme;
- `functions.php` của theme/site;
- template override;
- CSS/JS toàn site ngoài Cresco;
- nội dung/template Elementor thực tế;
- implementation thật của hệ thống class `lisa-*`.

`PackageBuilder` đọc active theme, Elementor Kit, breakpoint và runtime controls lúc chạy. Không hard-code giả định theme chỉ vì môi trường local đang dùng một theme cụ thể.

## 2. Thứ tự nguồn sự thật

Khi các nguồn mâu thuẫn, ưu tiên theo thứ tự:

1. runtime/code hiện tại;
2. contract test, behavior test và architecture invariant hiện tại;
3. `PROJECT_RULES.md`;
4. tài liệu kỹ thuật mới trong `docs/`;
5. tài liệu legacy/lịch sử.

Không giữ một rule lỗi thời chỉ vì tài liệu cũ từng ghi như vậy.

## 3. Cấu trúc repository

```text
cresco-layer.php                 bootstrap/version/autoloader
includes/Plugin.php              wiring service, WordPress/Elementor hooks
includes/AI/                     export/import, capability, mutation/patch, fidelity
includes/Elementor/              runtime discovery, snapshot, custom widgets/integration
includes/SiteSettings/           Elementor Kit / Global Settings engine
includes/DesignSystem/           fluid scale, contrast, preset, design-standard planning
includes/Audit/                  accessibility/performance/design audit
includes/Diagnostics/            export diagnostics/fatal recovery
includes/Skills/                 deterministic runtime widget skills
includes/LocalAI/                Local AI settings/provider/context
includes/Admin/                  Cresco admin screen
includes/REST/                   REST controllers
includes/Support/                assets, requirements, serialization helpers
assets/                          admin/editor/frontend CSS và browser JS
tests/js/                        JS contract/behavior tests
tests/php/                       PHP contract/behavior tests
scripts/                         architecture/lint checks
docs/                            tài liệu kỹ thuật
.github/workflows/ci.yml         CI quality pipeline
```

Entry point quan trọng:

- PHP: `cresco-layer.php` → `CrescoLayer\Plugin::boot()`.
- REST namespace: `cresco-layer/v1`.
- Elementor editor startup: chỉ `assets/editor-bootstrap.js` + `assets/export-target-sync.js` trên critical path.
- External exchange nặng được lazy-load sau hành động rõ ràng của người dùng.
- Frontend widget base CSS: `assets/frontend.css`.
- Admin design tokens/UI: `assets/admin.css`.

## 4. Các invariant kiến trúc không được phá

Không thay đổi các nguyên tắc sau nếu chưa có architecture change rõ ràng + test + docs:

1. **Không ghi trực tiếp Elementor document vào `_elementor_data`.** Dùng Elementor Document API.
2. **Không ghi Site Settings phía sau Elementor Kit API**, ví dụ không tự ghi raw `_elementor_page_settings` để thay Kit save.
3. Không dùng `eval()`, `shell_exec()`, `exec()` hoặc shortcut thực thi code động tương đương.
4. Resolve target/scope trước persistence; patch không có quyền không được tự mở rộng thành document-wide.
5. Runtime capability là authoritative khi đọc được. Không invent Elementor control, responsive suffix, unit, option, Dynamic Tag hoặc global reference.
6. Preserve unknown persisted Elementor/addon/Atomic fields khi chúng không phải mục tiêu chỉnh sửa. Unknown persisted data không phải giấy phép để AI tạo setting mới.
7. Ưu tiên native Elementor controls trước `custom_css` khi runtime có thể biểu đạt yêu cầu.
8. Ưu tiên active Elementor Global Styles/Kit tokens trước local value gần giống.
9. `save()` thành công chưa phải bằng chứng cuối. Dùng read-back verification khi workflow cần độ chính xác.
10. Render/Fidelity chỉ được PASS khi có rendered evidence thật. **Không có evidence không phải PASS.**
11. Người dùng giữ quyết định Update/Publish cuối cùng trong Elementor.
12. Site Settings và element/page mutation là hai contract/pipeline riêng.
13. Cresco phải fail-closed khi không chắc về safety/validation; chỉ fail-soft với enrichment được định nghĩa rõ là optional.

Khi chạm vào kiến trúc nhạy cảm, chạy:

```bash
php scripts/check-architecture.php
```

## 5. Quy ước PHP

- Namespace gốc: `CrescoLayer\`.
- File class dùng PascalCase khớp tên class, ví dụ `PackageBuilder.php`.
- Phần lớn service dùng `final class`, trừ khi external API cần inheritance, ví dụ Elementor widget.
- Dùng PHP 8.1 typed property/return type theo style của module hiện tại.
- Method/property trong code hiện tại chủ yếu dùng `snake_case`; giữ style của file đang sửa.
- Constant dùng `UPPER_SNAKE_CASE`.
- Dùng WordPress/Elementor API thay vì viết lại platform behavior.
- User-facing string dùng text domain `cresco-layer` và helper escape/translation của WordPress.
- Sanitize input tại boundary; escape output tại render boundary.
- Ưu tiên patch nhỏ, có mục tiêu, hơn rewrite nguyên service.

Khi query parameter chỉ dùng cho routing/context và nonce không phù hợp, giữ pattern `phpcs:ignore` có lý do rõ như code hiện tại.

## 6. REST và bảo mật

Mọi REST route phải có permission model rõ ràng.

Pattern hiện có:

- runtime inspection chung: `edit_posts` khi phù hợp;
- document mutation/export: `current_user_can( 'edit_post', $post_id )`;
- full runtime snapshot/global configuration: `manage_options`.

Quy tắc:

- sanitize route/query bằng WordPress sanitizers;
- JSON write endpoint phải reject payload malformed/non-object;
- giữ `X-WP-Nonce` trong browser requests;
- không export secret/credential;
- giữ `SerializableSanitizer` và sensitive-setting guard thành hai lớp bảo vệ riêng;
- không log raw secret trong diagnostics;
- error response nên machine-readable và giữ `errorId`/`stage` của Cresco khi có.

Không hạ capability/safety check chỉ để demo local chạy qua.

## 7. Quy tắc Elementor Runtime

### Runtime discovery

Cresco phải inspect Elementor runtime **đang thực sự hoạt động**. Danh sách widget/control hard-code chỉ được làm hint/candidate, không phải authority cuối.

Trước khi thêm support cho một control:

1. chứng minh runtime control tồn tại;
2. inspect type, responsive support, unit, option, range, condition;
3. chỉ sửa normalizer/adapter khi thật sự cần;
4. thêm regression coverage;
5. bảo đảm phiên bản Elementor/addon khác vẫn fail-safe.

### Bounded export context hiện tại

Implementation hiện tại tách **full registry awareness** khỏi **detailed control hydration**:

- toàn bộ registry index vẫn có trong context;
- server detailed capability budget: 12 widget / 6 element type;
- editable/read-only-context types là bắt buộc và không được silently truncate;
- construction candidates ưu tiên cao hơn generic registry entries;
- Dynamic Tags trong external export dùng metadata compact, không hydrate toàn bộ editor config/control stack;
- runtime modules được summary, không instantiate tất cả module;
- Exact Runtime reuse detail server đã trả và chỉ fetch phần thiếu;
- optional fetch/workers có budget; required target/context capability vẫn fail-closed.

Đây là **resource safety budget**, không phải quality target. Nếu đổi budget phải cập nhật test, diagnostics, docs và đo impact trên document/runtime lớn.

## 8. Đồng bộ target trước Export

Không quay lại pattern cũ “client chọn ID → backend không thấy → bắt người dùng chọn lại” nếu chưa chẩn đoán.

Preflight chuẩn:

```text
người dùng bấm Export
→ Elementor force autosave qua Commands API
→ export-target-status
→ resolve working/autosave + main document
→ bounded retry
→ mới chạy export pipeline
```

Quy tắc:

- `ExportTargetResolver` chỉ đọc;
- không thay canonical Elementor data bằng clipboard/client JSON;
- không ghi `_elementor_data` để “sửa sync”;
- preflight chỉ chạy sau thao tác Export;
- retry phải bounded;
- target stale/chưa sync phải dừng export thay vì gửi server data cũ cho AI.

## 9. Safe Bootstrap và critical path của Elementor

**Khả năng mở và dùng Elementor quan trọng hơn Cresco exchange.**

Trước khi người dùng mở Cresco, startup-safe code không được:

- chạy runtime scanner;
- capture computed styles/geometry;
- build AI context;
- gọi export/import REST;
- autosave;
- cài DOM-wide `MutationObserver` loop;
- dùng `setInterval` polling;
- monkey-patch `window.fetch`;
- block Elementor vô thời hạn.

Startup-safe scripts hiện tại:

```text
editor-bootstrap.js
export-target-sync.js
```

`editor-bootstrap.js` dùng một Elementor-ready watchdog có giới hạn, hiện khoảng 8000ms. Timeout → Cresco chuyển passive, không retry vô hạn.

Emergency rescue mode phải luôn tồn tại:

```text
&cresco_safe=1
```

Mọi editor feature mới phải được phân loại trước:

```text
startup-safe | user-triggered | post-import verification | legacy/admin-only
```

Chỉ work thật sự startup-safe mới được đặt trên critical path.

## 10. Quy ước JavaScript

Browser assets thường dùng IIFE + `'use strict'` và chỉ expose global có chủ đích, ví dụ:

```text
window.CrescoLayerSafeBootstrap
window.CrescoLayerExactRuntimeExport
window.CrescoLayerExportDiagnostics
window.CrescoLayerAIPanel
```

Localized config dùng lower-camel global như `window.crescoLayerEditor` / `window.crescoLayerAdmin`.

Quy tắc:

- giữ style của asset hiện tại; nhiều runtime file cố ý dùng `var`/function syntax;
- không thêm framework/jQuery cho behavior nhỏ;
- ưu tiên native browser API và event delegation;
- tránh DOM query/scroll/resize handler lặp không giới hạn;
- observer, `requestAnimationFrame`, passive listener chỉ dùng khi có lý do và phải bounded;
- không tạo global ngoài integration surface `CrescoLayer*` đã định nghĩa;
- listener/wrapper cùng concern phải có idempotency guard.

### Fetch wrapper

Thứ tự load fetch wrapper là contract kiến trúc.

Wrapper phải:

- capture upstream fetch lúc load;
- chỉ intercept đúng Cresco endpoint của nó;
- forward mọi request khác nguyên trạng;
- clone response trước khi consume body;
- giữ status/statusText/header cần thiết;
- tránh recursion;
- fail-soft chỉ với optional enrichment;
- preserve/augment diagnostics thay vì che lỗi server.

Khi thêm runtime JS mới:

1. thêm vào `npm run lint:js`;
2. khai báo lazy/enqueue dependency order;
3. thêm contract test tối thiểu;
4. thêm test vào `npm run check`.

## 11. CSS và UI

Cresco plugin CSS và downstream website CSS là hai miền khác nhau.

### Namespace plugin Cresco

```text
.cresco-layer-*
.cresco-ai-*
.cresco-layer-component__part
.cresco-layer-component--variant
.is-active / .is-error / .is-warning / ...
```

Admin token được scope dưới `.cresco-layer-admin` với `--cl-*`. Reuse token hiện có trước khi tạo hệ token admin mới.

Frontend widget classes hiện có:

```text
.cresco-layer-heading
.cresco-layer-button-wrap
.cresco-layer-button
.cresco-layer-image
.cresco-layer-icon
.cresco-layer-divider
.cresco-layer-spacer
```

Quy tắc:

- frontend base CSS phải tối giản; configurable visuals nên thuộc Elementor controls;
- selector low-specificity và component-scoped;
- giữ `:focus-visible`;
- giữ `prefers-reduced-motion`;
- ưu tiên CSS custom property, flex/grid, `gap` khi phù hợp;
- tránh ID selector mới cho styling;
- tránh `!important` nếu chưa có specificity conflict được chứng minh;
- không mass-refactor CSS minified/legacy trong task không liên quan;
- không leak admin/editor styles ra frontend.

**Không đổi class plugin Cresco sang `lisa-*`.** Hai namespace thuộc hai hệ khác nhau.

## 12. Custom Elementor Widgets

Widget Cresco đã đăng ký:

```text
cresco-advanced-heading
cresco-advanced-button
cresco-smart-image
cresco-advanced-icon
cresco-divider
cresco-spacer
```

Khi thêm/sửa widget:

- dùng `Controls_Manager`, group controls và responsive controls của Elementor khi có thể;
- selector gắn với wrapper/component class;
- dynamic fields chỉ bật nơi Elementor hỗ trợ;
- escape text/attribute khi render;
- dùng link/icon helper của Elementor khi phù hợp;
- giữ accessible name/focus behavior;
- đăng ký qua `WidgetRegistry`, không rải hook ad-hoc.

## 13. AI contracts và schema

Schema là API contract có version. Một số schema hiện dùng:

```text
cresco-layer-ai-package/v2
cresco-ai-context/v3
cresco-control-registry/v1
cresco-external-ai-package/v1
cresco-ai-bundle/v4
cresco-external-exchange-policy/v1
cresco-ai-mutation/v3
cresco-ai-mutation/v2
cresco-layer-patch/v1
cresco-layer-patch-validation/v2
cresco-site-settings/v1
cresco-fidelity-*/v1
```

Quy tắc:

- ưu tiên optional metadata backward-compatible trước khi bump transport schema;
- nếu semantics/required fields đổi, version contract có chủ đích;
- khi contract đổi phải cập nhật normalizer/compiler/validator/applier/diff/package instructions/tests/docs cùng nhau;
- không thêm patch operation chỉ để shortcut một use case đã biểu đạt an toàn bằng operation hiện có;
- external element/subtree ưu tiên semantic mutation; document-wide ưu tiên document patch;
- giữ existing element IDs; ID mới phải collision-free và do Cresco/Elementor-aware logic cấp;
- không bắt AI echo internal data mà Cresco tự resolve deterministic được.

## 14. Quy tắc Import và Patch

Chuỗi trust:

```text
AI result
→ normalize/compile
→ schema + sensitive-key validation
→ runtime capability validation
→ semantic guard
→ scope enforcement
→ preview/diff
→ apply
→ read-back verification
→ rendered/fidelity verification
```

Preview và Apply phải dùng cùng interpretation/compilation path.

Không bypass validation vì JSON “trông có vẻ đúng”.

Khi thêm patch operation mới, cập nhật đồng bộ:

- `PatchValidator::ALLOWED_OPERATIONS`;
- shape/value validation;
- scope enforcement;
- applier;
- diff/details/history khi cần;
- package/output instructions;
- contract + behavior tests;
- schema/docs.

## 15. Site Settings / Global Design System

Site Settings là engine riêng cho active Elementor Kit.

Pipeline:

```text
semantic spec
→ validate
→ capability discovery
→ active Kit snapshot
→ adapter mapping
→ diff
→ no-op hoặc Kit save
→ read-back normalize/verify
→ rollback nếu verification fail
→ cache invalidation
→ ownership bookkeeping
```

Quy tắc:

- active Kit là source of truth;
- adapter map semantic path sang control thật;
- unsupported control phải skip/report, không invent key;
- `no_op` là success hợp lệ;
- rollback cũng phải verify;
- preserve user/third-party globals ngoài Cresco ownership;
- custom global ID ổn định bằng ownership bookkeeping, không dựa title đơn thuần;
- managed Global Custom CSS chỉ thay block Cresco sở hữu;
- `clamp()`/custom unit chỉ dùng khi capability chứng minh control hỗ trợ và expression qua allowlist validator.

## 16. Design System và Responsive

Trong design-standard engine:

- fluid scaling và structural breakpoint là hai concern khác nhau;
- dùng `clamp()` cho scale liên tục khi control/runtime hỗ trợ;
- dùng breakpoint khi layout thật sự đổi cấu trúc;
- không tạo breakpoint chỉ vì một spacing hơi lệch;
- xét container role để tránh nested double-gutter;
- giữ WCAG-oriented contrast logic hiện có trong `DesignSystem/ContrastRatio.php`.

## 17. Foundation website downstream `lisa-*`

**Trạng thái nguồn:** convention do người dùng cung cấp; chưa thấy implementation thật trong repo plugin. Phải verify với site/theme/Elementor source trước khi sửa site code.

Khi Cresco/AI result được dùng cho site downstream này, giữ các convention sau trừ khi live source chứng minh đã thay:

### Naming

Dùng `lisa-*` cho custom classes của website:

```css
.lisa-component {}
.lisa-component__element {}
.lisa-component--variant {}
```

### Typography

- H1–H6 giữ semantic hierarchy.
- Visual size không quyết định heading level.
- Responsive heading ưu tiên `clamp()`.

### Breadcrumb

- uppercase;
- baseline 14px nếu live source chưa có foundation mới.

### Hero

```text
Desktop: 190px top padding
Tablet/mobile: 110px top padding
```

Nếu live site đã chuyển sang fluid implementation an toàn, ưu tiên implementation hiện tại.

### Buttons

```text
.lisa-button--rose
.lisa-button--gold
.lisa-button--outline
```

Baseline:

```text
border-radius: 6px
hover: translateY(-3px)
```

Giữ focus-visible và reduced-motion.

### Paragraph / Form

- reuse rule bỏ margin paragraph cuối/duy nhất;
- mở rộng Elementor form foundation hiện có thay vì page-specific CSS;
- placeholder không thay accessible label.

### Layout

```text
.lisa-section
.lisa-content
standard max-width: 82rem
reading max-width: 48rem
```

Reuse full-bleed/standard/reading pattern trước khi tạo width mới.

### Spacing / Utilities

- reuse gap scale từ `2xs` tới `section`;
- tránh magic number tùy tiện như `37px`, `23px`, `71px` nếu không có lý do layout rõ;
- utilities đã biết: `.lisa-card-title`, `.lisa-text__accent`;
- search site source trước khi thêm utility/component abstraction.

### Elementor globals

Ưu tiên Global Colors, Global Typography/Fonts và CSS variables hiện có trước hard-code duplicate.

## 18. Accessibility

Accessibility là một phần của correctness.

- mọi action quan trọng phải keyboard-operable;
- focus phải nhìn thấy, ưu tiên `:focus-visible`;
- không xóa outline nếu không có focus treatment tương đương;
- giữ accessible name/label;
- link dùng cho navigation, button dùng cho action;
- heading hierarchy logic;
- tôn trọng `prefers-reduced-motion`;
- không phụ thuộc hover duy nhất;
- site-facing design hướng tới WCAG 2.2 AA;
- giữ contrast checks và form error/success feedback.

## 19. Performance

Không “tối ưu” bằng cách đẩy việc nặng lên Elementor startup.

Quy tắc:

- lazy-load detailed runtime capability;
- bounded export/runtime scans;
- không traverse DOM/catalog không budget;
- tránh hydrate cùng capability nhiều lần;
- cache theo request/session khi an toàn;
- verification timeout phải bounded;
- Fidelity element budget là safety ceiling, không phải target phải lấp đầy;
- không thêm dependency browser nặng cho UI nhỏ;
- animation ưu tiên `transform`/`opacity`.

Với downstream website, còn phải bảo vệ LCP, CLS, INP; ảnh phải size/lazy-load theo usage, và không lazy-load LCP image nếu làm LCP xấu hơn.

## 20. Refactor, xóa code và tránh over-engineering

**Patch trước, rewrite sau.**

Chỉ refactor khi cải thiện ít nhất một trong:

- maintainability;
- reusability;
- accessibility;
- performance;
- consistency;
- reliability.

Trước refactor lớn, ghi:

```text
Problem
Root cause
Proposed change
Affected contracts/components
Regression risk
Verification plan
```

Trước khi xóa code:

1. search PHP/JS/CSS usage;
2. search Elementor/runtime references;
3. search tests/docs/contracts;
4. xét markup/hook generated động;
5. nếu chưa chắc, giữ lại và ghi technical debt.

Không tạo framework/token/utility system mới chỉ để một use case trông “sạch” hơn.

## 21. Comment

Comment nên giải thích **vì sao**, đặc biệt với:

- Elementor limitation/version compatibility;
- startup safety;
- browser/runtime quirks;
- security boundary;
- ownership/preservation rules;
- fail-closed behavior không hiển nhiên.

Không comment để kể lại syntax hiển nhiên.

## 22. Testing và Quality Gate

Lệnh chuẩn:

```bash
npm run check
```

Architecture check:

```bash
php scripts/check-architecture.php
```

Mọi PHP phải qua `php -l`. Runtime JS phải qua `node --check` trong `lint:js`.

Khi behavior đổi:

- thêm/cập nhật static contract test;
- thêm behavior coverage cho happy path và fail-closed path;
- test không phụ thuộc network;
- mock tối thiểu WordPress/Elementor surface cần thiết;
- runtime-dependent behavior cần manual/integration verification với Elementor thật.

Matrix khuyến nghị khi phù hợp:

```text
Elementor Free
Elementor Pro
Hello Theme
non-Hello theme
classic widgets
container/flex/grid
Atomic/V4 khi có
third-party addon sample
published document + autosave
responsive device modes
```

Nếu CI không chạy do billing/runner/infrastructure, báo **CI unavailable**. Không gọi đó là test pass.

## 23. Checklist trước khi sửa

```text
[ ] Đọc PROJECT_RULES.md và docs/tests liên quan.
[ ] Xác định layer: startup, export, import, Site Settings, widget, admin, fidelity...
[ ] Search class/function/control/schema/component hiện có trước khi tạo mới.
[ ] Xác nhận giả định Elementor từ code/runtime, không từ trí nhớ.
[ ] Xác nhận editable scope và persistence owner.
[ ] Kiểm tra contract/operation/token hiện có đã giải quyết vấn đề chưa.
[ ] Kiểm tra startup/editor impact.
[ ] Kiểm tra responsive/accessibility/security impact.
[ ] Xác định contract + behavior tests cần đổi.
[ ] Lập patch nhỏ nhất giữ behavior hiện có.
```

Với downstream website:

```text
[ ] Search foundation/component/utility `lisa-*` trước.
[ ] Ưu tiên Elementor globals/tokens trước hard-code design value.
[ ] Verify desktop/tablet/mobile.
```

## 24. Checklist sau khi sửa

```text
[ ] PHP syntax pass cho file PHP mới/sửa.
[ ] JS syntax pass cho runtime JS mới/sửa.
[ ] `npm run check` pass khi môi trường cho phép.
[ ] `php scripts/check-architecture.php` pass khi chạm architecture-sensitive code.
[ ] Không có console/PHP error mới.
[ ] Elementor editor vẫn mở; Safe Mode vẫn dùng được.
[ ] Không thêm polling/observer/fetch interception không bounded trên startup.
[ ] Export target sync vẫn fail-safe khi state stale.
[ ] Scope không escape trong preview/apply.
[ ] Unknown persisted data vẫn lossless.
[ ] Read-back verification phản ánh persisted truth.
[ ] No-evidence không thể thành Fidelity PASS.
[ ] Docs/schema/version cập nhật nếu contract meaning đổi.
```

Với visual/site-facing change còn phải kiểm desktop/tablet/mobile, keyboard/focus, hover, reduced motion, horizontal overflow, buttons/forms/cards/header/footer nếu liên quan.

## 25. Hạn chế đã biết

- Elementor/addon control availability thay đổi theo runtime; capability discovery là bắt buộc.
- Elementor Pro không được giả định luôn active.
- Atomic/V4 phải forward-compatible và lossless khi metadata chưa biết.
- Full Fidelity/raster có thể cần same-origin Elementor preview iframe.
- Published content có thể được edit qua autosave/working document; không giả định `postId === workingPostId`.
- Client/runtime/autosave có thể tạm diverge; dùng Target Sync thay vì persist raw client payload.
- External AI luôn là untrusted input, kể cả khi result do ChatGPT tạo.

## 26. Technical Debt / Cần xác minh

Không “fix” ngầm các mục này trong task không liên quan:

- Một số tài liệu lịch sử ghi version/contract của giai đoạn cũ; cần giữ nhãn legacy rõ nếu chưa cập nhật semantics.
- Copy trong Admin về profile `Full` vẫn có chỗ mô tả như detailed controls của mọi registered type; behavior 0.24.3 thực tế là full registry awareness + bounded detail + Exact Runtime enrichment.
- `cresco-advanced-icon` và `cresco-smart-image` có thể kết hợp link với trạng thái decorative/accessible name rỗng. Cần explicit accessible-link-label UX + browser test trước khi thay render behavior.
- External AI panel đã có focus-visible baseline nhưng custom tabs vẫn cần accessibility pass riêng cho tab semantics và Arrow-key behavior.
- `tmp-cresco-create-test.txt` tồn tại ở root; ownership/purpose chưa xác nhận. Không xóa chỉ vì tên giống file tạm.
- Active theme, child theme, site `functions.php`, implementation `lisa-*`, Elementor Global IDs/tokens và breakpoint thực tế của website **chưa được xác nhận trong repo plugin này**.

## 27. Quy tắc cho AI Coding Agent

Mọi AI Coding Agent phải:

1. đọc file này trước khi đổi code;
2. inspect source/tests hiện tại trước khi giả định behavior;
3. search trước khi tạo;
4. reuse trước khi duplicate;
5. patch trước khi rewrite;
6. giữ behavior/scope/data trước khi refactor;
7. dùng runtime evidence cho Elementor capability;
8. giữ workload nặng ngoài Elementor startup;
9. validate + verify sau thay đổi;
10. ghi rõ mọi thông tin chưa được xác nhận từ source/runtime.

Nếu chỉ dẫn trực tiếp của người dùng mâu thuẫn file này, chỉ dẫn người dùng có ưu tiên cao hơn; tuy nhiên phải cảnh báo rõ nếu thay đổi có regression/security/architecture risk cụ thể.

Không redesign dự án một cách cơ hội. Chọn thay đổi nhỏ nhất, đáng tin cậy và phù hợp kiến trúc hiện có.

## 28. Ngôn ngữ tài liệu

Từ 0.24.3, tài liệu dự án là **Vietnamese-first** để đội phát triển dễ đọc. Các định danh kỹ thuật phải giữ nguyên tiếng Anh khi chúng là contract/code, gồm:

- schema như `cresco-layer-patch/v1`;
- class/function/file path;
- REST endpoint;
- JSON field/key;
- CSS selector;
- shell/npm command;
- Elementor/WordPress API name.

Không dịch một tên kỹ thuật nếu việc dịch làm người đọc khó đối chiếu với code/runtime.