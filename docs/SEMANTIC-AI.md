# Cresco Semantic Local AI

Cresco Layer 0.8 bổ sung pipeline semantic context + planning giữa Elementor và local model đã cấu hình.

## Vì sao cần semantic context?

Local model không nhận raw Elementor document hoặc full catalog dump. Cresco chuyển selected runtime element thành context gọn, tập trung vào mục đích và capability thật.

Mục tiêu:

- giảm noise;
- tránh model invent setting key;
- giúp model local nhỏ vẫn reasoning hữu ích;
- giữ execution deterministic.

Pipeline:

```text
Selected Elementor element
  -> runtime capability profile
  -> semantic context compiler
  -> expert card + context graph + effective responsive state
  -> redaction + context budget
  -> local model diagnosis + evidence
  -> cresco-layer-local-ai-plan/v2
  -> exact skill whitelist validation
  -> runtime parameter pre-validation
  -> preview
  -> Skill Runtime resolve
  -> one Elementor history transaction
```

## Semantic context

Schema:

```text
cresco-layer-semantic-context/v1
```

Các block chính:

- `task` — user instruction + analysis goal.
- `selectedElement` — selected Elementor runtime element.
- `expertCard` — semantic purpose, important parts, design rules, common failure patterns.
- `contextGraph` — parent/sibling/direct-child summary khi neighbor context bật.
- `effectiveState` — explicit/inherited/effective values theo responsive device.
- `availableSkills` — chỉ executable skills được runtime chứng minh.
- `designSystem` — safe summary của global/dynamic bindings.
- `constraints` — scope/preservation/execution rules.
- `contextBudget` — metadata về trimming.

AI không dùng raw Elementor setting name làm vocabulary chính. `availableSkills` expose semantic property/input rules nhưng vẫn giữ exact `skillId` cho deterministic execution.

## Expert Cards

`WidgetExpertRegistry` nhóm runtime type thành semantic family như:

```text
layout
heading
text
button
image
form
navigation
query
carousel
disclosure
video
icon
code
commerce
```

Third-party/unknown widget fallback về generic runtime-proven profile.

Expert card là guidance, không tạo capability. Runtime metadata vẫn là source of truth.

## Responsive effective state

`EffectiveValueResolver` cho mỗi device biết:

- có explicit value không;
- explicit value;
- effective value;
- source của effective value: explicit/inherited/runtime-default/unset;
- inherited từ device lớn nào.

Nhờ vậy model hiểu được câu như:

> “Mobile vẫn đang inherit desktop padding.”

mà không cần đọc raw responsive suffix.

## Planning contract

Model phải trả:

```text
cresco-layer-local-ai-plan/v2
```

Plan gồm:

- intent/confidence;
- summary;
- diagnosis problem;
- evidence statements dựa trên semantic context;
- requested skills với exact `skillId`, params, reason;
- clarification questions khi evidence chưa đủ.

System prompt phải mang output schema + parameter grammar phù hợp cho dimensions, sliders, numbers, switchers, selects, URLs và structured values.

Một responsive skill có thể được yêu cầu nhiều lần nếu params thật sự khác, ví dụ desktop padding và mobile padding.

## Prompt-injection boundary

Page text, label, caption, placeholder và `contentHint` là **untrusted data**, không phải instruction.

Chỉ:

- user task;
- Cresco system contract

mới là instruction authority.

## Runtime validation

Plan đúng schema chưa có nghĩa executable.

`PlanValidator` resolve từng step qua real `WidgetSkillRuntime` và reject:

- unknown/unavailable skill;
- invalid unit/range/option/device;
- operation ngoài selected element;
- non-setting operation;
- expert/structural/external-risk skill trong semantic AI mode;
- write vào Global-bound setting;
- write vào Dynamic Tag-bound setting;
- no-op;
- contradictory values cùng native setting.

Kết quả là native before/after preview trước Apply.

## Local inference modes

### Browser / Local Bridge

WordPress chuẩn bị + redact context/contract. Browser gửi request đã chuẩn bị tới local model, sau đó gửi **plan result** về WordPress.

Server rebuild current context và validate lại trước khi chấp nhận.

Saved API token không expose sang browser inference.

### WordPress server direct

WordPress server gọi local endpoint trực tiếp.

- Ollama: `/api/chat`.
- OpenAI-compatible local providers: `/chat/completions`.

## Apply boundary

Accepted plan vẫn không được write Elementor trực tiếp.

Trước Apply:

```text
re-resolve requested skills
→ validate current live settings
→ only update-setting/remove-setting cho selected element
→ one Elementor history transaction
```

Cresco snapshot touched settings và rollback nếu batch execution lỗi.

## Accuracy strategy

Cresco tăng độ chính xác bằng cách **thu nhỏ bài toán**, không bắt model nhớ Elementor:

1. runtime capability xác định cái gì có thể làm;
2. semantic context giải thích current state;
3. expert cards giải thích điều gì quan trọng;
4. context budget loại noise;
5. output schema + parameter grammar luôn được cung cấp;
6. diagnosis + evidence là bắt buộc;
7. exact skill IDs được whitelist;
8. Skill Runtime pre-validates params;
9. user xem Preview trước execution.

Các phiên bản mới hơn có thể nâng schema/evidence model, nhưng trust boundary này phải được giữ.