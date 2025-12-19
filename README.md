# REDCapAgentRecordTools

REDCapAgentRecordTools is a lightweight External Module that exposes
**record-related actions as callable agent tools** for the SecureChatAI
agent orchestration system.

It exists solely to provide **safe, auditable, permission-controlled
operations** that can be invoked by an LLM via SecureChatAI.

---

## Purpose

This module enables agentic workflows such as:

- Resolving record IDs from human-readable identifiers
- Fetching structured record metadata
- Supporting multi-step AI-driven tasks without embedding logic in the UI

Example agent request:

> “Get Michael Hallas’s hypertension treatment records for the past 6 months.”

The agent may call:

```
records.getUserIdByFullName
```


Which maps to this module.

---

## Architecture Role

| Component | Responsibility |
|---------|----------------|
| Chatbot EM (Cappy) | UI only |
| SecureChatAI EM | Agent routing & orchestration |
| **REDCapAgentRecordTools** | Atomic record operations |
| LLM | Planner, not executor |

---

## Exposed Tools

### records.getUserIdByFullName

**Description**  
Resolve a REDCap record ID from a full name.

**Parameters**
```json
{
  "full_name": "string"
}
```
Returns
```
{
  "record_id": "string"
}
```

## Security Model

- Tools are callable **only** via authenticated REDCap API requests
- Permissions are enforced **upstream by SecureChatAI**
- This module never accepts free-form prompts

## Design Principles

- Tools are **atomic**
- No orchestration
- No LLM calls
- No UI
- Deterministic behavior only

This ensures:

- Easy auditing
- Clear separation of concerns
- Safe expansion of agent capabilities

