# Phát triển & Kiểm thử Cresco Layer

Tài liệu dành cho người sửa code hoặc mở rộng contract. Baseline hiện hành: **0.24.4 — External AI Only**.

## Yêu cầu

- PHP 8.1+
- Node.js 20+
- WordPress 6.6+
- Elementor
- Elementor Pro khi test integration Pro

## Cấu trúc chính

```text
assets/                 editor/admin/frontend assets
includes/AI/            external package, patch/mutation, safety, fidelity
includes/Elementor/     runtime discovery/snapshot
includes/SiteSettings/  Elementor Kit engine
includes/DesignSystem/  design standards
includes/Skills/        deterministic widget skills, không gọi AI model
tests/js/               JS contract/behavior tests
tests/php/              PHP contract/behavior tests
scripts/                lint + architecture checks
docs/                   tài liệu
```

Không có `includes/LocalAI/` trong kiến trúc hiện hành.

## Quality gate

```bash
npm run check
php scripts/check-architecture.php
```

Mọi PHP mới/sửa phải qua `php -l`; JS runtime mới/sửa phải qua `node --check` và test liên quan.

0.24.4 có regression test:

```text
tests/php/no-local-ai-remnants-test.php
```

Test này chặn việc runtime/assets/routes/tests Local AI cũ quay lại ngoài ý muốn.

## Quy tắc runtime

- Không invent Elementor control/suffix/unit/option.
- Capability cuối cùng phải được runtime chứng minh.
- Unknown persisted data phải được preserve nếu không phải mục tiêu sửa.
- Page/document dùng Elementor Document API.
- Site Settings dùng active Kit/Kit API.
- Safe Bootstrap không được mang heavy scanning/polling vào startup.
- Deterministic `Cresco Skills` không phải Local AI và phải tiếp tục hoạt động độc lập.
- AI generation/editing thuộc External AI Exchange qua file ZIP/JSON.

## Quy tắc schema

Chỉ bump schema khi meaning/required fields/parser compatibility thực sự thay đổi. Optional metadata nên ưu tiên backward-compatible enrichment.

## Quy tắc performance

- lazy-load detailed capability;
- bounded context/snapshot;
- không scan full detail khi không cần;
- không traversal/polling vô hạn;
- verifier phải có timeout/budget;
- fail-closed cho safety, fail-soft chỉ cho optional enrichment đã định nghĩa.

## Quy tắc security

Không đưa password, API key, private key, access token, refresh token, nonce hoặc secret vào package/log. `SerializableSanitizer` và sensitive-setting guards là các lớp riêng, không thay thế nhau.

## Review trước khi merge

```text
[ ] Scope không mở rộng ngoài ý muốn
[ ] External AI Exchange vẫn hoạt động
[ ] Cresco Skills deterministic vẫn hoạt động
[ ] Không còn Local AI runtime/model wiring
[ ] Runtime assumptions có bằng chứng
[ ] Unknown persisted data lossless
[ ] PHP/JS syntax pass khi môi trường cho phép
[ ] Contract/behavior tests cập nhật
[ ] Docs/version đồng bộ
[ ] Elementor vẫn là source of truth
```

## Release

```text
npm run check
php scripts/check-architecture.php
review compare against main
confirm branch is not behind main
inspect CI conclusion
fast-forward main với force=false
verify main ref + version
```

Nếu CI không chạy do billing/runner thì ghi rõ **CI unavailable**, không diễn giải thành test pass.
