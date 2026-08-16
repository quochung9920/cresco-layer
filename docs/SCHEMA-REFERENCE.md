# Schema Reference — Cresco Layer 0.24

Workflow ưu tiên từ 0.24:

```text
Elementor
→ cresco-external-ai-package/v1 hoặc cresco-ai-bundle/v4
→ ChatGPT/AI bên ngoài
→ result JSON theo scope
→ Cresco import
→ internal patch
→ runtime validation
→ Elementor
→ Fidelity verification
```

## 1. `cresco-external-ai-package/v1`

Single JSON package dành cho AI bên ngoài.

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

`instructionsForAI` yêu cầu model:

- coi package/runtime là source of truth;
- nhận design prompt từ cuộc trò chuyện bên ngoài Elementor;
- không invent control/unit/option/responsive suffix/Dynamic Tag/global reference;
- preserve scope và existing IDs;
- dùng temporary refs cho node mới khi contract cho phép;
- chỉ trả intended delta;
- trả JSON sạch.

`resultContract` là **contract lớp ngoài cùng** và phải được ưu tiên nếu template legacy bên trong `context` có khác biệt.

## 2. `cresco-ai-bundle/v4`

Manifest schema của ZIP bundle.

Các file có thể có:

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

## 3. `cresco-external-exchange-policy/v1`

Policy chuẩn hóa output contract cho workflow external.

### Widget/subtree

```text
mode: semantic-target-mutation
preferredSchema: cresco-ai-mutation/v3
```

### Document

```text
mode: document-patch
preferredSchema: cresco-layer-patch/v1
scope.mode: document
```

Lý do: semantic mutation v3 cần một root Elementor cụ thể; document không phải một fake root container.

Document scope có template cho:

- update existing element;
- top-level insert với `parentId: ""`;
- temporary ref như `$new:top-level-section`.

`replace-document` chỉ dành cho full rebuild có chủ đích.

## 4. `cresco-ai-context/v3`

Editor-enriched context dành cho AI exchange.

Có thể chứa:

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
externalExchangePolicy
```

External workflow 0.24 dùng **Full Context** vì design prompt không còn nằm trong Elementor.

## 5. `cresco-layer-ai-package/v2`

Package lõi server-side.

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

## 6. `cresco-control-registry/v1`

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

Registry lấy từ Elementor runtime thật, không phải danh sách widget hard-code.

## 7. `cresco-ai-mutation/v3`

Preferred external result cho **Selected element / Selected subtree**.

V3 mô tả semantic design intent trước khi Cresco lower về runtime controls.

Ví dụ:

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

AI không cần mint final Elementor ID cho node mới.

## 8. `cresco-ai-mutation/v2`

Semantic mutation thấp hơn v3.

Pipeline thường:

```text
v3
→ SemanticDesignCompiler
→ v2
→ AIMutationCompiler
→ cresco-layer-patch/v1
```

V2 vẫn được import để tương thích.

## 9. `cresco-layer-ai-result/v1`

Format tương thích khi AI trả một Elementor element tree hoàn chỉnh cho một target cụ thể.

Top-level:

```text
schema
target
element
label
```

Không phải format ưu tiên của external workflow 0.24.

## 10. `cresco-layer-patch/v1`

Internal/transport patch ổn định và preferred external result cho **Entire page**.

Top-level:

```text
schema
base
scope
label
operations
```

Operations:

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

### Document scope

```json
{
  "mode": "document",
  "rootElementId": "",
  "elementIds": []
}
```

### Top-level insertion

```json
{
  "operation": "insert-element",
  "parentId": "",
  "position": 999999,
  "element": {
    "ref": "$new:section",
    "elType": "container",
    "settings": {},
    "elements": []
  }
}
```

`InternalPatchCompiler` chuẩn hóa ID cho inserted subtree trước khi runtime validator/applier xử lý.

### Replace document

```json
{
  "operation": "replace-document",
  "content": [],
  "pageSettings": {}
}
```

Đây là destructive operation; chỉ dùng khi user yêu cầu full rebuild.

## 11. `cresco-layer-patch-validation/v2`

Runtime validation contract/report.

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

## 12. `cresco-fidelity-policy/v1`

Policy đo rendered fidelity.

Các phần:

```text
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

Default overall threshold:

```text
96
```

## 13. `cresco-fidelity-snapshot/v1`

Rendered snapshot từ Elementor preview.

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

## 14. `cresco-geometry-graph/v1`

Quan hệ DOM/hình học:

```text
parent
children
previous sibling
next sibling
relative geometry
```

## 15. `cresco-fidelity-report/v1`

Các category hiện dùng:

```text
geometry
spacing
typography
color
structure
quality
```

Report là computed/rendered verification, không phải tuyên bố raster pixel-perfect tuyệt đối.

## 16. `cresco-fidelity-gate/v1`

PASS/BLOCKED dựa trên:

- overall threshold;
- category floor;
- blocking rules;
- verification evidence.

`no-verification-evidence` là blocker.

## 17. `cresco-site-settings/v1`

Contract riêng cho active Elementor Kit:

- Global Colors;
- Global Fonts;
- Theme Style;
- layout defaults;
- responsive foundation;
- optional theme/Pro integration khi runtime hỗ trợ.

Không trộn Site Settings spec vào element-level AI patch.

## 18. Ma trận chọn schema

```text
External single JSON   → cresco-external-ai-package/v1
External ZIP           → cresco-ai-bundle/v4
External policy        → cresco-external-exchange-policy/v1
AI context             → cresco-ai-context/v3
Widget/subtree output  → cresco-ai-mutation/v3
Document output        → cresco-layer-patch/v1
Internal apply         → cresco-layer-patch/v1
Runtime validation     → cresco-layer-patch-validation/v2
Rendered verification  → cresco-fidelity-report/v1 + cresco-fidelity-gate/v1
```

Nếu `cresco-package.json.resultContract` của một export cụ thể yêu cầu khác, contract nằm trong chính package đó là nguồn ưu tiên cho AI.
