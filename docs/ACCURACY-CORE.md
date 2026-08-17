# Lõi độ chính xác Cresco Layer 0.9

Cresco Layer 0.9 chuyển Local AI từ một bộ planner nhận danh sách control rộng sang pipeline **truy xuất skill theo task + kiểm chứng bằng evidence** dành cho Elementor.

## Pipeline

```text
Elementor element đang chọn
  -> runtime skill profile
  -> Semantic Skill Identity V2
  -> truy xuất skill theo task (thường tối đa 18 candidate)
  -> Context Graph V2 + effective responsive state
  -> browser render observation có giới hạn
  -> machine-readable facts
  -> Local AI diagnosis + fact citations
  -> EvidenceValidator
  -> WidgetSkillRuntime validate parameter chính xác
  -> semantic confidence score
  -> preview
  -> native Elementor transaction
  -> model read-back + render observation verification
  -> rollback nếu model read-back thất bại
```

## Semantic Skill Identity V2

Native Elementor control ID vẫn là **execution identifier duy nhất**. Cresco chỉ bổ sung semantic metadata không thực thi như:

```text
semanticId
semanticBase
targetPart
property
state
purpose
displayLabel
```

Mục tiêu là phân biệt các control tên gần giống nhau mà không invent native key.

Ví dụ:

```text
control.flex_direction
  semanticId: layout.container.direction#flex-direction
  displayLabel: Container · Direction (Flex Direction)
```

## Truy xuất skill theo task

Local AI không còn nhận toàn bộ executable control của widget. `SkillRetriever`:

- loại capability rủi ro cao;
- xếp hạng native skill theo user task;
- xét expert profile;
- responsive intent;
- target part;
- semantic role.

Context Local AI mặc định chứa tối đa **18 candidate phù hợp task** trước bước context budgeting.

## Evidence có thể kiểm bằng máy

Planning schema `cresco-layer-local-ai-plan/v3` yêu cầu mỗi evidence item trỏ tới một fact chính xác trong `cresco-layer-semantic-context/v2`.

```json
{
  "factId": "skill.s01.mobile.effective",
  "operator": "eq",
  "value": "8px",
  "statement": "Mobile padding hiện là 8px."
}
```

Cresco tự đánh giá claim. Các trường hợp sau chặn execution:

- fact không tồn tại;
- operator không hỗ trợ;
- comparison sai.

## Semantic confidence

Confidence do model tự báo chỉ là một thành phần. Cresco kết hợp:

- AI self-report;
- evidence validity;
- mức khớp task → skill retrieval;
- context completeness;
- exact runtime validation.

Chỉ **combined score** mới được so với minimum confidence đã cấu hình.

## Context Graph V2

Context gồm:

- state của element đang chọn;
- tóm tắt parent/sibling/child;
- quan hệ width/overflow suy ra;
- nguồn Global/Dynamic binding;
- optional browser render observation.

DOM/computed style chỉ là **sensor**. Elementor model/runtime metadata vẫn là source of truth.

## Verification sau Apply

Sau khi plan hợp lệ được apply qua `document/elements/settings`, Cresco đọc lại live Elementor settings.

Nếu native setting được yêu cầu không nhận đúng giá trị mong đợi:

```text
apply
→ read-back mismatch
→ rollback touched settings
```

Với visual role, Cresco còn so sánh before/after render observation có giới hạn. Nếu model state đã đổi nhưng không có measurable render delta trên selected element, Cresco cảnh báo thay vì giả định thay đổi đã có hiệu lực.

## Trust boundary

Local AI vẫn không được:

- invent Elementor settings;
- thực thi arbitrary CSS/JavaScript;
- ghi trực tiếp Elementor document;
- sửa sibling/child ngoài selected-element scope;
- thực thi expert/structural/external skills;
- tự tách Global hoặc Dynamic references bằng generic AI planning;
- bypass evidence, runtime hoặc post-apply validation.

Nguyên tắc chính: **AI chẩn đoán và đề xuất; runtime + validator + Elementor quyết định điều gì thực sự được phép thực thi.**