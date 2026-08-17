# Professional Design Reasoning

Cresco Layer 0.21 bổ sung `cresco-design-reasoning/v1`, một lớp design brief deterministic dành cho external AI. Lớp này nằm **trên Exact Runtime + Widget Intelligence** và **dưới output mutation contract**.

Mục tiêu không phải biến Cresco thành một design-system product. Active Elementor Kit vẫn là editable source of truth.

Phân công trách nhiệm:

```text
External AI / Design Reasoning
→ quyết định hierarchy, composition, product/page intent

Cresco / Exact Runtime
→ quyết định widget/control nào thực sự hợp lệ
```

## Nguồn tham khảo

Workflow tham khảo các khái niệm từ dự án MIT công khai `nextlevelbuilder/ui-ux-pro-max-skill` tại revision:

```text
a38d04c3d5c298c851dbe5e6ee1965ee3de42cb5
```

Các khái niệm được áp dụng ở mức nguyên tắc:

- phân tích product/page intent trước style;
- accessibility + interaction safety trước decoration;
- variance/motion/density controls;
- reasoning theo product/page cụ thể;
- quality checks cho responsive, typography, forms, navigation, performance;
- checklist trước delivery.

Cresco không vendor dataset/search tooling/Python/generated design system từ repo tham khảo và không có runtime dependency vào nó.

## Contract `designReasoning`

Các field có thể gồm:

- `productArchetype` — SaaS, dashboard, commerce, service, editorial, portfolio hoặc general web.
- `pageArchetype` — landing, dashboard, pricing, checkout, article, portfolio, lead generation hoặc content section.
- `audienceSignals` — tín hiệu conservative suy ra từ task.
- `objective` — kết quả thiết kế vùng/page cần đạt.
- `visualHierarchy` — thứ tự attention priority.
- `compositionStrategy` — pattern composition khuyến nghị.
- `pageStrategy` — action/proof/rhythm guidance.
- `designVocabulary` — semantic emphasis/surface/depth/spacing roles.
- `qualityGates` — critical/high/advisory checks.
- `referenceTranslation` — cách chuyển reference image qua site/runtime hiện tại.
- `antiPatterns` — failure modes theo product/task.
- `decisionOrder` — thứ tự xử lý conflict.
- `elementorTranslation` — rule hand-off sang semantic mutation v3.

## Decision order

Priority mặc định:

```text
accessibility + behavior safety
  -> user task + hierarchy
  -> responsive layout
  -> Active Kit consistency
  -> widget/runtime fit
  -> typography + color
  -> depth + motion
  -> decorative polish
```

Một reference đẹp không được override accessibility, form behavior, responsive correctness hoặc Elementor-native semantics.

## Reference image

Khi có reference image, model được yêu cầu phân tích:

- visual hierarchy;
- section composition;
- relative proportions;
- spacing rhythm;
- typography character;
- color relationships;
- surface depth;
- component patterns;
- imagery treatment;
- visible interaction/motion cues.

Sau đó chuyển các quality này qua:

```text
Widget Intelligence
+ Active Kit
+ Exact Runtime
```

Không copy mù raw pixel, framework markup, brand identity hoặc interaction mà runtime không hỗ trợ.

## Quality gates

### Critical

- readable contrast;
- visible focus;
- touch usability;
- behavior/external configuration preservation.

### High priority

- responsive overflow;
- hierarchy;
- Active Kit consistency;
- layout stability.

### Advisory

- purposeful motion;
- richer product proof;
- section rhythm.

Quality gate ở đây là **design guidance**. Runtime legality và mutation safety vẫn do compiler, `MutationNormalizer`, `SemanticPatchGuard` và post-apply verification enforce.

## AI Bundle

Ở AI Bundle v3, `06-design-reasoning.json` là file riêng và task brief hướng model đọc nó trước khi trả mutation.

Luồng mong muốn:

```text
Task + reference
  -> Design Reasoning
  -> Widget Intelligence / Structure Grammar
  -> Exact Runtime / Active Kit
  -> cresco-ai-mutation/v3
  -> SemanticDesignCompiler
  -> validation / preview / apply
  -> rendered verification
```

AI bên ngoài chỉ nên trả final mutation/result theo contract, không trả thêm design-analysis prose nếu package yêu cầu JSON importable.