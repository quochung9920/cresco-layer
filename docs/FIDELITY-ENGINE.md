# Fidelity Engine — Cresco Layer 0.23

Fidelity Engine là lớp biến Cresco Layer từ “patch hợp lệ” thành “kết quả render có thể đo và kiểm chứng”.

## 1. Vấn đề cần giải quyết

Elementor JSON mô tả cấu hình, nhưng không mô tả đầy đủ kết quả browser render.

Ví dụ:

```text
width = 50%
```

không cho biết element cuối cùng rộng bao nhiêu pixel nếu chưa biết:

- parent width;
- padding/gap;
- flex/grid context;
- viewport;
- breakpoint;
- CSS cascade;
- font metrics;
- addon/frontend CSS.

Vì vậy Fidelity Engine đo trực tiếp preview DOM.

## 2. Thành phần

```text
FidelityPolicy.php
fidelity-engine.js
fidelity-export.js
visual-verification.js
fidelity-verification.js
```

### `FidelityPolicy.php`

Nguồn policy server-side dùng để localize sang editor.

### `fidelity-engine.js`

Capture DOM, computed styles, geometry graph, scoring và gate.

### `fidelity-export.js`

Enrich AI Package bằng visual context.

### `visual-verification.js`

Verifier semantic intent hiện có.

### `fidelity-verification.js`

Tự động chạy rendered verification sau apply, chấm score và hiển thị gate.

## 3. Fidelity Policy

Schema:

```text
cresco-fidelity-policy/v1
```

Default threshold:

```text
96.0
```

### Weights

```text
geometry    0.30
spacing     0.18
typography  0.18
color       0.12
structure   0.12
quality     0.10
```

Tổng weights phải bằng 1.0 và được kiểm bằng contract test.

### Category floors

```text
geometry    90
spacing     90
typography  90
color       88
structure   98
quality     95
```

Overall score không thể che một category xuống dưới floor.

### Tolerances

Default:

```text
geometryPx    2.0
spacingPx     2.0
typographyPx  1.5
opacity       0.03
overflowPx    2.0
```

Tolerance là chủ đích. Browser/font rasterization có thể tạo khác biệt nhỏ không đáng coi là failure.

### Blocking rules

```text
missing-element
parent-drift
horizontal-overflow
invisible-target
invalid-geometry
no-verification-evidence
```

Blocking issue làm gate fail dù overall score còn cao.

## 4. Computed Visual Snapshot

Schema:

```text
cresco-fidelity-snapshot/v1
```

Snapshot đọc từ Elementor preview iframe.

### Snapshot-level metadata

```text
status
capturedAt
device
viewport
requestedElementIds
elementCount
truncated
elements
geometryGraph
policy
```

### Device

Engine cố gắng đọc Elementor device mode qua editor channel.

Giá trị thường có dạng:

```text
desktop
tablet
mobile
```

và có thể phụ thuộc active breakpoints/version Elementor.

### Viewport

```text
width
height
devicePixelRatio
scrollX
scrollY
```

## 5. Element snapshot

Mỗi rendered element có các phần sau.

### Identity

```text
id
parentId
children
sibling
```

### Geometry

```text
x
y
width
height
right
bottom
relativeX
relativeY
```

`relativeX/relativeY` quan trọng hơn absolute x/y trong nhiều case vì nó mô tả vị trí đối với parent.

### Scroll dimensions

```text
clientWidth
clientHeight
scrollWidth
scrollHeight
```

Dùng để phát hiện overflow.

### Layout computed styles

Ví dụ:

```text
display
position
flexDirection
flexWrap
justifyContent
alignItems
alignContent
gap
rowGap
columnGap
gridTemplateColumns
gridTemplateRows
overflow
overflowX
overflowY
zIndex
transform
```

### Spacing

```text
marginTop
marginRight
marginBottom
marginLeft
paddingTop
paddingRight
paddingBottom
paddingLeft
```

### Typography

```text
fontFamily
fontSize
fontWeight
fontStyle
lineHeight
letterSpacing
textAlign
textTransform
textDecorationLine
whiteSpace
wordBreak
```

### Visual

```text
color
backgroundColor
backgroundImage
border widths/styles/colors
border radius
boxShadow
opacity
visibility
```

### Quality flags

```text
horizontalOverflow
verticalOverflow
hidden
invalidGeometry
```

## 6. Geometry Graph

Schema:

```text
cresco-geometry-graph/v1
```

Mỗi node chứa:

```text
parentId
children
sibling
geometry
```

Edge gồm:

```text
parent
next-sibling
```

Mục tiêu của graph là cho AI và verifier biết **quan hệ layout**, không chỉ property cục bộ.

## 7. Visual Context trong export

Schema wrapper:

```text
cresco-visual-context/v1
```

Ví dụ cấu trúc:

```json
{
  "schema": "cresco-visual-context/v1",
  "source": "elementor-preview-computed-runtime",
  "currentBreakpointOnly": true,
  "snapshot": {
    "schema": "cresco-fidelity-snapshot/v1"
  }
}
```

`currentBreakpointOnly: true` là contract quan trọng của 0.23.

AI không được dùng desktop snapshot rồi tự kết luận mobile pixel geometry.

## 8. Snapshot comparison

`CrescoLayerFidelityEngine.compare(reference, actual)` tạo:

```text
cresco-fidelity-report/v1
```

Các category được chấm độc lập.

### Geometry

So sánh:

```text
width
height
relativeX
relativeY
```

### Spacing

So sánh margin/padding/gap.

### Typography

Numeric tolerance cho:

```text
fontSize
lineHeight
letterSpacing
```

Exact normalized compare cho:

```text
fontFamily
fontWeight
fontStyle
textAlign
textTransform
```

### Color

So sánh computed color strings sau normalization cơ bản của browser result.

### Structure

Parent relationship là tín hiệu cấu trúc chính ở foundation phase.

### Quality

Overflow/hidden/invalid geometry có thể thành blocking issue.

## 9. Intent verification score

Không phải workflow nào cũng có một full reference snapshot trước/sau. Existing `visual-verification.js` tạo danh sách check từ semantic design intent.

`scoreChecks()` chuyển các check đó sang fidelity categories.

Mapping khái quát:

```text
layout.gap / padding / margin → spacing
layout.*                      → geometry
style.font* / lineHeight      → typography
style.background/textColor    → color
a11y.* / ux.*                 → quality
khác                          → structure
```

Status hiện tại được quy đổi:

```text
pass     → 100
warning  → 55
fail     → 0
```

Đây là foundation scoring, không phải machine vision metric.

## 10. No-evidence fail-closed

Một lỗi phổ biến trong hệ thống scoring là:

```text
không có check
→ average(empty) = 100
```

Cresco không cho phép điều đó.

Nếu `scoreChecks()` không nhận rendered evidence:

```text
overall = 0
coverage.status = unavailable
issue.rule = no-verification-evidence
issue.blocking = true
gate.status = blocked
```

Đây là invariant quan trọng.

## 11. Verification Gate

Schema:

```text
cresco-fidelity-gate/v1
```

Gate pass khi:

```text
overall >= threshold
AND blockingIssues = 0
AND mọi category >= categoryFloor tương ứng
```

Report gate có:

```text
pass
status
threshold
overall
blockingIssues
categoryFloorFailures
note
```

## 12. Post-apply automatic verification

`fidelity-verification.js` wrap apply workflow.

Luồng:

```text
AI apply request
→ visual-verification wrapper xử lý apply
→ Elementor save + response
→ Fidelity Verification schedule retry
→ đọc rendered DOM
→ visual checks
→ scoreChecks
→ verification gate
→ UI report
```

Retry mặc định:

```text
250 ms
650 ms
1200 ms
```

Mục đích: cho preview iframe thời gian cập nhật sau save.

Đây không phải background job; retry xảy ra trong phiên editor hiện tại.

## 13. UI result

Kết quả có dạng:

```text
Fidelity Score: 97.8/100 · Gate PASS
geometry 98.2 · spacing 96.5 · typography 99.1 · color 100 · structure 100 · quality 96.0
Threshold 96.0. Blocking issues: 0.
```

Hoặc:

```text
Fidelity Score: 91.3/100 · Gate BLOCKED
```

UI cũng dispatch event:

```text
cresco-layer:fidelity-verified
```

để module tương lai có thể dùng report/gate mà không cần scrape DOM.

## 14. Điều Fidelity 0.23 chưa làm

### Chưa tự động chuyển qua mọi breakpoint

Snapshot hiện tại đại diện cho device mode hiện đang mở trong Elementor.

### Chưa có raster/image diff đầy đủ

Computed style/geometry không thể phát hiện mọi khác biệt hình ảnh, ví dụ chi tiết trong ảnh, antialiasing hoặc một số effect phức tạp.

### Chưa có property ownership graph

Engine chưa xác định đầy đủ property cuối cùng đến từ:

```text
local setting
global token
parent
responsive override
custom CSS
addon stylesheet
theme stylesheet
```

### Chưa có autonomous correction loop

0.23 đo và gate. Nó chưa tự động gọi AI nhiều vòng để sửa cho đến khi score đạt threshold.

## 15. Lộ trình Fidelity Engine

### Phase 2 — Multi-breakpoint Matrix

```text
desktop
laptop
tablet_extra
tablet
mobile_extra
mobile
widescreen
```

chỉ với breakpoint đang active.

### Phase 3 — Raster Reference Diff

Thêm screenshot/reference-based regions và visual tolerance.

### Phase 4 — Property Ownership

Map mismatch → source control/parent/global token.

### Phase 5 — Correction Loop

```text
candidate patch
→ render
→ score
→ nếu tăng: giữ candidate
→ nếu giảm: reject/rollback
→ tối đa N vòng
```

Policy hiện đã dự trù:

```text
maxCorrectionRounds = 4
requireScoreImprovement = true
rollbackOnRegression = true
```

0.23 mới định nghĩa policy; execution loop sẽ được triển khai ở phase sau.

## 16. Nguyên tắc không được phá khi nâng Fidelity

1. Không coi raster pixel equality là truth tuyệt đối giữa mọi môi trường.
2. Không dùng score để bỏ qua blocking structural issue.
3. Không pass khi không có evidence.
4. Không sửa DOM/CSS trực tiếp như persistence mechanism.
5. Mismatch phải dẫn về Elementor control/structure khi có thể.
6. Responsive evidence phải ghi rõ breakpoint/device.
7. Capture phải có budget để không làm editor chậm vô hạn.
8. Fidelity là verifier, Elementor vẫn là renderer/source of truth.
