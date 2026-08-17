# Cresco Local AI Manager

Cresco Layer 0.7 giới thiệu Local AI Manager dành cho administrator trong Cresco admin.

## Ranh giới sản phẩm

Local AI là **lớp phân tích và lập kế hoạch**, không phải Elementor executor.

```text
User request
  -> deterministic router khi có thể
  -> local AI analysis cho task mơ hồ/phức tạp
  -> cresco-layer-local-ai-plan/v1
  -> validate exact skill IDs với selected runtime skill registry
  -> preview/risk policy
  -> Cresco Skill Runtime
  -> Elementor
```

Model không được:

- invent Elementor setting key;
- dùng arbitrary CSS thay native control;
- ghi document trực tiếp;
- execute JavaScript;
- thoát selected scope;
- bypass Cresco validation.

## Provider hỗ trợ

Manager có adapter cho:

- Ollama — `http://127.0.0.1:11434`, `/api/version`, `/api/tags`, `/api/chat`.
- LM Studio OpenAI-compatible — `http://127.0.0.1:1234/v1`, `/models`, `/chat/completions`.
- llama.cpp server — `http://127.0.0.1:8080/v1`, `/models`, `/chat/completions`.
- custom OpenAI-compatible local API.

Endpoint policy chỉ chấp nhận local/private host như:

- localhost/loopback;
- RFC1918 private IPv4;
- `host.docker.internal`;
- `.local` host.

Mục tiêu là không biến Local AI module thành generic remote HTTP relay.

## Connection modes

### Browser / Local Bridge

Khuyến nghị khi WordPress nằm trên remote server nhưng model chạy trên máy người dùng.

```text
browser đang chạy Elementor/Cresco
→ local model endpoint trên máy user
```

WordPress server không cần truy cập máy local.

Saved API token **không được expose sang browser mode JavaScript**. Nếu endpoint cần Authorization header, dùng trusted local bridge không auth hoặc server-direct mode phù hợp.

### WordPress server direct

Dùng khi PHP/WordPress host truy cập được Ollama/LM Studio/llama.cpp.

Trong mode này:

```text
127.0.0.1 = WordPress server
```

không phải máy browser của user.

## Settings

Administrator có thể cấu hình:

- bật/tắt Local AI;
- provider + connection mode;
- private endpoint + optional token;
- analysis/planning model;
- optional vision model;
- temperature;
- context window;
- maximum output tokens;
- minimum confidence;
- preview requirement;
- SAFE-only auto-apply policy flag;
- parent/sibling context;
- local canvas screenshot permission.

Sensitive-context redaction là bắt buộc ở 0.7.

## Diagnostics

Admin panel có thể:

- test active route;
- discover installed models;
- verify selected analysis model.

Browser mode chạy connectivity/model checks trong browser; server-direct dùng WordPress HTTP client.

## Planning schema

Local AI output bị giới hạn bởi:

```text
cresco-layer-local-ai-plan/v1
```

Ví dụ:

```json
{
  "schema": "cresco-layer-local-ai-plan/v1",
  "intent": "improve-card-spacing",
  "confidence": 0.94,
  "summary": "Increase card breathing room.",
  "requestedSkills": [
    {
      "skillId": "control.padding",
      "params": {
        "device": "mobile",
        "value": "20px"
      },
      "reason": "Improve mobile spacing"
    }
  ],
  "questions": []
}
```

Mỗi `skillId` phải tồn tại trong compiled Cresco skill registry của selected Elementor element. Unknown skill → fail-closed.

## Scope của 0.7

0.7 tập trung vào:

- configuration;
- provider abstraction;
- model discovery;
- browser/server connection modes;
- diagnostics;
- strict planning contract.

Execution vẫn phải đi qua Skill Runtime/runtime validation. Local AI không được trở thành đường tắt bỏ qua trust boundary.

## Quan hệ với các phiên bản sau

Các phiên bản Local AI sau này bổ sung semantic context, evidence validation và task-aware skill retrieval. Tuy nhiên invariant từ 0.7 vẫn giữ:

```text
AI proposes
→ Cresco validates
→ deterministic runtime executes
→ Elementor persists/renders
```