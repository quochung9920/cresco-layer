# Cresco Layer Accuracy Core 0.9

Cresco Layer 0.9 changes Local AI from a broad control-list planner into a task-retrieved, evidence-checked Elementor planning pipeline.

## Pipeline

```text
Selected Elementor element
  -> runtime skill profile
  -> Semantic Skill Identity V2
  -> task-aware skill retrieval (normally 18 candidates)
  -> Context Graph V2 + effective responsive state
  -> bounded browser render observation
  -> machine-readable facts
  -> Local AI diagnosis + fact citations
  -> EvidenceValidator
  -> exact WidgetSkillRuntime parameter validation
  -> semantic confidence score
  -> preview
  -> native Elementor transaction
  -> model read-back + render observation verification
  -> rollback when model read-back fails
```

## Semantic Skill Identity V2

Native Elementor control IDs remain the only execution identifiers. Cresco adds non-executable semantic metadata such as `semanticId`, `semanticBase`, `targetPart`, `property`, `state`, `purpose`, and `displayLabel` so similarly named controls can be distinguished without inventing a native key.

Example:

```text
control.flex_direction
  semanticId: layout.container.direction#flex-direction
  displayLabel: Container · Direction (Flex Direction)
```

## Task-aware retrieval

Local AI no longer receives every executable control for the widget. `SkillRetriever` filters higher-risk capabilities and ranks the remaining native skills against the user task, expert profile, responsive intent, target part and semantic role. The default Local AI context contains at most 18 task-relevant candidates before context budgeting.

## Machine-verifiable evidence

Planning schema `cresco-layer-local-ai-plan/v3` requires each evidence item to cite an exact fact from `cresco-layer-semantic-context/v2`:

```json
{
  "factId": "skill.s01.mobile.effective",
  "operator": "eq",
  "value": "8px",
  "statement": "Mobile padding is currently 8px."
}
```

Cresco evaluates the claim itself. A missing fact, unsupported operator or false comparison blocks execution.

## Semantic confidence

The Local AI model's self-reported confidence is only one component. Cresco combines:

- AI self-report;
- evidence validity;
- task-to-skill retrieval match;
- context completeness;
- exact runtime validation.

Only the combined score is compared with the configured minimum confidence.

## Context Graph V2

The context includes selected element state, parent/sibling/child summaries, derived width/overflow relationships, Global/Dynamic binding sources, and an optional browser render observation. DOM/computed style data is a sensor only; Elementor model/runtime metadata remains the source of truth.

## Post-apply verification

After a validated plan is applied through `document/elements/settings`, Cresco reads the live Elementor settings back. If a requested native setting did not take the expected value, the touched settings are rolled back. For visual roles, Cresco also compares bounded before/after render observations and warns when the model changed but no measurable selected-element render delta was observed.

## Trust boundary

Local AI still cannot:

- invent Elementor settings;
- execute arbitrary CSS or JavaScript;
- write the Elementor document directly;
- modify siblings/children outside the selected-element scope;
- execute expert/structural/external skills;
- detach Global or Dynamic references through generic AI planning;
- bypass evidence, runtime or post-apply validation.
