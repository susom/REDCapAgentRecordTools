# Copilot Instructions — REDCapAgentRecordTools

## Architecture

This is a **REDCap External Module (EM)** that exposes 8 agent tools as API endpoints for the SecureChatAI orchestration system. It is a **data layer only** — no UI, no LLM calls, no orchestration logic.

**System context:**

- **Chatbot EM (Cappy)** → UI
- **SecureChatAI EM** → agent routing & orchestration
- **This EM** → atomic record/project operations (called by LLM via SecureChatAI)
- **LLM** → planner, not executor

All 8 tools are invoked through a single entry point: `redcap_module_api($action, $payload)`, which dispatches to `tool*` methods via a switch statement. Each tool method validates parameters, calls a REDCap static method (e.g., `\REDCap::getData()`, `\REDCap::saveData()`), and returns a structured array. The `wrapResponse()` helper converts this to a JSON HTTP response, setting status 400 when an `error` key is present.

## Key Conventions

- **Namespace:** `Stanford\REDCapAgentRecordTools`
- **Framework version:** 14 (REDCap EM Framework)
- **Auto-discovery:** SecureChatAI discovers tool EMs by prefix. Any enabled EM named `redcap_agent_*` with `agent-tool-definitions` in config.json is auto-discovered. This module follows that convention.
- **Tool registration:** Tools are declared in `config.json` under both `api-actions` (for REDCap routing) and `agent-tool-definitions` (for LLM tool schemas). Both must stay in sync when adding/modifying tools.
- **Action naming:** API actions use `snake_case` with category prefix (e.g., `records_save`, `projects_search`). Agent tool names use `dot.notation` (e.g., `records.save`, `projects.search`).
- **Error pattern:** Every tool returns `["error" => true, "message" => "..."]` on failure. Never throw exceptions past tool boundaries.
- **Logging:** Uses `emLoggerTrait` — call `$this->emDebug()` for debug, `$this->emError()` for errors. Requires the `em_logger` EM to be installed; gracefully degrades if absent.
- **Parameter validation:** Each tool validates required params at the top of the method and returns an error array (not exception) if missing.
- **`projects.search`** is the only tool that uses raw SQL (`db_query`) against `redcap_projects`. All other tools use REDCap static API methods.

## Adding a New Tool

1. Add the `api-actions` entry in `config.json` with action name and `"access": ["auth"]`
2. Add the `agent-tool-definitions` entry in `config.json` with full JSON Schema parameters
3. Add a `case` to the switch in `redcap_module_api()`
4. Implement the `tool*` method following the existing pattern: validate params → try/catch → return structured array

## Code Style

- 4 spaces indentation, UTF-8, LF line endings (see `.editorconfig`)
- PHP with no closing `?>` tag
- Tabs used in `config.json` for indentation
