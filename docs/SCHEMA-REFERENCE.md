# Schema Reference — Cresco Layer 0.24

Tài liệu tra cứu nhanh các schema/contract chính. Workflow ưu tiên từ 0.24 là **Elementor → external AI package → ChatGPT → AI result → Cresco import**.

## 1. `cresco-external-ai-package/v1`

Single JSON package dành cho ChatGPT/AI bên ngoài.

Top-level:

```text
schema
packageId
createdAt
producer
workflow
target
instructionsForAI
resultContract
contextQuality
context
```

`workflow`:

```text
elementor-export-external-ai-import
```

`target` giữ identity/scope do Cresco AI Context cung cấp.

`instructionsForAI` là instruction machine-readable, nhắc model:

- package/runtime là source of truth;
- yêu cầu thiết kế đến từ cuộc trò chuyện bên ngoài Elementor;
- không invent control/unit/option/responsive suffix/Dynamic Tag/global reference;
- preserve IDs và unknown persisted fields;
- chỉ trả intended delta;
- trả JSON sạch.

`resultContract`:

```text
preferredSchema
acceptedSchemas
filename
targetRule
responseRule
```

`context` chứa nguyên Cresco AI Context v3.

## 2. `cresco-ai-bundle/v4`

Manifest schema của ZIP bundle đầy đủ.

Top-level chính:

```text
schema
packageSchema
packageId
pluginVersion
createdAt
target
contextQuality
preferredOutputSchema
resultFilename
raster
reference
files
```

Các file bundle có thể gồm:

```text
README-FOR-CHATGPT.md
cresco-package.json
elementor-context.json
output-contract.json
widget-guide.json
visual-context.json
current-preview.png
reference-<filename>
manifest.json
```

`cresco-package.json` dùng `cresco-external-ai-package/v1`.

## 3. `cresco-ai-context/v3`

Editor-enriched context dành cho AI workflow.

Nó bao quanh/biên dịch dữ liệu từ package/runtime và có thể chứa:

```text
target
currentInterface
placementContext
layoutGraph
runtimeCapabilities
capabilityLock
siteDesignContext
taskRuntimeDiscovery
widgetIntelligence
constructionPlan
semanticBindings
structureGrammar
controlExamples
designIntelligence
designReasoning
mutationBoundary
outputContract
contextQuality
fidelityPolicy
visualContext
```

Trong external workflow 0.24, REST export dùng **Full Context profile** vì prompt thiết kế không còn nằm trong Elementor.

## 4. `cresco-layer-ai-package/v2`

Package lõi server-side của Cresco.

Các vùng chính:

```text
manifest
editableScope
document
elementContext
elementStates
siteContext
designSystem
layoutContext
registryIndex
controlRegistry
patchContract
widgetCatalog
elementCatalog
relevantCapabilities
dynamicTags
templates
assets
capabilities
audit
instructions
```

## 5. `cresco-control-registry/v1`

Normalized runtime control contract.

Top-level:

```text
schema
controlMetadataVersion
widgets
elements
responsiveSuffixes
```

Mỗi control có thể có:

```text
name
type
source
label
responsive
dynamic
units
options
range
min
max
step
condition
conditions
selectors
bind
propType
```

Registry không phải danh sách hard-code; nó được tạo từ Elementor runtime đang chạy.

## 6. `cresco-ai-mutation/v3`

Schema kết quả **ưu tiên** cho external AI.

V3 mô tả semantic design intent trước khi Cresco lower về v2/internal patch.

Ví dụ khái niệm:

```json
{
  "schema": "cresco-ai-mutation/v3",
  "intent": "add",
  "target": {
    "postId": 3,
    "id": "abc1234",
    "scope": "subtree"
  },
  "placement": {
    "mode": "inside-end"
  },
  "nodes": [
    {
      "ref": "$new:title",
      "widgetIntent": "heading",
      "content": {
        "text": "Example",
        "semanticLevel": "h2"
      },
      "styleIntent": {
        "fontSize": "48px"
      }
    }
  ]
}
```

AI không được tự tạo final Elementor ID cho node mới. Dùng `$new:<name>`/ref khi contract yêu cầu.

## 7. `cresco-ai-mutation/v2`

Semantic mutation thấp hơn v3, gần runtime binding hơn. Vẫn được import để tương thích.

Pipeline thường là:

```text
v3
→ SemanticDesignCompiler
→ v2
→ AIMutationCompiler
→ cresco-layer-patch/v1 nội bộ
```

## 8. `cresco-layer-ai-result/v1`

Format tương thích dùng khi AI trả một Elementor element tree hoàn chỉnh cho target.

Top-level chính:

```text
schema
target
element
label
```

Không phải format ưu tiên cho workflow 0.24 vì mutation/delta thường an toàn và nhỏ hơn.

## 9. `cresco-layer-patch/v1`

Transport/internal patch schema ổn định.

Top-level:

```text
schema
base
scope
label
operations
```

Các operation hỗ trợ:

```text
update-setting
remove-setting
replace-settings
replace-element
insert-element
remove-element
move-element
update-page-setting
remove-page-setting
replace-document
```

Ví dụ `update-setting`:

```json
{
  "operation": "update-setting",
  "elementId": "abc123",
  "setting": "title_color",
  "value": "#ffffff"
}
```

Ví dụ `insert-element`:

```json
{
  "operation": "insert-element",
  "parentId": "abc123",
  "index": 1,
  "element": {
    "id": "allocated-by-cresco",
    "elType": "widget",
    "widgetType": "heading",
    "settings": {}
  }
}
```

AI bên ngoài nên ưu tiên v3 thay vì tự viết patch setting-level nếu package `outputContract` yêu cầu như vậy.

## 10. `cresco-layer-patch-validation/v2`

Runtime validation report/contract.

Các gate chính:

```text
scope
target-exists
registered-control
responsive-capability
unit
option
range
global-reference
unsafe-value
```

Unknown persisted field chỉ được preserve khi unchanged; không phải giấy phép invent setting mới.

## 11. `cresco-fidelity-policy/v1`

Policy đo rendered fidelity.

Các trường chính:

```text
schema
snapshotSchema
reportSchema
gateSchema
threshold
categoryFloor
weights
tolerances
blockingRules
capture
iteration
```

Default overall threshold hiện tại:

```text
96
```

## 12. `cresco-fidelity-snapshot/v1`

Snapshot DOM/rendered state từ Elementor preview.

Mỗi element có thể mang:

```text
id
geometry
parentId
previousId
nextId
styles
quality
```

Geometry có thể gồm x/y/width/height, parent-relative position và client/scroll dimensions.

Styles được nhóm theo layout, spacing, typography và visual properties.

## 13. `cresco-geometry-graph/v1`

Quan hệ hình học/DOM:

```text
parent
children
previous sibling
next sibling
relative geometry
```

Dùng để phát hiện drift/layout collateral change mà chỉ nhìn setting không thấy được.

## 14. `cresco-fidelity-report/v1`

Kết quả chấm điểm rendered checks.

Các category hiện dùng:

```text
geometry
spacing
typography
color
structure
quality
```

Report không đồng nghĩa pixel diff tuyệt đối.

## 15. `cresco-fidelity-gate/v1`

Kết luận PASS/BLOCKED dựa trên:

- overall threshold;
- category floor;
- blocking rules;
- verification evidence.

`no-verification-evidence` là blocker; không có evidence không được mặc định 100 điểm.

## 16. `cresco-site-settings/v1`

Contract riêng cho Elementor Site Settings/active Kit.

Dùng cho:

- Global Colors;
- Global Fonts;
- typography/theme style;
- layout defaults;
- responsive foundation;
- Hello/theme integration khi runtime hỗ trợ.

Không trộn Site Settings spec vào element-level patch.

## 17. Quy tắc chọn schema

Đối với workflow ChatGPT bên ngoài:

```text
Export JSON:   cresco-external-ai-package/v1
Export ZIP:    cresco-ai-bundle/v4
AI context:    cresco-ai-context/v3
AI output:     cresco-ai-mutation/v3 (ưu tiên)
Internal apply:cresco-layer-patch/v1
Validation:    cresco-layer-patch-validation/v2
Render verify: cresco-fidelity-report/v1 + cresco-fidelity-gate/v1
```

Nếu `outputContract` của package cụ thể yêu cầu khác, AI phải ưu tiên contract nằm trong chính package đó.
