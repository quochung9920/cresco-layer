# Full Elementor Runtime Snapshot v1

`cresco-elementor-snapshot/v1` là snapshot cấu hình dành cho administrator, được giới thiệu trong Cresco Layer 0.5.0.

Snapshot rộng hơn widget catalog. Mục tiêu là thu thập tối đa cấu hình Elementor Core, Elementor Pro và addon đã đăng ký mà runtime có thể serialize an toàn, đồng thời giữ cả representation dễ đọc và raw representation gần nguồn nhất có thể.

## Contract

Mỗi payload lazy-loaded có dạng:

```json
{
  "schema": "cresco-elementor-snapshot/v1",
  "section": "...",
  "normalized": {},
  "raw": {},
  "coverage": {
    "status": "complete|partial|failed",
    "errors": 0,
    "redactions": 0,
    "omissions": 0
  },
  "redactions": [],
  "omissions": [],
  "scanErrors": []
}
```

- `normalized` — dữ liệu ổn định, có tên rõ, phù hợp inspection/AI reasoning.
- `raw` — giữ runtime/WordPress structure có thể serialize để field Elementor mới không bị mất chỉ vì Cresco chưa hiểu.

## Các section chính

Index endpoint có thể expose:

- `environment` — WordPress/PHP/Elementor/Pro version, active theme, plugin stack.
- `global-settings` — Elementor-related options, multisite options, current-user editor metadata.
- `features` — feature/experiment definitions + saved state.
- `breakpoints` — all/active breakpoint definitions.
- `active-kit` — active Site Kit, Site Settings, Global Colors/Fonts, settings/data/meta.
- `dynamic-tags` — registered Dynamic Tags và metadata được runtime expose.
- `runtime` — Core/Pro modules, dependency-aware Pro capabilities, lightweight widget/element registry.
- `records` — Elementor-owned post types và index documents/templates/Theme Builder/popups/custom fonts/icons/code.

Widget/element detail được lấy riêng qua `CapabilityScanner`, bao gồm Classic controls và Atomic/V4 metadata khi runtime expose.

Elementor-owned record được tải từng record. Snapshot có thể giữ:

- WordPress post fields;
- post meta;
- taxonomies;
- persisted Elementor document data;
- page settings;
- current-user working/autosave data.

## Runtime discovery

Cresco sửa hai giả định quan trọng với Elementor 4.x.

### Dynamic Tags

Dynamic Tag được đọc từ registry record qua registered `instance`, không coi registry record tự nó là tag object.

Nếu Elementor Pro active nhưng registry vẫn rỗng sau registration request:

```text
coverage = partial
```

thay vì trả trusted empty catalog.

### Module discovery

Module manager được đọc bằng:

```text
get_modules_names()
→ get_modules($name)
```

Không gọi `get_modules()` thiếu argument. Điều này tránh `ArgumentCountError` trên một số Elementor Pro 4.x runtime.

Runtime section còn phân biệt licensed feature với dependency active. Ví dụ WooCommerce/ACF/Pods/Toolset feature có thể tồn tại về license nhưng dependency không active; khi đó báo `dependency-inactive` thay vì coi là live capability.

## REST API

Mọi snapshot route yêu cầu `manage_options`.

```text
GET /wp-json/cresco-layer/v1/elementor-snapshot
GET /wp-json/cresco-layer/v1/elementor-snapshot/section/{section}
GET /wp-json/cresco-layer/v1/elementor-snapshot/widget/{name}
GET /wp-json/cresco-layer/v1/elementor-snapshot/element/{name}
GET /wp-json/cresco-layer/v1/elementor-snapshot/record/{postId}
```

Index có `downloadPlan` chứa section names, registered widgets/elements và record IDs.

Browser downloader chạy plan tuần tự:

```text
index
→ section từng phần
→ widget từng phần
→ element từng phần
→ record từng phần
→ ghép JSON cuối ở browser
```

Cách này tránh một PHP request khổng lồ gây memory exhaustion và cô lập failure theo từng mục.

## Xử lý secret

`SerializableSanitizer` phải redact key giống:

- password;
- API key;
- access/refresh token;
- private key;
- consumer/client/app secret;
- license key;
- SMTP password;
- webhook secret;
- authorization value;
- nonce.

Secret-bearing URL query parameter và bearer token cũng phải được xử lý trước output.

Unsupported runtime object/resource/callback, cycle, excessive nesting và bounded truncation không được stringify tùy tiện; chúng được omit và ghi path trong `omissions`.

## Coverage semantics

- `complete` — scanner hoàn thành không có runtime exception/hidden partial.
- `partial` — scanner recover được nhưng có phần không đọc được.
- `failed` — phần đó không scan thành công.

Redaction hoặc omission có chủ đích vì safety **không tự động** biến coverage thành partial.

Browser-built snapshot phải aggregate cả HTTP failure lẫn `coverage.status` nội bộ. Một request HTTP 200 nhưng payload `partial` không được biến thành top-level `complete`.

## Quan hệ với AI package

Full snapshot là artifact riêng với `cresco-layer-ai-package/v2` vì có thể rất lớn.

Normal AI editing nên dùng scoped/bounded context. Snapshot phù hợp cho:

- diagnostics;
- runtime inspection;
- compatibility investigation;
- full-site knowledge export khi administrator chủ động yêu cầu.

Không nhúng full snapshot vào mọi AI edit request.