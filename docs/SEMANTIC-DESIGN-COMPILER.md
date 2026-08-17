# Semantic Design Compiler

Cresco Layer 0.20 giới thiệu `cresco-ai-mutation/v3`, một contract dành cho AI mô tả **design intent** ở tầng cao hơn Elementor control name.

External model nên nói **giao diện cần trông và hoạt động như thế nào**. Cresco chịu trách nhiệm resolve intent với active Elementor runtime rồi lower xuống mutation/patch có thể validate.

```text
External AI
  -> cresco-ai-mutation/v3
  -> SemanticDesignCompiler
  -> exact active-runtime controls
  -> cresco-ai-mutation/v2
  -> AIMutationCompiler
  -> cresco-layer-patch/v1
  -> SemanticPatchGuard
  -> Preview / Apply / Verify
```

## Add và Rebuild

Node mới có thể dùng:

```text
content
layoutIntent
styleIntent
responsiveIntent
accessibilityIntent
children
settings  # expert escape hatch
```

Ví dụ:

```json
{
  "schema": "cresco-ai-mutation/v3",
  "intent": "add",
  "target": {
    "postId": 3,
    "id": "abc1234"
  },
  "placement": {
    "mode": "inside-end"
  },
  "nodes": [
    {
      "ref": "$new:hero-content",
      "widgetIntent": "container",
      "layoutIntent": {
        "direction": "column",
        "gap": "24px",
        "padding": "32px"
      },
      "responsiveIntent": {
        "mobile": {
          "layout": {
            "gap": "16px"
          }
        }
      },
      "children": [
        {
          "ref": "$new:title",
          "widgetIntent": "heading",
          "content": {
            "text": "A healthier, drier home starts here",
            "semanticLevel": "h1"
          },
          "styleIntent": {
            "fontSize": "clamp(40px, 6vw, 72px)"
          }
        }
      ]
    }
  ]
}
```

Node mới không cần final Elementor ID; Cresco cấp ID sau khi validate/compile.

## Edit existing UI

Existing element dùng `designChanges`:

```json
{
  "schema": "cresco-ai-mutation/v3",
  "intent": "edit",
  "target": {
    "postId": 3,
    "id": "abc1234"
  },
  "designChanges": [
    {
      "elementId": "def5678",
      "content": {
        "text": "Updated headline"
      },
      "styleIntent": {
        "fontSize": "48px",
        "textAlign": "center"
      },
      "responsiveIntent": {
        "mobile": {
          "style": {
            "fontSize": "32px",
            "textAlign": "left"
          }
        }
      }
    }
  ]
}
```

Cresco đọc live element theo ID, xác định type thật, chỉ dùng control có trong active runtime rồi compile semantic intent thành v2 `update-setting` operations.

## Fail-closed guarantees

Compiler không được invent control name hoặc responsive suffix.

Một semantic property chỉ được compile khi:

- exact candidate control tồn tại;
- select/choose value là option hợp lệ;
- unit được control hỗ trợ;
- responsive device hợp lệ với Elementor breakpoint manager;
- fluid expression như `clamp()`/`min()`/`max()`/`calc()`/`var()` chỉ đi qua native `custom` unit khi control thật sự hỗ trợ.

Nếu mapping ambiguous hoặc unavailable:

```text
compile must stop
```

không được fallback bằng cách invent control.

Explicit `settings` vẫn có thể dùng cho expert case nhưng phải qua `SemanticPatchGuard`.

## Structure policy

Arbitrary Elementor children chỉ được đặt dưới structural element type như Container khi scope cho phép.

Widget không được coi như generic layout container.

Các widget nested phức tạp như:

```text
Accordion
Tabs
Carousel
Menu
Loop
```

phải dùng runtime-proven native repeater/content controls hoặc dedicated adapter; không suy ra internal storage từ rendered DOM.

## Backward compatibility

Các format vẫn có thể được hỗ trợ:

```text
cresco-ai-mutation/v2
cresco-layer-patch/v1
cresco-layer-ai-result/v1
```

Mutation v3 được ưu tiên cho external design work vì giảm số implementation detail của Elementor mà model phải tự quản lý.

## Nguyên tắc phân công

```text
AI
→ quyết định design intent

SemanticDesignCompiler
→ map intent sang runtime-proven control

Patch/Semantic validators
→ quyết định mutation có an toàn/hợp lệ không

Elementor
→ persist + render
```

Đây là ranh giới quan trọng để external AI không phải trở thành một bản sao không đáng tin cậy của Elementor internals.