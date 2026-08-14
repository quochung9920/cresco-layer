# Cresco Semantic Local AI

Cresco Layer 0.8 adds a semantic context and planning pipeline between Elementor and the configured local model.

## Why semantic context

The local model does not receive a raw Elementor document or a full catalog dump. Cresco first translates the selected runtime element into a compact, purpose-oriented context. This reduces noise, prevents the model from inventing Elementor setting keys and makes small local models more useful.

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

The context schema is `cresco-layer-semantic-context/v1`. Its stable top-level blocks are:

- `task`: the user's instruction and analysis goal;
- `selectedElement`: the selected Elementor runtime element;
- `expertCard`: semantic purpose, important parts, design rules and common failure patterns for the widget family;
- `contextGraph`: summarized parent, siblings and direct children when neighbor context is enabled;
- `effectiveState`: explicit, inherited and effective values by responsive device for executable skills;
- `availableSkills`: only executable skills proven by the selected element's current Elementor capability metadata;
- `designSystem`: safe summary of global/dynamic binding presence;
- `constraints`: scope, preservation and execution rules;
- `contextBudget`: metadata describing context trimming.

Raw Elementor setting names are not the AI vocabulary. `availableSkills` exposes a semantic `property`, input kind, allowed options/units/ranges, devices and risk while keeping the exact skill ID required for deterministic execution.

## Expert cards

`WidgetExpertRegistry` maps runtime element types into semantic families such as layout, heading, text, button, image, form, navigation, query, carousel, disclosure, video, icon, code and commerce. Third-party or unknown widgets fall back to the generic runtime-proven profile.

Expert cards are guidance, not execution code. Runtime capabilities remain the source of truth.

## Responsive effective state

`EffectiveValueResolver` reports each available device with:

- whether a value is explicitly stored for that device;
- the explicit value;
- the effective value;
- whether the effective value is explicit, inherited, a runtime default or unset;
- the larger device from which an inherited value came.

This lets the model reason about statements such as "mobile is still inheriting desktop padding" without reading raw responsive setting suffixes.

## Planning contract

The model must return `cresco-layer-local-ai-plan/v2` JSON. A plan contains:

- intent and confidence;
- summary;
- diagnosis problem;
- one or more evidence statements grounded in the semantic context;
- requested skills with exact `skillId`, parameters and reason;
- clarification questions when evidence is insufficient.

The full JSON Schema is included in the model system message on every analysis request. The system prompt also includes the Cresco skill parameter grammar for dimensions, sliders, numbers, switchers, selects, URLs and structured expert values.

The same responsive skill can be requested more than once when the parameter sets are genuinely different, for example desktop and mobile padding.

## Prompt-injection boundary

Page text, labels, captions, placeholders and `contentHint` values are explicitly described to the local model as untrusted data. They are context, not instructions. Only the user task and Cresco's system contract are instructions.

## Runtime validation

A structurally valid model plan is not considered executable yet. `PlanValidator` resolves every proposed step through the real `WidgetSkillRuntime` before Cresco marks the plan accepted.

The validator rejects:

- unknown or unavailable skills;
- invalid units, ranges, options or responsive devices;
- operations outside the selected element;
- non-setting operations;
- expert, structural or external-risk skills in semantic AI mode;
- writes to Global-bound settings;
- writes to Dynamic Tag-bound settings;
- no-op changes;
- contradictory values for the same native setting.

This produces a native before/after preview before the user can apply the plan.

## Local inference modes

### Browser / Local Bridge

The WordPress server prepares and redacts the semantic context and planning contract. The browser sends that prepared request to the local model endpoint, then returns only the generated plan to WordPress. Cresco rebuilds the current context and validates the plan server-side before accepting it.

Saved API tokens are never exposed to browser inference.

### WordPress server direct

The WordPress server sends the semantic request directly to the configured local endpoint. Ollama uses `/api/chat`; OpenAI-compatible local providers use `/chat/completions`.

## Apply boundary

Accepted plans are still not allowed to write Elementor directly. Before apply, the editor resolves every requested skill again against current live settings. Only `update-setting` and `remove-setting` operations for the selected element are accepted.

All resolved operations execute inside one Elementor history transaction. Cresco snapshots touched live settings and attempts rollback if any operation in the batch fails.

## Accuracy strategy

Cresco improves local-model accuracy by reducing the task rather than asking the model to memorize Elementor:

1. runtime capabilities establish what is actually possible;
2. semantic context explains the current state in a model-friendly vocabulary;
3. expert cards explain what matters for the widget family;
4. context budgeting removes unrelated noise;
5. the full output schema and parameter grammar are supplied every time;
6. diagnosis and evidence are mandatory before planning;
7. exact skill IDs are whitelisted;
8. the native Skill Runtime pre-validates every parameter;
9. the user receives a preview before execution.
