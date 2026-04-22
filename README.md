# REDCapAgentRecordTools

A REDCap External Module that exposes **project and record operations as callable agent tools** for the [SecureChatAI](https://github.com/susom/secureChatAI) orchestration system.

The module provides **atomic, auditable operations** invoked by an LLM — it is a pure data layer with no UI, no orchestration logic, and no LLM calls of its own.

---

## Table of Contents

- [How It Works](#how-it-works)
- [Installation & Setup](#installation--setup)
- [Tool Reference](#tool-reference)
  - [projects.search](#projectssearch)
  - [projects.getMetadata](#projectsgetmetadata)
  - [projects.getInstruments](#projectsgetinstruments)
  - [records.get](#recordsget)
  - [records.search](#recordssearch)
  - [records.evaluateLogic](#recordsevaluatelogic)
  - [records.save](#recordssave)
  - [survey.getLink](#surveygetlink)
- [Example Agent Workflows](#example-agent-workflows)
- [How to Build Your Own Tool EM](#how-to-build-your-own-tool-em)
  - [Step 1: Create config.json](#step-1-create-configjson)
  - [Step 2: Implement the PHP Method](#step-2-implement-the-php-method)
  - [Step 3: Wire It Up in the Router](#step-3-wire-it-up-in-the-router)
  - [Step 4: Test It](#step-4-test-it)
  - [Full Walkthrough: Adding a New Tool to This Module](#full-walkthrough-adding-a-new-tool-to-this-module)
- [Architecture & Design Principles](#architecture--design-principles)
- [Security Model](#security-model)
- [Supported REDCap Features](#supported-redcap-features)

---

## How It Works

```
User → Cappy (or other UX) → SecureChatAI (Agent Orchestrator) → THIS MODULE → REDCap
```

1. User asks something in natural language ("What's my intake status for the Cancer study?")
2. The calling EM (Cappy, MSPA, or any EM) calls `$secureChatAI->callAI()` with `agent_mode => true`
3. SecureChatAI's LLM decides which tool(s) to call and with what parameters
4. SecureChatAI invokes this module's `redcap_module_api()` internally (no API token needed)
5. This module executes the corresponding REDCap operation and returns structured JSON
6. The LLM uses the result to compose a human-readable response

**The key design insight:** The agent doesn't need to know project structures in advance. It discovers them dynamically using `projects.search` → `projects.getMetadata` → then operates on whatever it finds. This makes the same 8 tools work across **any** REDCap project.

---

## Installation & Setup

1. Place this module in your REDCap `modules/` directory (or `modules-local/` for development)
2. Enable the module **system-wide** in REDCap's External Module Manager (project-level enablement is not required — SecureChatAI only needs the EM enabled at the system level to call it)
3. In **SecureChatAI** settings, add this module's prefix to **Agent Tool EM Prefixes**:
   - System-wide: `agent_tool_em_prefixes` setting
   - Or per-project: `project_agent_tool_em_prefixes` setting (overrides system)

That's it. SecureChatAI auto-discovers the tools from this module's config.json and invokes them via direct PHP calls (EM-to-EM). No API token, no HTTP requests, no network — just one EM calling another's `redcap_module_api()` method in the same process.

### Calling from another EM

Any EM can trigger an agent workflow by calling SecureChatAI directly:

```php
$secureChatAI = \ExternalModules\ExternalModules::getModuleInstance('secure_chat_ai');
$response = $secureChatAI->callAI('gpt-4.1', [
    'messages' => [
        ['role' => 'system', 'content' => 'Your system prompt here'],
        ['role' => 'user',   'content' => $userInput],
    ],
    'agent_mode' => true,
], $project_id);
```

SecureChatAI handles tool discovery, LLM routing, and the agent loop. Your EM just sends the prompt.

### External access (optional)

If you need to call tools from **outside** REDCap (external services, curl, testing), you can use the REDCap API with an API token:

```bash
curl -X POST https://your-redcap-instance/api/ \
  -d "token=YOUR_API_TOKEN" \
  -d "content=externalModule" \
  -d "prefix=redcap_agent_record_tools" \
  -d "action=projects_search" \
  -d 'payload={"query":"test"}'
```

This requires an API token for a project where the EM is enabled. For normal agent workflows within REDCap, this is unnecessary.

---

## Tool Reference

Every tool is called via the REDCap External Module API. The request always looks like:

```
POST /api/
  token=<API_TOKEN>
  content=externalModule
  prefix=redcap_agent_record_tools
  action=<ACTION_NAME>
  payload=<JSON_STRING>
```

All tools return JSON. On success, you get the result object. On failure, you get:
```json
{"error": true, "message": "What went wrong"}
```

### projects.search

**Action:** `projects_search`

Search for REDCap projects by name, description, or PID. Uses fuzzy matching.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `query` | string | ✅ | Search term — name, description, or numeric PID |
| `limit` | integer | | Max results (default: 10) |

```json
// Request
{"query": "Cancer study", "limit": 5}

// Response
{
  "query": "Cancer study",
  "project_count": 2,
  "projects": [
    {"pid": 42, "title": "Oncology Patient Intake", "purpose": null, "creation_time": "2025-06-18 10:40:29"},
    {"pid": 55, "title": "Cancer Registry", "purpose": null, "creation_time": "2024-03-12 08:15:00"}
  ]
}
```

---

### projects.getMetadata

**Action:** `projects_getMetadata`

Get the data dictionary (field definitions) for a project — field names, types, labels, validation rules, branching logic.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `pid` | integer | ✅ | REDCap project ID |
| `fields` | string[] | | Specific fields only (omit for all) |

```json
// Request
{"pid": 42, "fields": ["age", "consent_date"]}

// Response
{
  "pid": 42,
  "field_count": 2,
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

### projects.getInstruments

**Action:** `projects_getInstruments`

List all instruments (forms/surveys) in a project.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `pid` | integer | ✅ | REDCap project ID |

```json
// Request
{"pid": 42}

// Response
{
  "pid": 42,
  "instrument_count": 6,
  "instruments": [
    {"instrument_name": "demographics", "instrument_label": "Demographics"},
    {"instrument_name": "consent", "instrument_label": "Consent Form"}
  ]
}
```

---

### records.get

**Action:** `records_get`

Retrieve a specific record by its record ID.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `pid` | integer | ✅ | REDCap project ID |
| `record_id` | string | ✅ | The record ID to retrieve |
| `fields` | string[] | | Specific fields only |
| `events` | string[] | | Event names (longitudinal projects only) |

```json
// Request
{"pid": 42, "record_id": "1001", "fields": ["age", "consent_complete"]}

// Response
{
  "pid": 42,
  "record_id": "1001",
  "data": {
    "1001": {"age": "34", "consent_complete": "2"}
  }
}
```

---

### records.search

**Action:** `records_search`

Search records with an optional REDCap logic filter expression.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `pid` | integer | ✅ | REDCap project ID |
| `filter` | string | | REDCap logic expression, e.g. `[age] > 18` |
| `fields` | string[] | | Fields to include in results |
| `return_format` | string | | `"array"` (default) or `"json"` |

```json
// Request
{"pid": 42, "filter": "[baseline_complete] = '2' AND [age] >= 18"}

// Response
{
  "pid": 42,
  "filter": "[baseline_complete] = '2' AND [age] >= 18",
  "record_count": 23,
  "records": {
    "1001": {"age": "34", "baseline_complete": "2"},
    "1002": {"age": "28", "baseline_complete": "2"}
  }
}
```

---

### records.evaluateLogic

**Action:** `records_evaluateLogic`

Evaluate a REDCap logic expression against a specific record. Returns `true`/`false`.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `pid` | integer | ✅ | REDCap project ID |
| `record_id` | string | ✅ | Record to evaluate against |
| `logic` | string | ✅ | REDCap logic expression |
| `event` | string | | Event name (longitudinal projects only) |

```json
// Request
{"pid": 42, "record_id": "1001", "logic": "[consent_complete] = '2' AND [age] >= 18"}

// Response
{
  "pid": 42,
  "record_id": "1001",
  "logic": "[consent_complete] = '2' AND [age] >= 18",
  "result": true,
  "raw_result": 1
}
```

---

### records.save

**Action:** `records_save`

Create or update record data.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `pid` | integer | ✅ | REDCap project ID |
| `data` | object | ✅ | Field-value pairs (must include record ID field) |
| `overwrite` | boolean | | `true` = overwrite existing values; `false` (default) = only write to empty fields |

```json
// Request — simple update
{
  "pid": 42,
  "data": {"record_id": "1001", "consent_date": "2026-01-22", "enrollment_complete": "2"},
  "overwrite": true
}

// Request — repeating instrument
{
  "pid": 63,
  "data": {
    "record_id": "irvins",
    "redcap_repeat_instrument": "user_info",
    "redcap_repeat_instance": 7,
    "intake_id": "42"
  },
  "overwrite": false
}

// Response (success)
{
  "pid": 42,
  "success": true,
  "records_saved": 1,
  "record_ids": ["1001"],
  "warnings": [],
  "overwrite_mode": true
}

// Response (error)
{
  "error": true,
  "message": "Failed to save data",
  "errors": ["Field 'age' has invalid value 'abc' (must be integer)"],
  "warnings": [],
  "data_submitted": [{"record_id": "1001", "age": "abc"}]
}
```

---

### survey.getLink

**Action:** `survey_getLink`

Generate a survey URL. The instrument must be survey-enabled in the project.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `pid` | integer | ✅ | REDCap project ID |
| `record_id` | string | ✅ | Record ID |
| `instrument` | string | ✅ | Instrument machine name |
| `event` | string | | Event name (longitudinal projects only) |
| `instance` | integer | | Repeating instrument instance (default: 1) |

```json
// Request
{"pid": 42, "record_id": "1001", "instrument": "consent"}

// Response
{
  "pid": 42,
  "record_id": "1001",
  "instrument": "consent",
  "survey_url": "https://redcap.example.edu/surveys/?s=ABC123XYZ"
}
```

---

## Example Agent Workflows

### Multi-Step Intake Progress

> **User:** "For the PTSD study intake, what's my next step?"
>
> 1. `projects.search(query="PTSD study")` → Find project (PID 42)
> 2. `projects.getInstruments(pid=42)` → Get list of intake forms
> 3. `records.get(pid=42, record_id="1001")` → Check `*_complete` fields
> 4. `survey.getLink(pid=42, record_id="1001", instrument="contact_info")` → Generate next survey URL
>
> **Agent:** "You've completed 4/6 intake forms. Next up: Contact Information. [Here's your survey link]"

### Cohort Query

> **User:** "How many participants completed baseline but not 6-month followup?"
>
> 1. `projects.getMetadata(pid=42)` → Understand field structure
> 2. `records.search(pid=42, filter="[baseline_complete] = '2' AND [followup_6m_complete] <> '2'")` → Query
>
> **Agent:** "23 participants have completed baseline but not 6-month followup."

### Data Update

> **User:** "Mark my consent form as complete"
>
> 1. `projects.search(query="intake")` → Find project
> 2. `projects.getMetadata(pid=30)` → Learn field names
> 3. `records.save(pid=30, data={record_id:"irvins", consent_complete:"2"}, overwrite=true)` → Update
>
> **Agent:** "Done! Your consent form is marked complete."

---

## How to Build Your Own Tool EM

This section explains how to create a new External Module that exposes tools to SecureChatAI, **or** how to add more tools to this module. The config.json structure is the tricky part, so we'll go through it carefully.

> **Starter template available:** See [`redcap_agent_template`](https://github.com/susom/REDCapAgentTemplate) for a minimal, copy-and-go template EM with all the boilerplate already wired up.

### Auto-Discovery via Naming Convention

SecureChatAI discovers tool EMs by **prefix matching**. Any enabled EM whose prefix starts with `redcap_agent_` is scanned for `agent-tool-definitions` in its config.json.

**To make your tool EM auto-discoverable:**
1. Name your module directory `redcap_agent_<something>_v9.9.9`
2. Include `agent-tool-definitions` in your config.json
3. Enable the EM in REDCap

SecureChatAI's EM settings control which prefix to scan (e.g., `redcap_agent_`). This gives you plug-and-play discovery with a namespace gate — new tool EMs are picked up automatically, but only if they follow the naming convention.

### The Big Picture

A tool EM has three pieces that must stay in sync:

```
config.json                         PHP Class
┌─────────────────────┐            ┌──────────────────────┐
│ "api-actions": {    │ ───maps──→ │ redcap_module_api()  │
│   "my_action": {}   │            │   case "my_action":  │
│ }                   │            │     return toolX()   │
│                     │            └──────────────────────┘
│ "agent-tool-        │
│  definitions": [{   │ ───tells LLM──→ what tools exist
│   "name": "x.y",   │                  and how to call them
│   "api-action":     │
│     "my_action"     │
│ }]                  │
└─────────────────────┘
```

- **`api-actions`** — Registers URL endpoints with REDCap's EM framework. This is what makes `/api/?action=my_action` work.
- **`agent-tool-definitions`** — Describes each tool in JSON Schema format so the LLM knows how to call it. The `api-action` field links back to the corresponding `api-actions` entry.
- **PHP switch case** — Routes the action string to the method that does the work.

### Step 1: Create config.json

The minimum config for a tool EM:

```json
{
  "name": "MyAgentTools",
  "namespace": "Stanford\\MyAgentTools",
  "description": "Agent tools for doing X",
  "framework-version": 14,

  "api-actions": {
    "my_action_name": {
      "description": "What this endpoint does",
      "access": ["auth"]
    }
  },

  "agent-tool-definitions": [
    {
      "name": "category.toolName",
      "description": "A clear description the LLM will read to decide when to use this tool",
      "api-action": "my_action_name",
      "parameters": {
        "type": "object",
        "properties": {
          "param1": {
            "type": "string",
            "description": "What this parameter is for"
          },
          "param2": {
            "type": "integer",
            "description": "Optional numeric param",
            "default": 10
          }
        },
        "required": ["param1"]
      },
      "readOnly": true,
      "destructive": false
    }
  ]
}
```

#### `api-actions` — explained

```json
"api-actions": {
  "my_action_name": {         // ← This string is passed as $action to redcap_module_api()
    "description": "...",     // ← Human-readable, for documentation
    "access": ["auth"]        // ← Requires API authentication
  }
}
```

- The **key** (`my_action_name`) becomes the `action` parameter in API calls
- Convention: use `snake_case` with a category prefix — `records_get`, `projects_search`, `files_upload`
- Always use `"access": ["auth"]` so calls require a valid API token

#### `agent-tool-definitions` — explained

This is the part the **LLM reads** to decide what tools are available and how to call them. Each entry follows JSON Schema:

```json
{
  "name": "records.get",              // LLM-facing name (dot notation)
  "description": "...",               // LLM reads this to decide when to use the tool
  "api-action": "records_get",        // Must match a key in api-actions
  "parameters": {
    "type": "object",
    "properties": {
      "pid": {
        "type": "integer",            // JSON Schema types: string, integer, number, boolean, array, object
        "description": "..."          // LLM reads this to know what value to pass
      },
      "fields": {
        "type": "array",
        "items": {"type": "string"},  // Array of strings
        "description": "..."
      }
    },
    "required": ["pid"]               // Which params the LLM must always provide
  },
  "readOnly": true,                   // Hint: this tool only reads data
  "destructive": false                // Hint: this tool won't delete anything
}
```

**Tips for good tool definitions:**
- The `description` is the most important field — a vague description means the LLM won't know when to pick the tool
- Include examples in descriptions when the format isn't obvious (e.g., REDCap logic syntax)
- Mark `readOnly: false` and `destructive: true` for tools that modify data (like `records.save`)
- Keep parameter names simple and consistent across tools (`pid` everywhere, not sometimes `project_id`)

### Step 2: Implement the PHP Method

Each tool method follows the same pattern:

```php
public function toolMyAction(array $payload)
{
    // 1. Validate required parameters
    if (empty($payload['param1'])) {
        return [
            "error" => true,
            "message" => "Missing required parameter: param1"
        ];
    }

    // 2. Extract and type-cast parameters
    $param1 = $payload['param1'];
    $param2 = (int)($payload['param2'] ?? 10);

    try {
        // 3. Call REDCap API or do your work
        $result = \REDCap::someMethod($param1, $param2);

        // 4. Return structured result
        return [
            "param1" => $param1,
            "result_count" => count($result),
            "results" => $result
        ];
    } catch (\Exception $e) {
        // 5. Log and return error
        $this->emError("myAction error: " . $e->getMessage());
        return [
            "error" => true,
            "message" => "Failed to do the thing: " . $e->getMessage()
        ];
    }
}
```

**Rules:**
- Never throw exceptions past the method boundary — always return an error array
- Always validate required params before doing work
- Always wrap the core logic in try-catch
- Return `"error" => true` on failure (this triggers HTTP 400 in `wrapResponse`)
- Log errors with `$this->emError()` and debug info with `$this->emDebug()`

### Step 3: Wire It Up in the Router

Add a case to the switch in `redcap_module_api()`:

```php
case "my_action_name":
    return $this->wrapResponse(
        $this->toolMyAction($payload)
    );
```

The action string must match the key in `api-actions` in config.json.

### Step 4: Test It

```bash
curl -X POST https://your-redcap/api/ \
  -d "token=YOUR_TOKEN" \
  -d "content=externalModule" \
  -d "prefix=your_module_prefix" \
  -d "action=my_action_name" \
  -d 'payload={"param1":"test"}'
```

There's also a built-in debug endpoint:
```bash
curl -X POST https://your-redcap/api/ \
  -d "token=YOUR_TOKEN" \
  -d "content=externalModule" \
  -d "prefix=redcap_agent_record_tools" \
  -d "action=debug" \
  -d 'payload={"anything":"here"}'
```

This echoes back the parsed action and payload — useful for verifying your request is structured correctly.

### Full Walkthrough: Adding a New Tool to This Module

Let's say you want to add a `records.delete` tool. Here's every change:

**1. Add to `api-actions` in config.json:**
```json
"records_delete": {
    "description": "Agent tool: delete a record by record ID",
    "access": ["auth"]
}
```

**2. Add to `agent-tool-definitions` in config.json:**
```json
{
    "name": "records.delete",
    "description": "Permanently delete a record from a REDCap project. This cannot be undone.",
    "api-action": "records_delete",
    "parameters": {
        "type": "object",
        "properties": {
            "pid": {"type": "integer", "description": "REDCap project ID"},
            "record_id": {"type": "string", "description": "The record ID to delete"}
        },
        "required": ["pid", "record_id"]
    },
    "readOnly": false,
    "destructive": true
}
```

**3. Add the switch case in `redcap_module_api()`:**
```php
case "records_delete":
    return $this->wrapResponse(
        $this->toolDeleteRecord($payload)
    );
```

**4. Implement the method:**
```php
public function toolDeleteRecord(array $payload)
{
    if (empty($payload['pid'])) {
        return ["error" => true, "message" => "Missing required parameter: pid"];
    }
    if (empty($payload['record_id'])) {
        return ["error" => true, "message" => "Missing required parameter: record_id"];
    }

    $pid = (int)$payload['pid'];
    $record_id = $payload['record_id'];

    try {
        // Your delete logic here
        $result = \Records::deleteRecord($record_id, ..., $pid);

        return [
            "pid" => $pid,
            "record_id" => $record_id,
            "deleted" => true
        ];
    } catch (\Exception $e) {
        $this->emError("deleteRecord error for pid $pid: " . $e->getMessage());
        return ["error" => true, "message" => "Failed to delete record: " . $e->getMessage()];
    }
}
```

---

## Architecture & Design Principles

| Component | Role |
|-----------|------|
| **Cappy / Other UX** | User interface (chatbot, data entry form, etc.) |
| **SecureChatAI EM** | Agent routing & LLM orchestration |
| **REDCapAgentRecordTools** | Atomic record/project operations (this module) |
| **LLM** | Planner, not executor |

**Design rules this module follows:**
- Tools are **atomic** and single-purpose
- No orchestration logic (SecureChatAI's job)
- No LLM calls (this is a data layer)
- No UI (purely API-driven)
- Deterministic behavior only
- Every tool call is independently auditable

---

## Security Model

### Current State

- Tools are invoked by SecureChatAI via direct PHP calls (EM-to-EM, same process — no HTTP, no API token needed)
- External access is possible via the REDCap API with an API token, but not required for normal agent workflows
- The module never accepts free-form prompts
- All operations use REDCap's native methods (`getData`, `saveData`, etc.)
- `projects.search` currently returns all matching projects with no user-level filtering

### Planned (Phase 2): Governance Layer

A Tools Registry Project will provide:
- Project-level permission allowlists/denylists
- User-level access control
- Rate limiting
- Full audit logging of all tool invocations

---

## Supported REDCap Features

| Feature | Status |
|---------|--------|
| Classic projects | ✅ Full support |
| Longitudinal projects | ✅ Via optional `events` / `event` parameters |
| Repeating instruments | ✅ Full support |
| Repeating events | ✅ Via getData/saveData |
| File upload fields | ⚠️ Can detect presence, cannot verify file validity |
| Calculated fields | ⚠️ Read-only (cannot force recalculation) |
| Branching logic | ⚠️ Returned in metadata, not enforced on save |

---

## Related Modules

- [`secure_chat_ai`](https://github.com/susom/secureChatAI) — Agent orchestration and LLM routing
- `redcap_rag` — Document ingestion and retrieval
- `redcap-em-chatbot` (Cappy) — Conversational UI

