# Schema Reference — Cresco Layer 0.23

Tài liệu tra cứu nhanh các schema/contract chính.

## 1. `cresco-layer-ai-package/v2`

Dùng cho export AI context.

Các vùng quan trọng:

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

Trong Elementor Editor, package có thể được enrich thêm:

```text
runtimeCapabilities
capabilityLock
siteDesignContext
taskRuntimeDiscovery
fidelityPolicy
visualContext
```

## 2. `cresco-control-registry/v1`

Normalized runtime control contract.

Top-level:

```text
schema
controlMetadataVersion
widgets
elements
responsiveSuffixes
```

Control contract:

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

## 3. `cresco-layer-patch/v1`

Transport schema cho AI patch.

Top-level:

```text
schema
base
scope
label
operations
```

`base`:

```text
postId
```

`scope`:

```text
mode
rootElementId
elementIds
```

Các operation hiện được hỗ trợ:

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

### `update-setting`

```json
{
  "operation": "update-setting",
  "elementId": "abc123",
  "setting": "title_color",
  "value": "#ffffff"
}
```

### `remove-setting`

```json
{
  "operation": "remove-setting",
  "elementId": "abc123",
  "setting": "title_color"
}
```

### `replace-settings`

```json
{
  "operation": "replace-settings",
  "elementId": "abc123",
  "settings": {}
}
```

### `replace-element`

Yêu cầu replacement giữ target ID.

```json
{
  "operation": "replace-element",
  "elementId": "abc123",
  "preserveChildren": true,
  "element": {
    "id": "abc123",
    "elType": "widget",
    "widgetType": "heading",
    "settings": {},
    "elements": []
  }
}
```

### `insert-element`

```json
{
  "operation": "insert-element",
  "parentId": "container1",
  "position": 0,
  "element": {
    "id": "newid",
    "elType": "widget",
    "widgetType": "heading",
    "settings": {},
    "elements": []
  }
}
```

### `move-element`

```json
{
  "operation": "move-element",
  "elementId": "abc123",
  "parentId": "container2",
  "position": 1
}
```

### `replace-document`

```json
{
  "operation": "replace-document",
  "content": [],
  "pageSettings": {}
}
```

## 4. `cresco-layer-patch-validation/v2`

Runtime validation report cho patch.

Các rule chính:

```text
registered-control
responsive-capability
unit
option
range
global-reference
```

Report có thể gồm:

```text
schema
status
checkedSettings
checkedElements
preservedUnknownSettings
rules
```

## 5. `cresco-site-settings/v1`

Semantic spec cho Elementor Kit/Global Settings.

Schema này không dùng cho page element mutation.

Các mode trong contract code gồm:

```text
merge
sync-owned
force
```

Tên cụ thể trong PHP contract phải được xem là authoritative khi tích hợp.

## 6. `cresco-fidelity-policy/v1`

Policy cho rendered fidelity.

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

Default overall threshold:

```text
96.0
```

## 7. `cresco-fidelity-snapshot/v1`

Rendered/computed snapshot của preview hiện tại.

```text
schema
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

Element record:

```text
id
parentId
children
sibling
geometry
scroll
layout
spacing
typography
visual
quality
```

## 8. `cresco-geometry-graph/v1`

```text
schema
nodes
edges
```

Edge type foundation:

```text
parent
next-sibling
```

## 9. `cresco-visual-context/v1`

Wrapper được browser export integration thêm vào AI package.

```text
schema
source
currentBreakpointOnly
snapshot
limitations
```

0.23 contract:

```text
currentBreakpointOnly = true
```

## 10. `cresco-fidelity-report/v1`

Kết quả scoring.

```text
schema
mode
overall
categories
issues
coverage
gate
```

Mode hiện tại:

```text
snapshot-compare
intent-verification
```

Categories:

```text
geometry
spacing
typography
color
structure
quality
```

## 11. `cresco-fidelity-gate/v1`

```text
schema
pass
status
threshold
overall
blockingIssues
categoryFloorFailures
note
```

Gate pass khi:

```text
overall >= threshold
AND blockingIssues rỗng
AND categoryFloorFailures rỗng
```

No rendered evidence phải tạo blocking issue:

```text
no-verification-evidence
```

## 12. `cresco-visual-verification/v1`

Existing rendered semantic verification contract.

Nó vẫn được giữ để backward compatibility. Fidelity 0.23 được gắn thêm dưới dạng report/gate thay vì phá schema cũ.

## 13. Runtime Snapshot

Elementor runtime snapshot dùng schema riêng trong `RuntimeSnapshot.php`:

```text
cresco-elementor-snapshot/v1
```

Dùng cho full inspection/configuration catalog, không phải transport patch.

## 14. Quy tắc versioning

- Không đổi schema transport chỉ để thêm metadata có thể enrich backward-compatible.
- Nếu meaning/required field thay đổi không tương thích, tạo schema version mới.
- Contract test phải kiểm token/invariant quan trọng.
- Parser nên fail rõ ràng với schema không hỗ trợ.
- Unknown persisted Elementor field được preserve theo lossless policy, không được coi là schema extension của Cresco.
