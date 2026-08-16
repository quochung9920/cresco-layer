# Phát triển & Kiểm thử Cresco Layer

Tài liệu này dành cho người sửa code, thêm module hoặc mở rộng contract.

## 1. Yêu cầu phát triển

- PHP 8.1+
- Node.js 20+
- WordPress 6.6+
- Elementor
- Elementor Pro khi test module Pro

## 2. Cấu trúc repo

```text
assets/                 JavaScript/CSS cho editor, admin, frontend
includes/AI/            AI package, patch, semantic guard, fidelity policy
includes/Elementor/     runtime discovery/snapshot/widget integrations
includes/SiteSettings/  global Kit engine
includes/DesignSystem/  design standards/fluid planning
includes/LocalAI/       local AI runtime/context/provider
includes/Skills/        deterministic widget skills
tests/js/               JS contract/behavior tests
tests/php/              PHP contract/behavior tests
scripts/                architecture checks
docs/                   tài liệu kỹ thuật
```

## 3. Quality command

Lệnh chuẩn:

```bash
npm run check
```

Nó chạy:

- syntax check cho JS;
- editor contracts;
- AI context/bundle contracts;
- visual verification;
- Fidelity Foundation;
- semantic design/import tests;
- PHP behavior tests được khai báo trong package scripts.

CI còn chạy PHP syntax và architecture invariants riêng.

## 4. PHP syntax

CI dùng pattern tương đương:

```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
```

Mọi file PHP mới phải qua `php -l` trước khi merge.

## 5. JavaScript syntax

`npm run lint:js` dùng `node --check` cho asset JS.

Khi thêm file JS runtime mới:

1. thêm file vào `lint:js`;
2. thêm dependency enqueue đúng thứ tự;
3. tạo contract test tối thiểu;
4. thêm test vào `npm run check`.

## 6. Architecture invariants

```bash
php scripts/check-architecture.php
```

Script bảo vệ các nguyên tắc như:

- không ghi Elementor document data bằng đường tắt;
- không dùng `eval`/shell execution;
- required module/file/token tồn tại;
- Site Settings không ghi sai persistence layer;
- editor integrations không bị biến mất vô tình.

Khi thêm invariant mới, ưu tiên token kiểm được ổn định. Không nên viết architecture test quá phụ thuộc whitespace/implementation detail vô nghĩa.

## 7. Contract tests

Repo dùng nhiều contract tests dạng static token check.

Mục đích:

- phát hiện module bị xóa/đổi contract vô tình;
- đảm bảo schema/invariant còn hiện diện;
- chạy nhanh, không cần full WordPress runtime.

Contract test **không thay thế** integration test với Elementor thật.

## 8. Behavior tests

Các test behavior mock vừa đủ WordPress/Elementor để kiểm logic cụ thể.

Ví dụ nhóm:

```text
patch validator
mutation normalizer
semantic guard
Site Settings normalizer
breakpoint policy
history
ID allocation
```

Khi viết behavior test:

- mock ít nhất cần thiết;
- không làm test phụ thuộc network;
- kiểm cả happy path và fail-closed path;
- error case phải khẳng định guard không bị bypass.

## 9. Fidelity tests

### PHP policy contract

```text
tests/php/fidelity-policy-contract-test.php
```

Kiểm:

- schema constants;
- weights tổng bằng 1;
- threshold hợp lý;
- blocking rules quan trọng;
- iteration budget.

### Browser foundation contract

```text
tests/js/fidelity-foundation-contract-test.mjs
```

Kiểm asset có:

- snapshot schema;
- geometry graph;
- `getBoundingClientRect`;
- `getComputedStyle`;
- parent-relative geometry;
- sibling relationship;
- overflow/invalid geometry guard;
- current Elementor device detection;
- scoring + category floor;
- export integration;
- automatic post-apply verification;
- Fidelity Gate UI/event.

### Test bắt buộc khi nâng Fidelity

Mỗi thay đổi scoring nên có test cho:

```text
perfect match
within tolerance
outside tolerance
missing element
parent drift
overflow
hidden target
no evidence
category floor failure
overall threshold failure
```

Foundation hiện mới có contract coverage; phase tiếp theo nên bổ sung DOM behavior tests bằng mock DOM/jsdom hoặc browser integration phù hợp.

## 10. Test với Elementor thật

Static/unit test không đủ cho các vùng phụ thuộc runtime.

Nên có matrix thủ công/automation cho:

```text
Elementor Free
Elementor Pro
Hello Theme
non-Hello theme
classic widgets
container/flex/grid
Atomic/V4 widgets khi khả dụng
third-party addon sample
published document + autosave
responsive device modes
```

## 11. Golden regression corpus

Hướng khuyến nghị cho production-grade fidelity:

Tạo corpus document chuẩn:

```text
01-hero-flex
02-pricing-grid
03-nested-containers
04-form
05-loop-grid
06-woocommerce
07-global-styles
08-dynamic-tags
09-atomic-v4
10-custom-breakpoints
```

Mỗi fixture có:

- source Elementor data;
- expected runtime controls;
- expected scope behavior;
- reference snapshot theo breakpoint;
- expected score/gate;
- expected persisted result.

Sau mỗi release:

```text
export
→ mutate
→ validate
→ apply
→ reload
→ render
→ compare
```

## 12. Quy tắc thêm Elementor control support

Không hard-code control key chỉ vì gặp một site.

Quy trình:

1. chứng minh runtime control tồn tại;
2. inspect metadata;
3. cập nhật normalizer/adapter nếu thật sự cần;
4. thêm regression test;
5. đảm bảo addon/version khác không bị ảnh hưởng.

## 13. Quy tắc thêm patch operation

Nếu cần operation mới:

1. xác định vì sao operation hiện tại không đủ;
2. thêm vào `PatchValidator::ALLOWED_OPERATIONS`;
3. validate shape/value;
4. update scope enforcement;
5. update applier;
6. update diff/details;
7. update history/audit nếu cần;
8. update AI instructions/package capabilities;
9. thêm contract + behavior tests;
10. viết docs/schema reference.

Không thêm operation chỉ để shortcut một use case có thể biểu đạt an toàn bằng operation hiện có.

## 14. Quy tắc thay schema

Trước khi bump schema version, hỏi:

```text
Có phá parser cũ không?
Meaning cũ có thay không?
Required field có thay không?
Có thể enrich backward-compatible không?
```

Nếu chỉ thêm optional metadata, ưu tiên giữ transport schema.

Ví dụ 0.23 giữ:

```text
cresco-layer-patch/v1
cresco-visual-verification/v1
```

và bổ sung Fidelity report/gate riêng.

## 15. Quy tắc fetch wrapper trong editor

Một số asset wrap `window.fetch` để enrich response.

Khi thêm wrapper:

- capture `upstreamFetch` tại thời điểm load;
- chỉ intercept đúng endpoint;
- request khác phải forward nguyên vẹn;
- clone response trước `json()`;
- giữ response status/headers;
- tránh recursion;
- khai báo dependency enqueue để thứ tự ổn định;
- test load-order nếu wrapper phụ thuộc wrapper khác.

## 16. Quy tắc performance

Runtime discovery và snapshot có thể nặng.

Nguyên tắc:

- lazy-load detailed control metadata;
- giới hạn số element snapshot;
- không scan full catalog trong mọi export nếu smart context đủ;
- tránh DOM traversal không giới hạn;
- cache trong phạm vi request/session khi an toàn;
- không làm editor block vô thời hạn vì verifier.

Fidelity policy hiện có:

```text
maxElements = 500
```

Giới hạn này phải được xem như safety budget, không phải mục tiêu cần đạt đủ.

## 17. Quy tắc security

Không đưa vào patch/package những dữ liệu không cần thiết như:

```text
password
API key
private key
access token
refresh token
nonce
SMTP password
webhook secret
```

`SerializableSanitizer` và patch sensitive-key guard là hai lớp khác nhau; không bỏ một lớp chỉ vì đã có lớp kia.

## 18. Quy tắc persistence

### Page/document

Dùng Elementor Document API.

### Site Settings

Dùng active Kit/Kit Document API.

### History bookkeeping

Có thể dùng WordPress meta/options cho dữ liệu **Cresco-owned bookkeeping**, nhưng không dùng nó để thay thế Elementor persistence.

## 19. Review checklist trước khi merge

```text
[ ] Scope không bị mở rộng ngoài ý muốn
[ ] Không hard-code runtime assumption vô căn cứ
[ ] Unknown persisted data vẫn lossless
[ ] New JS có syntax check
[ ] New PHP có php -l
[ ] Contract test đã thêm/cập nhật
[ ] Behavior failure path đã nghĩ tới
[ ] Docs/schema đã cập nhật
[ ] Version có cần bump không
[ ] Asset dependency đúng thứ tự
[ ] No-evidence không bị pass
[ ] Elementor vẫn là source of truth
```

## 20. Release checklist

```text
npm run check
php scripts/check-architecture.php
PHP syntax all files
review compare against main
confirm branch is not behind main
inspect CI conclusion
fast-forward/merge only after scope is understood
verify plugin version + package version
```

Nếu CI không chạy vì hạ tầng/billing/runner, phải ghi rõ đây là **CI unavailable**, không được diễn giải thành test pass.

## 21. Roadmap test quality

Ưu tiên tiếp theo:

1. DOM behavior test cho Fidelity Engine;
2. multi-breakpoint integration fixtures;
3. browser screenshot/raster tolerance tests;
4. correction-loop monotonic-score tests;
5. addon compatibility corpus;
6. long-document performance budgets;
7. WordPress/Elementor version matrix.
