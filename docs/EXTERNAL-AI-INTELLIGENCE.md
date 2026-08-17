# Cresco Layer External AI Intelligence

> **Tài liệu lịch sử:** mô tả foundation ở giai đoạn 0.20. External workflow 0.24 đã nâng bundle/policy/UX, nhưng các nguyên tắc Semantic Design Compiler, runtime legality và rendered verification vẫn còn giá trị.

Cresco Layer 0.20 mở rộng external-AI foundation bằng:

- **Semantic Design Compiler**;
- professional Design Intelligence;
- rendered verification.

Elementor vẫn là source of truth. Cresco không trở thành page builder và không giữ design system song song.

## Mục tiêu

External model nên reasoning bằng **design intent**, không cần ghi nhớ Elementor internals.

Cresco chịu trách nhiệm export đủ context và lower semantic intent về controls được active runtime chứng minh.

```text
Elementor working document
  -> Exact Runtime + Active Kit + layout/visual context
  -> task-aware runtime widget discovery
  -> Widget Intelligence + Semantic Scene + Placement/Mutation Boundary
  -> Design Intelligence + Structure Grammar + Semantic Bindings
  -> external AI returns cresco-ai-mutation/v3
  -> SemanticDesignCompiler
  -> cresco-ai-mutation/v2
  -> AIMutationCompiler
  -> Cresco ID allocation + deterministic normalization
  -> internal cresco-layer-patch/v1
  -> SemanticPatchGuard
  -> Preview / Apply / persistence verification
  -> rendered verification
```

## Các lớp intelligence giữ nguyên

Top-level context vẫn có thể dùng `cresco-ai-context/v3` và expose:

- task-aware runtime discovery;
- Widget Intelligence;
- Semantic Scene;
- Construction Plan;
- Placement Context;
- Mutation Boundary;
- control examples;
- Active Kit/design system;
- responsive foundation;
- semantic content bindings.

Final ID của node mới do Cresco sở hữu. Existing ID là authoritative. Global references/Dynamic Tags preserve-by-default. Custom CSS là last resort.

## Design Intelligence

Schema:

```text
cresco-design-intelligence/v1
```

Context có thể gồm:

- product archetype;
- style keywords;
- variance/motion/density dials;
- semantic spacing scale;
- quality priorities;
- anti-patterns.

Nguồn tham khảo cấp cao: `nextlevelbuilder/ui-ux-pro-max-skill` MIT, revision `a38d04c3d5c298c851dbe5e6ee1965ee3de42cb5`. Cresco không có runtime dependency vào repo đó.

Active Kit luôn ưu tiên hơn generic design suggestion.

## Semantic Design Mutation v3

`cresco-ai-mutation/v3` được ưu tiên cho external design work.

Node mới có thể mô tả:

```text
content
layoutIntent
styleIntent
responsiveIntent
accessibilityIntent
children
settings  # expert escape hatch
```

Existing element dùng `designChanges` + `elementId` và semantic intent.

Compiler phải đọc live type/control metadata trước khi lower xuống v2. Nếu không chứng minh được control/option/unit/responsive suffix, compilation phải dừng.

CSS concept tồn tại không phải bằng chứng Elementor setting tồn tại.

## Structure Grammar

Schema:

```text
cresco-structure-grammar/v1
```

Quy tắc:

- structural element như Container có thể sở hữu children khi scope cho phép;
- widget không nhận arbitrary child tree;
- Accordion/Tabs/Carousel/Menu/Loop và nested widget tương tự có runtime-managed nested content;
- internal storage của chúng phải đi qua native controls/repeaters hoặc dedicated adapter, không suy ra từ DOM.

## Bundle lịch sử

Ở 0.20, AI Bundle v2 bổ sung `05-design-intelligence.json` cùng Context v3, task brief, optional raster/reference image.

External workflow 0.24 hiện dùng `cresco-ai-bundle/v4`; xem `AI-EXPORT-IMPORT.md` và `SCHEMA-REFERENCE.md`.

## Rendered verification

Sau apply, verifier có thể đọc preview iframe và so semantic intent với computed geometry/style/accessibility condition.

Đây là deterministic verification, **không phải tuyên bố pixel-perfect image comparison**.

Mismatch không được auto-mutate trang âm thầm; repair phải là một reviewed mutation mới.

## Backward compatibility

Các format cũ vẫn có thể được normalizer hỗ trợ tùy version:

```text
cresco-ai-mutation/v2
cresco-layer-patch/v1
cresco-layer-ai-result/v1
```

Các invariant không đổi:

- không reintroduce freshness checksum làm AI contract bắt buộc;
- reject serialization placeholders;
- delta-first;
- destructive rebuild phải explicit;
- `SemanticPatchGuard` là authority trước apply;
- persist qua Elementor Document API;
- verify sau apply.