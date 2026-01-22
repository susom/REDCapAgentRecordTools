# REDCapAgentRecordTools

REDCapAgentRecordTools is a lightweight External Module that exposes
**project and record operations as callable agent tools** for the SecureChatAI
agent orchestration system.

It exists solely to provide **atomic, auditable operations** that can be invoked
by an LLM via SecureChatAI, enabling conversational CRUD workflows that
**dynamically adapt to any REDCap project structure**.

---

## Purpose

This module enables agentic workflows such as:

- **Project Discovery**: Finding projects by fuzzy name search
- **Dynamic Introspection**: Learning any project's field structure at runtime
- **Flexible Querying**: Searching and filtering records with REDCap logic
- **Status Checking**: Evaluating completion, eligibility, and complex conditions
- **Data Operations**: Creating and updating records conversationally
- **Workflow Automation**: Generating survey links for multi-step processes

### Key Insight: Project-Agnostic Design

The agent **does not need to know project structures in advance**. It discovers them dynamically:

1. User: "Check my intake status for the Cancer study"
2. Agent: `projects.search("Cancer")` → Finds "Oncology Patient Intake" (PID 42)
3. Agent: `projects.getMetadata(pid=42)` → Learns the project has fields like `consent_date`, `enrollment_complete`
4. Agent: `records.get(pid=42, record_id="user_123")` → Checks user's data
5. Agent: Responds intelligently based on discovered structure

**This works for ANY REDCap project** - research studies, clinical registries, administrative workflows, etc.

---

## Example Agent Workflows

### Workflow 1: Multi-Step Intake Progress

> **User:** "For the PTSD study intake, what's my next step?"
>
> **Agent:**
> 1. `projects.search(query="PTSD study")` → Find project (PID 42)
> 2. `projects.getInstruments(pid=42)` → Get list of intake forms
> 3. `records.get(pid=42, record_id="1001")` → Check `*_complete` fields
> 4. `survey.getLink(pid=42, record_id="1001", instrument="contact_info")` → Generate next survey URL
>
> **Response:** "You've completed 4/6 intake forms (demographics, medical history, consent, baseline assessment). Next up: Contact Information. [Here's your survey link]"

### Workflow 2: Cohort Queries

> **User:** "How many participants completed baseline but not 6-month followup?"
>
> **Agent:**
> 1. `projects.getMetadata(pid=42)` → Understand field structure
> 2. `records.search(pid=42, filter="[baseline_complete] = '2' AND [followup_6m_complete] <> '2'")` → Query matching records
>
> **Response:** "23 participants have completed baseline but not 6-month followup."

### Workflow 3: Data Updates

> **User:** "Mark my consent form as complete"
>
> **Agent:**
> 1. `projects.search(query="intake")` → Find intake project
> 2. `projects.getMetadata(pid=30)` → Learn field names
> 3. `records.get(pid=30, record_id="irvins")` → Get current status
> 4. `records.save(pid=30, data={record_id:"irvins", consent_complete:"2"}, overwrite=true)` → Update
>
> **Response:** "Done! Your consent form is marked complete."

### Workflow 4: Complex Status Analysis

> **User:** "Tell me about intake #24 in the Rover project"
>
> **Agent:**
> 1. `projects.search(query="Rover")` → Find "Rover Intake" (PID 63)
> 2. `projects.getMetadata(pid=63)` → Learn 273 fields, 147 required, 65 file uploads
> 3. `records.get(pid=63, record_id="irvins")` → Retrieve record with repeating instruments
> 4. Analyze instance 5 (intake_id=24)
>
> **Response:** "Intake #24 is marked complete, but all required fields are empty and no files uploaded. This suggests validation may be disabled or it's a placeholder record."

---

## Quick Reference: All 8 Tools

| Tool | Purpose | Key Use Case |
|------|---------|--------------|
| **projects.search** | Find projects by name | User: "Check the Cancer study" |
| **projects.getMetadata** | Learn field structure | Discover what fields exist before querying |
| **projects.getInstruments** | List forms/surveys | "What forms are in this project?" |
| **records.get** | Retrieve specific record | Get all data for record "1001" |
| **records.search** | Query records with filters | Find all participants where `[age] > 18` |
| **records.evaluateLogic** | Check eligibility/status | Is record "1001" eligible? (true/false) |
| **records.save** | Create/update records | Mark consent form complete |
| **survey.getLink** | Generate survey URLs | "Send me the intake survey link" |

---

## Architecture Role

| Component | Responsibility |
|---------|----------------|
| Chatbot EM (Cappy) | UI only |
| SecureChatAI EM | Agent routing & orchestration |
| **REDCapAgentRecordTools** | Atomic record operations |
| LLM | Planner, not executor |

---

## Exposed Tools (8 Total)

### Project Discovery

#### projects.search

**Description**
Search for REDCap projects by name, description, or PID. Uses fuzzy matching to find projects across the entire REDCap instance. Returns all projects (no user filtering) - governance layer to be added later.

**Parameters**
```json
{
  "query": "Cancer study",
  "limit": 10  // optional: max results (default: 10)
}
```

**Returns**
```json
{
  "query": "Cancer study",
  "project_count": 2,
  "projects": [
    {
      "pid": 42,
      "title": "Oncology Patient Intake",
      "purpose": null,
      "creation_time": "2025-06-18 10:40:29"
    },
    {
      "pid": 55,
      "title": "Cancer Registry",
      "purpose": null,
      "creation_time": "2024-03-12 08:15:00"
    }
  ]
}
```

**Use Cases:**
- User mentions a project by name (e.g., "the PTSD study")
- Agent needs to discover which PID to operate on
- Searching by numeric PID (e.g., "project 63")

---

### Project Introspection

#### projects.getMetadata

**Description**
Get the data dictionary (field definitions) for a REDCap project. Returns field names, types, labels, validation rules, and branching logic.

**Parameters**
```json
{
  "pid": 42,
  "fields": ["age", "consent"] // optional: specific fields only
}
```

**Returns**
```json
{
  "pid": 42,
  "field_count": 45,
  "fields": [
    {
      "field_name": "age",
      "form_name": "demographics",
      "field_type": "text",
      "field_label": "Age",
      "text_validation_type_or_show_slider_number": "integer",
      "required_field": "y",
      "branching_logic": null
    }
  ]
}
```

---

#### projects.getInstruments

**Description**
List all instruments (forms/surveys) in a REDCap project.

**Parameters**
```json
{
  "pid": 42
}
```

**Returns**
```json
{
  "pid": 42,
  "instrument_count": 6,
  "instruments": [
    {
      "instrument_name": "demographics",
      "instrument_label": "Demographics"
    },
    {
      "instrument_name": "consent",
      "instrument_label": "Consent Form"
    }
  ]
}
```

---

### Record Operations (Read)

#### records.get

**Description**
Retrieve a specific record's data by record ID.

**Parameters**
```json
{
  "pid": 42,
  "record_id": "1001",
  "fields": ["age", "consent_complete"], // optional
  "events": ["baseline_arm_1"] // optional (for longitudinal projects)
}
```

**Returns**
```json
{
  "pid": 42,
  "record_id": "1001",
  "data": {
    "1001": {
      "age": "34",
      "consent_complete": "2"
    }
  }
}
```

---

#### records.search

**Description**
Search for records matching criteria. Use REDCap logic syntax for filters (e.g., `"[age] > 18 AND [consent] = '1'"`).

**Parameters**
```json
{
  "pid": 42,
  "filter": "[baseline_complete] = '2' AND [age] >= 18",
  "fields": ["record_id", "age", "baseline_complete"] // optional
}
```

**Returns**
```json
{
  "pid": 42,
  "filter": "[baseline_complete] = '2' AND [age] >= 18",
  "record_count": 23,
  "records": {
    "1001": { "age": "34", "baseline_complete": "2" },
    "1002": { "age": "28", "baseline_complete": "2" }
  }
}
```

---

#### records.evaluateLogic

**Description**
Evaluate a REDCap logic expression for a specific record. Useful for checking eligibility, completion status, or complex conditions. Returns true/false.

**Parameters**
```json
{
  "pid": 42,
  "record_id": "1001",
  "logic": "[consent_complete] = '2' AND [age] >= 18",
  "event": "baseline_arm_1" // optional (for longitudinal projects)
}
```

**Returns**
```json
{
  "pid": 42,
  "record_id": "1001",
  "logic": "[consent_complete] = '2' AND [age] >= 18",
  "result": true,
  "raw_result": 1
}
```

---

### Survey Workflows

#### survey.getLink

**Description**
Generate a survey URL for a specific instrument and record. The instrument must be enabled as a survey.

**Parameters**
```json
{
  "pid": 42,
  "record_id": "1001",
  "instrument": "consent",
  "event": "baseline_arm_1", // optional (for longitudinal projects)
  "instance": 1 // optional (for repeating instruments)
}
```

**Returns**
```json
{
  "pid": 42,
  "record_id": "1001",
  "instrument": "consent",
  "survey_url": "https://redcap.example.edu/surveys/?s=ABC123XYZ"
}
```

---

### Record Operations (Write)

#### records.save

**Description**
Create new records or update existing records. Uses REDCap's saveData API with validation. Supports single records or batch operations. Returns detailed error messages if validation fails.

**Parameters**
```json
{
  "pid": 42,
  "data": {
    "record_id": "1001",
    "consent_date": "2026-01-22",
    "enrollment_complete": "2"
  },
  "overwrite": true  // optional: true to update, false to create (default: false)
}
```

**For Repeating Instruments:**
```json
{
  "pid": 63,
  "data": {
    "record_id": "irvins",
    "redcap_repeat_instrument": "user_info",
    "redcap_repeat_instance": 7,
    "intake_id": "42",
    "research_title": "New Study",
    "user_info_complete": "2"
  },
  "overwrite": false
}
```

**Returns**
```json
{
  "pid": 42,
  "success": true,
  "records_saved": 1,
  "record_ids": ["1001"],
  "warnings": [],
  "overwrite_mode": true
}
```

**Error Response:**
```json
{
  "error": true,
  "message": "Failed to save data",
  "errors": [
    "Field 'age' has invalid value 'abc' (must be integer)"
  ],
  "warnings": [],
  "data_submitted": { /* echoed data */ }
}
```

**Use Cases:**
- Creating new participant records
- Updating form completion status
- Bulk data import from agent-processed sources
- Correcting data errors conversationally

---

## Security Model

### Current State (Phase 1: Raw Capability Layer)

- Tools are callable **only** via authenticated REDCap API requests
- API token grants access to **ALL projects** (no user-level filtering)
- This module never accepts free-form prompts
- All operations use REDCap's native methods (getData, saveData, etc.)
- **⚠️ IMPORTANT:** Currently in "unlimited power" mode - governance layer required for production

### Planned Governance (Phase 2)

A **Tools Registry Project** will provide data-driven permission control:

- **Project-Level Permissions**: Allowlist/denylist which tools are available per project
- **User-Level Permissions**: Control which users can access which projects
- **Rate Limiting**: Prevent abuse with configurable call limits
- **Audit Logging**: Track all tool usage (user, tool, target project, timestamp, success/failure)
- **Tool Auto-Registration**: EMs self-register on enable, no code changes needed

**Example Governance Schema:**
```
Tools Registry Project (PID 61)
├─ Tool Definitions (auto-populated)
│  └─ [tool_name, description, parameters, em_prefix, risk_level]
├─ Project Permissions (admin-configured)
│  └─ [target_pid, allowed_tools, denied_tools, rate_limit]
├─ User Permissions (admin-configured)
│  └─ [username, allowed_projects, access_level, expiration]
└─ Usage Audit Log (auto-logged)
   └─ [timestamp, username, tool, target_pid, success, error_msg]
```

---

## Design Principles

- Tools are **atomic** and single-purpose
- No orchestration (that's SecureChatAI's job)
- No LLM calls (this is a data layer, not AI)
- No UI (purely API-driven)
- Deterministic behavior only

This ensures:

- Easy auditing (every tool call is logged)
- Clear separation of concerns
- Safe expansion of agent capabilities
- Predictable behavior for agents

---

## Implementation Details

### REDCap Methods Used

| Tool | REDCap Method | Purpose |
|------|---------------|---------|
| `projects.search` | Direct DB query (`redcap_projects`) | Fuzzy search across project titles |
| `projects.getMetadata` | `REDCap::getDataDictionary()` | Field definitions and validation rules |
| `projects.getInstruments` | `REDCap::getInstrumentNames()` | Form/survey listing |
| `records.get` | `REDCap::getData()` | Single record retrieval |
| `records.search` | `REDCap::getData()` with filter param | Record queries with logic filtering |
| `records.evaluateLogic` | `REDCap::evaluateLogic()` | Boolean logic evaluation |
| `records.save` | `REDCap::saveData()` | Create/update records with validation |
| `survey.getLink` | `REDCap::getSurveyLink()` | Survey URL generation |

### Error Handling

All tools:
- Validate required parameters before execution
- Wrap operations in try-catch blocks
- Return structured JSON with `error: true` on failure
- Include descriptive error messages for debugging
- Log errors via emLoggerTrait

### Supported REDCap Features

✅ **Classic Projects** - Full support
✅ **Longitudinal Projects** - Events supported via optional parameters
✅ **Repeating Instruments** - Full support (instance detection automatic)
✅ **Repeating Events** - Supported via getData/saveData
✅ **File Upload Fields** - Can detect presence, cannot verify file validity
✅ **Calculated Fields** - Read-only (cannot force recalculation)
✅ **Branching Logic** - Returned in metadata, not enforced on save

### Performance Considerations

- `projects.search` queries the entire `redcap_projects` table (add indexes if needed)
- `projects.getMetadata` returns full data dictionary (can be 100KB+ for large projects)
- `records.search` with complex filters may be slow on large datasets
- No caching implemented (REDCap's native caching applies)

---

## Installation & Setup

1. **Enable the EM** on the "Tools Registry Project" (recommended) or any project
2. **Generate API Token** for the project
3. **Configure SecureChatAI** with the API token and tool registry JSON
4. **Test tools** via curl or SecureChatAI agent loop

**Smoke Test:**
```bash
curl -X POST http://localhost/api/ \
  -d "token=YOUR_API_TOKEN" \
  -d "content=externalModule" \
  -d "prefix=redcap_agent_record_tools" \
  -d "action=projects_search" \
  -d 'payload={"query":"test"}'
```

---

## Future Enhancements

- [ ] **Tool Auto-Registration**: EMs register themselves in Tools Registry Project on enable
- [ ] **Permission Enforcement**: Check user/project permissions before executing tools
- [ ] **Rate Limiting**: Configurable throttling per user/project
- [ ] **Audit Logging**: Write usage logs to Tools Registry Project
- [ ] **Batch Operations**: Support array of record operations in single call
- [ ] **Caching Layer**: Cache metadata/instrument lists for performance
- [ ] **Delete Operations**: `records.delete` with confirmation workflow
- [ ] **Advanced Search**: Full-text search across record data
- [ ] **Data Export**: `records.export` in CSV/JSON formats
- [ ] **File Operations**: Upload/download file attachments via tools

---

## Contributing

This EM is part of the **REDCap AI Ecosystem**. See main project documentation for architecture decisions and contribution guidelines.

**Related EMs:**
- `secure_chat_ai_v9.9.9` - Agent orchestration and LLM routing
- `redcap_rag_v9.9.9` - Document ingestion and retrieval
- `redcap-em-chatbot_v9.9.9` - Conversational UI (Cappy)

