# Copilot Instructions — REDCapAgentRecordTools

## Architecture

This is a **REDCap External Module (EM)** that exposes 8 agent tools for the SecureChatAI orchestration system. It is a **data layer only** — no UI, no LLM calls, no orchestration logic.

**System context:**

- **Chatbot EM (Cappy)** → UI
- **SecureChatAI EM** → agent routing & orchestration
- **This EM** → atomic record/project operations (called by LLM via SecureChatAI)
- **LLM** → planner, not executor

All 8 tools are invoked through a single entry point: `handleToolCall(string $action, array $payload): array`, which dispatches to `tool*` methods via a switch statement. In production, SecureChatAI calls this via EM-to-EM direct PHP (`getModuleInstance()->handleToolCall()`). There is no external HTTP surface. Each tool method validates parameters, calls a REDCap static method (e.g., `\REDCap::getData()`, `\REDCap::saveData()`), and returns a raw associative array.

## Key Conventions

- **Namespace:** `Stanford\REDCapAgentRecordTools`
- **Framework version:** 14 (REDCap EM Framework)
- **Auto-discovery:** SecureChatAI discovers tool EMs by matching their prefix against the **Agent Tool EM Prefixes** list (configurable in SecureChatAI settings). The `redcap_agent_*` prefix is a recommended convention, not a hard requirement. Any EM whose prefix is in the list and has a `tools.json` manifest is auto-discovered.
- **Tool registration:** Tools are declared in `tools.json` with JSON Schema definitions. The `action` field links each tool to its PHP switch case.
- **Action naming:** Actions use `snake_case` with category prefix (e.g., `records_save`, `projects_search`). LLM-facing tool names use `dot.notation` (e.g., `records.save`, `projects.search`).
- **Error pattern:** Every tool returns `["error" => true, "message" => "..."]` on failure. Never throw exceptions past tool boundaries.
- **Return format:** Raw PHP arrays — no HTTP wrapping. SecureChatAI handles serialization.
- **Logging:** Uses `emLoggerTrait` — call `$this->emDebug()` for debug, `$this->emError()` for errors. Requires the `em_logger` EM to be installed; gracefully degrades if absent.
- **Parameter validation:** Each tool validates required params at the top of the method and returns an error array (not exception) if missing.
- **`projects.search`** is the only tool that uses raw SQL (`db_query`) against `redcap_projects`. All other tools use REDCap static API methods.

## Adding a New Tool

1. Add tool definition to `tools.json` with JSON Schema params and `"action": "my_action"`
2. Add a `case` to the switch in `handleToolCall()`
3. Implement the `tool*` method following the existing pattern: validate params → try/catch → return structured array

## Code Style

- 4 spaces indentation, UTF-8, LF line endings (see `.editorconfig`)
- PHP with no closing `?>` tag
- Tabs used in `config.json` and `tools.json` for indentation
