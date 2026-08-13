# Cresco Local AI Manager

Cresco Layer 0.7 introduces an administrator-only Local AI Manager inside the existing Cresco Layer page.

## Product boundary

Local AI is an analysis and planning layer, not an Elementor executor.

```text
User request
  -> deterministic router when possible
  -> local AI analysis for ambiguous/complex requests
  -> cresco-layer-local-ai-plan/v1
  -> validate exact skill IDs against the selected runtime skill registry
  -> preview/risk policy
  -> Cresco Skill Runtime
  -> Elementor
```

The model is never allowed to invent Elementor setting keys, emit arbitrary CSS as a replacement for native controls, write the document directly, execute JavaScript, escape the selected scope or bypass Cresco validation.

## Providers

The manager exposes adapters for:

- Ollama (`http://127.0.0.1:11434`, `/api/version`, `/api/tags`, `/api/chat`)
- LM Studio OpenAI-compatible API (`http://127.0.0.1:1234/v1`, `/models`, `/chat/completions`)
- llama.cpp server (`http://127.0.0.1:8080/v1`, `/models`, `/chat/completions`)
- a custom OpenAI-compatible local API

The endpoint policy accepts only localhost, loopback, RFC1918 private IPv4 ranges, `host.docker.internal`, or `.local` hosts. This prevents the Local AI module from becoming a generic remote HTTP relay.

## Connection modes

### Browser / Local Bridge

Recommended when WordPress is hosted remotely. The browser running Elementor/Cresco talks to the local endpoint on the user's computer. WordPress does not need network access to that machine.

Saved API tokens are intentionally not exposed to browser-mode JavaScript. Use an unauthenticated trusted local endpoint/bridge or server-direct mode when an Authorization header is required.

### WordPress server direct

Use when Ollama/LM Studio/llama.cpp is reachable from the PHP/WordPress host itself. In this mode `127.0.0.1` means the WordPress server.

## Settings

The administrator can configure:

- enable/disable Local AI;
- provider and connection mode;
- private endpoint and optional token;
- analysis/planning model;
- optional vision model;
- temperature, context window and maximum output tokens;
- minimum confidence;
- preview requirement;
- SAFE-only auto apply policy flag;
- parent/sibling context inclusion;
- local canvas screenshot permission.

Sensitive-context redaction is forced on and cannot be disabled in 0.7.

## Diagnostics

The admin panel can test the active route, discover installed models and verify the selected analysis model. Browser mode performs its connectivity/model checks in the browser; server-direct mode runs through the WordPress HTTP client.

## Planning schema

Local AI output is constrained to `cresco-layer-local-ai-plan/v1`:

```json
{
  "schema": "cresco-layer-local-ai-plan/v1",
  "intent": "improve-card-spacing",
  "confidence": 0.94,
  "summary": "Increase card breathing room.",
  "requestedSkills": [
    {
      "skillId": "control.padding",
      "params": { "device": "mobile", "value": "20px" },
      "reason": "Improve mobile spacing"
    }
  ],
  "questions": []
}
```

Every `skillId` must already exist in the selected Elementor element's compiled Cresco skill registry. Validation fails closed for unknown skills.

## Current scope

0.7 provides configuration, provider abstraction, model discovery, browser/server connection modes, diagnostics and the strict planning contract. The next integration layer can feed the selected widget Context Graph to the configured model and pass validated plans into the existing Skill Runtime without changing this trust boundary.
