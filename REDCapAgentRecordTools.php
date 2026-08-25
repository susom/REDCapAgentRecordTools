<?php
namespace Stanford\REDCapAgentRecordTools;

require_once "emLoggerTrait.php";
require_once "classes/PhiFieldPreHook.php";

class REDCapAgentRecordTools extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    // No hard cap on records.search results — REDCap itself doesn't cap, and
    // the full result set is always cached server-side regardless. This is
    // just the DEFAULT page size; past ~500 records the response notes steer
    // the agent toward suggesting the user narrow their filter or use
    // records.aggregate for "across the whole dataset" questions.
    const MAX_RECORDS_RETURNED = 500;

    // Byte budget for the one big list-or-map payload in a tool response
    // (getMetadata's 'fields', records.get's 'values').
    //
    // WHY WE SELF-LIMIT: SecureChatAI caps each tool result and its object
    // branch drops an oversized key WHOLESALE rather than shortening it — so an
    // unscoped getMetadata on any real project used to return the field COUNT
    // and nothing else. Trimming here instead means the caller gets a labelled
    // partial payload plus an accurate count of what was cut.
    //
    // COUPLED TO SecureChatAI's `agent_max_tool_result_chars` (default 8000).
    // This must stay comfortably below it: the difference covers our wrapper
    // keys and the ~700-char explanatory note. If that setting is raised (16k,
    // 24k) this can rise with it to return more per call; if it is lowered
    // below ~7000 this MUST come down too, or the outer cap starts nuking
    // payloads wholesale again — the exact failure this constant exists to
    // prevent. Not read directly: that would couple this EM to SecureChatAI's
    // settings, which the repo guardrails forbid without explicit instruction.
    const CAPPY_PAYLOAD_BUDGET = 6000;

    // Field labels can hold entire HTML blocks (descriptive fields). Capped in
    // the slim view only; a scoped call returns labels in full.
    const CAPPY_METADATA_LABEL_MAX = 120;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Tool Router — redcap_module_api()
     *
     * Standard REDCap hook — entry point for all tool calls.
     * Called by SecureChatAI via EM-to-EM:
     *   getModuleInstance($prefix)->redcap_module_api($action, $payload)
     * Also callable externally via the REDCap API:
     *   POST /api/ ... content=externalModule&prefix=...&action=...
     *
     * ⚠️  SECURITY — KNOWN CRITICAL ISSUE (deferred):
     * This endpoint performs ZERO authorization. Any REDCap API token
     * (or EM-to-EM call from a compromised context) can invoke any action
     * with any pid, regardless of whether the caller has rights to that
     * project. Today the risk surface is contained because:
     *   1. This EM is not exposed via the public REDCap API
     *      (auth-ajax-actions in config.json is empty), and
     *   2. The chatbot EM's CappyScopePreHook enforces pid scope inside
     *      SecureChatAI's agent loop.
     * Neither of these is a substitute for real per-pid authorization
     * inside routeToolCall. If either of those mitigations is removed
     * (auth-ajax-actions added, or SecureChatAI's hook registry edited),
     * any authenticated caller can read/write any project's data.
     * Fix tracked in CLAUDE.md "Security — known issues" section.
     */
    public function redcap_module_api($action = null, $payload = [])
    {
        // PHI-safe: log action name and payload shape only — never the payload
        // contents (which can contain record data, filters with patient info).
        $this->emDebug("Agent tool call", [
            'action' => $action,
            'payload_keys' => is_array($payload) ? array_keys($payload) : [],
            'has_filter' => !empty($payload['filter']),
        ]);

        $response = $this->routeToolCall($action, $payload);

        // PHI-safe: log response shape only. Full responses (which can contain
        // preview_markdown with record IDs, or raw records when include_records)
        // are never written to the log.
        $isError = !empty($response['error']);
        $logEntry = [
            'action' => $action,
            'response_keys' => is_array($response) ? array_keys($response) : [],
            'has_error' => $isError,
            'total_record_count' => $response['total_record_count'] ?? null,
            'value_count' => $response['value_count'] ?? null,
        ];
        // On failure, log WHY. Every error string in this file is a fixed,
        // developer-authored diagnostic ("Reference ref_x not found or expired",
        // "Missing required parameter: pid") — no record data, so it's safe here and
        // it's the difference between "a tool failed" and a usable answer. Without
        // it, has_error=1 was all we had, and diagnosing a failed turn meant
        // guessing which of a dozen error branches fired.
        if ($isError && isset($response['message'])) {
            $logEntry['error_message'] = (string)$response['message'];
        }
        $this->emDebug("Agent tool response", $logEntry);

        return $response;
    }

    private function routeToolCall($action, $payload)
    {
        switch ($action) {
            case "projects_getMetadata":
                return $this->toolGetMetadata($payload);

            case "projects_getInstruments":
                return $this->toolGetInstruments($payload);

            case "records_get":
                return $this->toolGetRecord($payload);

            case "records_search":
                return $this->toolSearchRecords($payload);

            case "records_count":
                return $this->toolCountRecords($payload);

            case "records_aggregate":
                return $this->toolAggregateRecords($payload);

            case "records_listIds":
                return $this->toolListRecordIds($payload);

            case "survey_getLink":
                return $this->toolGetSurveyLink($payload);

            case "records_evaluateLogic":
                return $this->toolEvaluateLogic($payload);

            case "projects_search":
                return $this->toolSearchProjects($payload);

            // WRITE DISABLED 2026-08-24: "records_save" intentionally has NO case here, so
            // it falls through to default ("Unknown action"). Guarantees no record writes
            // via Cappy pending real per-pid authorization (see redcap_module_api() docblock
            // above). Also pulled from tools.json and config.json api-actions; toolSaveRecords()
            // is a third, independent gate. Restore via git history, not by re-adding a case.

            default:
                return [
                    "error" => true,
                    "message" => "Unknown action: $action"
                ];
        }
    }

    /**
     * Tool 1: projects.getMetadata
     * Get data dictionary (field definitions) for a project
     */
    public function toolGetMetadata(array $payload)
    {
        if (empty($payload['pid'])) {
            return [
                "error" => true,
                "message" => "Missing required parameter: pid"
            ];
        }

        $pid = (int)$payload['pid'];
        $fields = $payload['fields'] ?? null; // Optional: specific fields only
        // Did the caller name the fields it wants? That's the path that can
        // afford the full per-field shape (choices, validation, branching).
        $scoped = is_array($fields) && !empty($fields);

        try {
            // Get full data dictionary
            $metadata = \REDCap::getDataDictionary($pid, 'array', false, $fields);

            if (empty($metadata)) {
                return [
                    "error" => true,
                    "message" => "No metadata found for project $pid (may not exist or no access)"
                ];
            }

            // Convert to array of field objects for easier agent consumption.
            // Unscoped calls get a SLIM shape: on a 189-field project the full
            // shape is ~55KB and the slim one is still ~36KB, so neither fits
            // the tool-result cap — but the slim rows at least degrade to a
            // useful partial list instead of being dropped en masse.
            $fields_array = [];
            foreach ($metadata as $field_name => $field_info) {
                $row = [
                    'field_name'  => $field_name,
                    'form_name'   => $field_info['form_name'] ?? null,
                    'field_type'  => $field_info['field_type'] ?? null,
                    'field_label' => $scoped
                        ? ($field_info['field_label'] ?? null)
                        : $this->cappyTruncate((string)($field_info['field_label'] ?? ''), self::CAPPY_METADATA_LABEL_MAX),
                ];
                if ($scoped) {
                    $row['select_choices_or_calculations'] = $field_info['select_choices_or_calculations'] ?? null;
                    $row['required_field'] = $field_info['required_field'] ?? null;
                    $row['text_validation_type_or_show_slider_number'] = $field_info['text_validation_type_or_show_slider_number'] ?? null;
                    $row['branching_logic'] = $field_info['branching_logic'] ?? null;
                }
                $fields_array[] = $row;
            }

            $total = count($fields_array);
            [$kept, $dropped] = $this->cappyFitRows($fields_array, self::CAPPY_PAYLOAD_BUDGET);

            // Build the note from what actually happened, so it never claims a
            // truncation that didn't occur or stays silent about one that did.
            $note = [];
            if (!$scoped) {
                $note[] = "SLIM VIEW: choices, validation and branching logic are omitted. "
                    . "To get the answer choices for a coded field (needed to turn a stored "
                    . "code into a label), call this tool again with fields=[\"field_a\",\"field_b\"] "
                    . "— a scoped call returns the full definition and fits comfortably.";
            }
            if ($dropped > 0) {
                $note[] = "Returned " . count($kept) . " of $total fields; $dropped omitted to fit the "
                    . "token budget. This is NOT the whole dictionary — do not conclude a field is "
                    . "absent from the project because it is missing here. Request specific fields by "
                    . "name via the 'fields' parameter instead of re-requesting everything.";
            }
            if ($scoped) {
                $note[] = "Coded fields: 'select_choices_or_calculations' holds the "
                    . "code,label pairs. Note that records.get already returns values with "
                    . "labels resolved, so you rarely need to map codes by hand.";
            }

            // Key order matters: SecureChatAI's cap drops trailing keys, so the
            // counts and note must precede the (much larger) field list.
            return [
                "pid" => $pid,
                "field_count" => $total,
                "returned_field_count" => count($kept),
                "view" => $scoped ? "full" : "slim",
                "note" => implode(' ', $note),
                "fields" => $kept,
            ];
        } catch (\Exception $e) {
            $this->emError("getMetadata error for pid $pid: " . $e->getMessage());
            return [
                "error" => true,
                "message" => "Failed to retrieve metadata: " . $e->getMessage()
            ];
        }
    }


    /**
     * Tool 4: projects.getInstruments
     * List all instruments/forms in a project
     */
    public function toolGetInstruments(array $payload)
    {
        if (empty($payload['pid'])) {
            return [
                "error" => true,
                "message" => "Missing required parameter: pid"
            ];
        }

        $pid = (int)$payload['pid'];

        try {
            // Returns ['instrument_name' => 'Instrument Label', ...]
            $instruments = \REDCap::getInstrumentNames(null, $pid);

            if (empty($instruments)) {
                return [
                    "error" => true,
                    "message" => "No instruments found for project $pid"
                ];
            }

            // Convert to array of objects
            $instruments_array = [];
            foreach ($instruments as $name => $label) {
                $instruments_array[] = [
                    'instrument_name' => $name,
                    'instrument_label' => $label
                ];
            }

            return [
                "pid" => $pid,
                "instrument_count" => count($instruments_array),
                "instruments" => $instruments_array
            ];
        } catch (\Exception $e) {
            $this->emError("getInstruments error for pid $pid: " . $e->getMessage());
            return [
                "error" => true,
                "message" => "Failed to retrieve instruments: " . $e->getMessage()
            ];
        }
    }

    /**
     * Tool 5: records.get
     * Get specific record data by record ID.
     *
     * Returns 'values': the record with every coded field ALREADY RESOLVED to
     * its choice label, and empty fields omitted. Raw getData output is
     * withheld unless include_raw=true.
     *
     * Why the labeled view is the default (and the raw one is not):
     * getData('array') returns codes, and for checkbox fields it returns one
     * key per option whether checked or not. On PID 70, record 1 came back as
     * ~21KB — over SecureChatAI's 8000-char tool-result cap, whose object
     * branch drops an oversized key WHOLESALE, so 'data' vanished and the model
     * got nothing at all. When it narrowed to fields=['d_spoken_language'] it
     * received 141 keys of `code => '0'|'1'` and knew code 5 was checked but
     * not that 5 means "Mixteco Bajo" — so it asked the USER for the mapping.
     * Resolving server-side kills that round-trip and shrinks the payload at
     * the same time: 141 mostly-zero keys collapse into one string of labels.
     */
    public function toolGetRecord(array $payload)
    {
        if (empty($payload['pid'])) {
            return [
                "error" => true,
                "message" => "Missing required parameter: pid"
            ];
        }

        if (empty($payload['record_id'])) {
            return [
                "error" => true,
                "message" => "Missing required parameter: record_id"
            ];
        }

        $pid = (int)$payload['pid'];
        $record_id = $payload['record_id'];
        $fields = $payload['fields'] ?? null; // Optional
        $events = $payload['events'] ?? null; // Optional (for longitudinal)
        $includeRaw = !empty($payload['include_raw']);

        try {
            $data = \REDCap::getData($pid, 'array', [$record_id], $fields, $events);

            if (empty($data)) {
                return [
                    "error" => true,
                    "message" => "No data found for record '$record_id' in project $pid"
                ];
            }

            // Walk getData's nested shape ourselves rather than reusing
            // cappyFlattenRows(): that helper discards event and instance
            // identity, which is fine for a preview table but would leave the
            // model unable to say WHICH visit or instance a value came from on
            // a longitudinal or repeating project — trading the code/label
            // round-trip we're fixing for a "which visit did you mean?" one.
            $eventNames = [];
            $longitudinal = false;
            try {
                $projObj = new \Project($pid);
                $longitudinal = !empty($projObj->longitudinal);
                if ($longitudinal) $eventNames = $projObj->getUniqueEventNames();
            } catch (\Exception $e) {
                // Best effort: fall back to bare event ids below.
            }

            $rawRows = [];
            foreach ($data as $events) {
                if (!is_array($events)) continue;
                foreach ($events as $eventId => $eventData) {
                    if ($eventId === 'repeat_instances' || !is_array($eventData)) continue;
                    $rawRows[] = [
                        $this->cappyRowContext($longitudinal, $eventNames, $eventId, null, null),
                        $eventData,
                    ];
                }
                if (isset($events['repeat_instances']) && is_array($events['repeat_instances'])) {
                    foreach ($events['repeat_instances'] as $eventId => $instruments) {
                        if (!is_array($instruments)) continue;
                        foreach ($instruments as $instrument => $instances) {
                            if (!is_array($instances)) continue;
                            foreach ($instances as $instance => $rowFields) {
                                if (!is_array($rowFields)) continue;
                                $rawRows[] = [
                                    $this->cappyRowContext($longitudinal, $eventNames, $eventId, $instrument, $instance),
                                    $rowFields,
                                ];
                            }
                        }
                    }
                }
            }

            // Resolve labels for the fields actually present, not the whole
            // dictionary — a scoped getDataDictionary call is much cheaper on
            // wide projects.
            $present = [];
            foreach ($rawRows as [, $rowFields]) {
                foreach (array_keys($rowFields) as $f) $present[$f] = true;
            }
            $choiceMaps = $this->cappyChoiceMaps($pid, array_keys($present));

            $emptyOmitted = 0;
            $built = [];
            foreach ($rawRows as [$ctx, $rowFields]) {
                $out = [];
                foreach ($rowFields as $field => $val) {
                    $resolved = $this->cappyLabelValue($choiceMaps[$field] ?? [], $val);
                    // Blank fields are the bulk of a wide record and are almost
                    // never the question. Omitted, but COUNTED — see the note.
                    if ($resolved === '') { $emptyOmitted++; continue; }
                    $out[$field] = $resolved;
                }
                $built[] = ['ctx' => $ctx, 'fields' => $out];
            }

            // Drop rows that came back entirely empty, but never return zero
            // rows for a record that exists — keep one so the caller can tell
            // "record found, all blank" from "record not found".
            $withData = array_values(array_filter($built, fn($b) => !empty($b['fields'])));
            $use = !empty($withData) ? $withData : array_slice($built, 0, 1);

            // Trim to fit rather than letting the outer cap drop 'values'
            // entirely. Without this a wide record (PID 70 record 1 is ~14KB
            // even after label collapsing) loses the whole payload, and the
            // model then receives BOTH our note saying "call again with
            // fields=[...]" and SecureChatAI's generic "do not retry" — a
            // direct contradiction on the one path that most needs to be clear.
            [$use, $sizeOmitted] = $this->cappyFitRecordRows($use, self::CAPPY_PAYLOAD_BUDGET);

            // Shape decision, made ONCE for the whole response so every row
            // looks the same. A record with one plain event row plus three
            // repeating instances would otherwise mix a flat field map (row 0,
            // which has no context keys) with wrapped rows — the kind of
            // heterogeneous list that invites the model to misread it.
            // getData returned something we couldn't walk into rows at all.
            // Guarded explicitly: $use[0] below would throw on an empty list.
            $useWrapper = count($use) > 1 || !empty($use[0]['ctx']);
            if (empty($use)) {
                $values = [];
            } elseif ($useWrapper) {
                $values = array_map(fn($b) => $b['ctx'] + ['fields' => $b['fields']], $use);
            } else {
                // The common classic-project case (a single non-repeating row)
                // collapses to a flat field => value map so the model doesn't
                // have to reach through a pointless wrapper.
                $values = $use[0]['fields'];
            }

            // Key order matters: SecureChatAI's cap drops trailing keys, so the
            // note (which tells the model how to recover) must land BEFORE the
            // payload that might not fit, and raw data goes last.
            $response = [
                "pid" => $pid,
                "record_id" => $record_id,
                "row_count" => count($use),
                "empty_fields_omitted" => $emptyOmitted,
                "fields_omitted_for_size" => $sizeOmitted,
                "note" => "'values' holds this record with all coded fields ALREADY "
                    . "RESOLVED to their choice labels (checkbox fields list the checked "
                    . "labels, comma-joined). Report those labels to the user as-is — do "
                    . "NOT translate codes yourself and NEVER ask the user for a code-to-label "
                    . "mapping; it is already applied here. Empty fields are omitted. "
                    . ($useWrapper
                        ? "This record has multiple rows (longitudinal events and/or repeating "
                            . "instrument instances), so 'values' is a LIST: each entry has its "
                            . "field values under 'fields', plus 'event'/'instrument'/'instance' "
                            . "identifying where those values live. A row with no 'instrument' is "
                            . "the non-repeating data for that event. "
                        : "'values' is a flat field => value map. ")
                    . ($sizeOmitted > 0
                        ? "NOT ALL FIELDS ARE HERE: $sizeOmitted more had values but were cut to "
                            . "fit the token budget (fields come in form order, so the tail is "
                            . "missing). If what you need isn't above, call again with "
                            . "fields=[...] naming it — that returns it reliably. Do not tell the "
                            . "user a field is empty just because it is absent here. "
                        : "")
                    . "Pass include_raw=true only when you need underlying codes for "
                    . "computation."
                    . ($includeRaw ? "" : " Raw getData output withheld by default."),
                "values" => $values,
            ];
            if ($includeRaw) {
                $response["data"] = $data;
            }
            return $response;
        } catch (\Exception $e) {
            $this->emError("getRecord error for pid $pid, record $record_id: " . $e->getMessage());
            return [
                "error" => true,
                "message" => "Failed to retrieve record: " . $e->getMessage()
            ];
        }
    }

    /**
     * Tool 6: records.search
     * Search records with optional REDCap logic filter
     */
    public function toolSearchRecords(array $payload)
    {
        if (empty($payload['pid'])) {
            return [
                "error" => true,
                "message" => "Missing required parameter: pid"
            ];
        }

        $pid = (int)$payload['pid'];
        $filter = $payload['filter'] ?? null; // REDCap logic string like "[age] > 18"

        // Validate filter field names against the data dictionary — a typo'd
        // field silently returns 0 records and sends the agent flailing.
        // Return a hard error with close-match suggestions instead.
        if (!empty($filter)) {
            $fieldError = $this->cappyValidateFilterFields($pid, $filter);
            if ($fieldError !== null) return $fieldError;
        }

        // Expand any label literals in the filter to (code OR label) so the
        // agent can write [d_legal_sex] = "Female" and still match the 2-codes.
        // Nerds who already use the code get a no-op expansion.
        $filterExpansions = [];
        $filterHints = [];
        if (!empty($filter)) {
            $expanded = $this->cappyExpandFilterLabels($pid, $filter);
            $filter = $expanded['filter'];
            $filterExpansions = $expanded['translations'];
            $filterHints = $expanded['hints'] ?? [];
        }
        $fields = $payload['fields'] ?? null; // Optional
        $return_format = $payload['return_format'] ?? 'array'; // 'array' or 'json'
        $offset = max(0, (int)($payload['offset'] ?? 0));
        // No upper clamp — caller may request as many as they want (default
        // page size is MAX_RECORDS_RETURNED). The note flags large result
        // sets so the agent can suggest narrowing instead of paging forever.
        $limit = (int)($payload['limit'] ?? self::MAX_RECORDS_RETURNED);
        if ($limit <= 0) {
            $limit = self::MAX_RECORDS_RETURNED;
        }
        // Raw rows are withheld by default — the LLM gets preview_markdown for
        // display and a reference for filtering/paging. Inlining hundreds of
        // full-width records bloats the context window and makes models
        // refuse to render ("due to space limits..."). Set include_records
        // only when the raw rows are genuinely needed for computation.
        $includeRecords = !empty($payload['include_records']);
        $formatRecords = function ($page) use ($return_format, $includeRecords) {
            if (!$includeRecords) return [];
            return $return_format === 'json' ? json_encode($page) : $page;
        };

        // What we tell the model about raw rows MUST depend on whether it already
        // asked for them. The old note said "rows are withheld, pass
        // include_records=true" unconditionally — including to a caller that had
        // just passed exactly that. Combined with SecureChatAI's result cap (which
        // drops trailing keys, and `records` is the last key), the model got a
        // response with `records` amputated plus an instruction to ask again. It
        // obliged, repeatedly, until the loop detector killed the turn. Observed on
        // PID 70: a 182,943-char result against an 8,000-char cap, five times.
        $rawRowsHint = $includeRecords
            ? "You requested raw rows, so they are in 'records' IF they fit. If 'records' "
                . "is missing or the result reports itself truncated, the rows did NOT fit "
                . "the token budget — do NOT request them again, and do NOT retry with a "
                . "smaller 'limit': what usually overflows is the WIDTH of each record (a "
                . "checkbox field with many options costs over a kilobyte per record), so "
                . "fewer records truncates the same way. Instead pass 'fields' to ask for "
                . "only the columns you actually need, or answer from 'preview_markdown'."
            : "Raw rows are withheld by default; pass include_records=true only if you need them for computation.";

        // Append path: run the new filter as a fresh query, then UNION the
        // results into the referenced cached recordset (the "accumulating
        // working set" — e.g. severity=3 set, then "also severity=4" merges
        // into one 532-record set). Only the new slice hits the database.
        $appendTo = $payload['append_to'] ?? null;
        $appendBase = null;
        if ($appendTo) {
            $appendBase = $this->cappyCacheFetch($appendTo, 'records_search');
            if ($appendBase === null) {
                return [
                    "error" => true,
                    "message" => "append_to reference $appendTo not found or expired. Call the tool again without 'append_to' to start a new query."
                ];
            }
            if (($appendBase['pid'] ?? null) !== $pid) {
                return [
                    "error" => true,
                    "message" => "append_to reference $appendTo was cached for a different project."
                ];
            }
        }

        // Reference path: reuse the PHP session cache instead of re-querying.
        // Two modes:
        //   1. reference + same/no filter  → page through the cached recordset
        //   2. reference + NEW filter      → apply the filter against the cached
        //      recordset in memory (evaluateLogic with inline record_data, no
        //      getData round trip), cache the subset under a new reference
        $reference = $payload['reference'] ?? null;
        if ($reference) {
            $cached = $this->cappyCacheFetch($reference, 'records_search');
            if ($cached === null) {
                return [
                    "error" => true,
                    "message" => "Reference $reference not found or expired. Call the tool again without 'reference' to re-query."
                ];
            }
            if (($cached['pid'] ?? null) !== $pid) {
                return [
                    "error" => true,
                    "message" => "Reference $reference was cached for a different project (expected $pid, got " . ($cached['pid'] ?? 'null') . ")."
                ];
            }

            $data = $cached['ids'];
            $cachedFilter = trim((string)($cached['filter'] ?? ''));
            $newFilter = trim((string)($filter ?? ''));
            $filteredFromCache = false;

            // Mode 2: new filter → narrow the cached recordset in memory
            if ($newFilter !== '' && $newFilter !== $cachedFilter) {
                $data = $this->cappyFilterCachedRecords($pid, $data, $newFilter);
                $filteredFromCache = true;
                // Cache the narrowed subset so follow-ups can chain off it
                $reference = $this->cappyCacheStore('records_search', [
                    'pid' => $pid,
                    'filter' => $newFilter,
                    'ids' => $data,
                ]);
            }

            $total = count($data);
            $page = array_slice($data, $offset, $limit, true);
            $returned_count = count($page);
            $activeFilter = $filteredFromCache ? $newFilter : $cachedFilter;
            // Key order matters: SecureChatAI caps oversized tool results by
            // dropping trailing keys — preview_markdown/note MUST come before
            // the (potentially huge) records payload or they get amputated.
            $largeSetNote = $total > self::MAX_RECORDS_RETURNED
                ? " LARGE RESULT SET ($total records) — rather than paging through all of them, suggest the user narrow their search with a more specific filter (pass reference + new filter to narrow in memory)."
                : "";
            $result = [
                "pid" => $pid,
                "filter" => $activeFilter,
                "reference" => $reference,
                "total_record_count" => $total,
                "returned_count" => $returned_count,
                "offset" => $offset,
                "limit" => $limit,
                "truncated" => ($offset + $returned_count) < $total,
                "preview_markdown" => $this->cappyBuildPreview($pid, $page, $activeFilter),
                "note" => ($filteredFromCache
                    ? "Filtered from the cached recordset in memory (no database re-query). Subset cached as $reference — pass it back with a new filter to narrow further, or with offset/limit to page. "
                    : "Served from session cache (reference $reference). ")
                    . "IMPORTANT: render 'preview_markdown' to the user VERBATIM as a markdown table — do not ask which fields to show, do not summarize instead of showing. "
                    . $rawRowsHint
                    . $largeSetNote,
                "records" => $formatRecords($page),
            ];
            return $result;
        }

        try {
            // Always fetch as 'array' internally so we can slice by record for pagination;
            // converted to the requested return_format after slicing.
            $data = \REDCap::getData(
                $pid,
                'array',
                null,        // all matching records (filter applied via $filterLogic)
                $fields,
                null,        // events
                null,        // groups
                false,       // combine checkbox values
                false,       // DAG
                false,       // survey fields
                $filter      // REDCap logic filter
            );

            $total_record_count = is_array($data) ? count($data) : 0;

            // Append mode: union the freshly-queried slice into the referenced
            // cached recordset. Fresh rows win on duplicate record IDs.
            $mergedFrom = null;
            if ($appendBase !== null) {
                $baseCount = count($appendBase['ids']);
                $newCount = $total_record_count;
                $data = array_replace($appendBase['ids'], is_array($data) ? $data : []);
                $total_record_count = count($data);
                $mergedFrom = [
                    'base_count' => $baseCount,
                    'new_count' => $newCount,
                    'added_count' => $total_record_count - $baseCount,
                    'base_filter' => $appendBase['filter'] ?? null,
                ];
            }

            // Always cache the full result set so follow-up questions can filter
            // or page within it without another getData round trip.
            $ref = $this->cappyCacheStore('records_search', [
                'pid' => $pid,
                'filter' => $mergedFrom
                    ? "(" . ($mergedFrom['base_filter'] ?? '') . ") OR (" . ($filter ?? '') . ")"
                    : $filter,
                'ids' => $data,
            ]);

            $page = is_array($data) ? array_slice($data, $offset, $limit, true) : [];
            $returned_count = count($page);
            $truncated = ($offset + $returned_count) < $total_record_count;

            // Key order matters: SecureChatAI caps oversized tool results by
            // dropping trailing keys — preview_markdown/note/message MUST come
            // before the (potentially huge) records payload.
            $result = [
                "pid" => $pid,
                "filter" => $filter,
                "filter_translations" => $filterExpansions,
                // Present only when a filter literal is not a choice of the
                // field it was compared against but IS a choice elsewhere —
                // turns a true-but-useless 0 into a legible wrong-field hint.
                "value_not_on_this_field" => $filterHints,
                "reference" => $ref,
                "total_record_count" => $total_record_count,
                "returned_count" => $returned_count,
                "offset" => $offset,
                "limit" => $limit,
                "truncated" => $truncated,
                "preview_markdown" => $this->cappyBuildPreview($pid, $page, $filter),
                "note" => ($mergedFrom
                    ? "APPENDED to the cached working set: {$mergedFrom['new_count']} records matched the new filter, {$mergedFrom['added_count']} were new — merged set is now $total_record_count records (was {$mergedFrom['base_count']}), cached as \"$ref\". The accumulated set is what the user now means by 'the records' — narrow it with reference + new filter, or append more with append_to + new filter. "
                    : "")
                    . "IMPORTANT: render 'preview_markdown' to the user VERBATIM as a markdown table — do not ask which fields to show, do not summarize instead of showing. "
                    . $rawRowsHint
                    // Be explicit that paging yields the next PREVIEW page, not
                    // raw rows. Sitting next to the "don't retry with a smaller
                    // limit" warning above, an unqualified "page with
                    // offset/limit" reads as permission to keep re-requesting
                    // rows — which is the loop this note exists to prevent.
                    . " The full result set is cached as reference \"$ref\" — pass it with offset/limit to get the NEXT PAGE OF preview_markdown (paging does not make withheld raw rows fit), or with a new filter to narrow.",
                 "records" => $formatRecords($page),
            ];

            if ($truncated) {
                // Insert BEFORE records — appended keys get amputated by the
                // SecureChatAI result-size cap when records is large.
                $pagingMsg = "Showing $returned_count of $total_record_count matching records "
                    . "(offset $offset). The FULL result set is cached server-side as reference \"$ref\" "
                    . "(expires in " . (self::CAPPY_CACHE_TTL / 60) . " minutes). For follow-up questions: "
                    . "pass reference=\"$ref\" with a NEW 'filter' to narrow within this recordset without "
                    . "re-querying the database, or pass reference + offset/limit to page through it."
                    . ($total_record_count > self::MAX_RECORDS_RETURNED
                        ? " LARGE RESULT SET ($total_record_count records) — rather than paging through all of them, suggest the user narrow their search with a more specific filter."
                        : "");
                $recordsVal = $result['records'];
                unset($result['records']);
                $result['message'] = $pagingMsg;
                $result['records'] = $recordsVal;
            }

            return $result;
        } catch (\Exception $e) {
            $this->emError("searchRecords error for pid $pid: " . $e->getMessage());
            return [
                "error" => true,
                "message" => "Failed to search records: " . $e->getMessage()
            ];
        }
    }

    /**
     * Tool 6b: records.count
     * Return only the count of records matching an optional REDCap logic filter.
     * No record data is fetched — single cheap call.
     */
    public function toolCountRecords(array $payload)
    {
        if (empty($payload['pid'])) {
            return [
                "error" => true,
                "message" => "Missing required parameter: pid"
            ];
        }

        $pid = (int)$payload['pid'];
        $filter = $payload['filter'] ?? null;

        if (!empty($filter)) {
            $fieldError = $this->cappyValidateFilterFields($pid, $filter);
            if ($fieldError !== null) return $fieldError;
        }

        // Expand any label literals in the filter to (code OR label) — same as
        // toolSearchRecords so [d_legal_sex] = "Female" still matches code 2.
        $filterExpansions = [];
        $filterHints = [];
        if (!empty($filter)) {
            $expanded = $this->cappyExpandFilterLabels($pid, $filter);
            $filter = $expanded['filter'];
            $filterExpansions = $expanded['translations'];
            $filterHints = $expanded['hints'] ?? [];
        }

        try {
            $data = \REDCap::getData(
                $pid,
                'array',
                null,    // all matching records
                null,    // no fields needed — we only count
                null,    // events
                null,    // groups
                false,   // combine checkbox values
                false,   // DAG
                false,   // survey fields
                $filter  // REDCap logic filter
            );

            $count = is_array($data) ? count($data) : 0;
            $recordIdField = \REDCap::getRecordIdField($pid);

            return [
                "pid"      => $pid,
                "filter"   => $filter,
                "filter_translations" => $filterExpansions,
                // Present only when a filter literal is not a choice of the
                // field it was compared against but IS a choice elsewhere —
                // turns a true-but-useless 0 into a legible wrong-field hint.
                "value_not_on_this_field" => $filterHints,
                "count"    => $count,
                "record_id_field" => $recordIdField,
                "note"     => "This is the count of records matching the filter (or all records if no filter). No record data was returned."
            ];
        } catch (\Exception $e) {
            $this->emError("countRecords error for pid $pid: " . $e->getMessage());
            return [
                "error"   => true,
                "message" => "Failed to count records: " . $e->getMessage()
            ];
        }
    }

    /**
     * Tool 6c: records.listIds
     * Return just the record IDs in a project (no field data). Useful when
     * the user wants to enumerate record IDs without pulling PHI into context.
     * Returns IDs in REDCap's natural order.
     */
    public function toolListRecordIds(array $payload)
    {
        if (empty($payload['pid'])) {
            return [
                "error" => true,
                "message" => "Missing required parameter: pid"
            ];
        }

        $pid = (int)$payload['pid'];
        $filter = $payload['filter'] ?? null;
        $offset = max(0, (int)($payload['offset'] ?? 0));
        $limit = (int)($payload['limit'] ?? 50);
        if ($limit <= 0 || $limit > 200) $limit = 50;
        $reference = $payload['reference'] ?? null;

        // Reference path: serve from PHP session cache
        if ($reference) {
            $cached = $this->cappyCacheFetch($reference, 'records_listIds');
            if ($cached === null) {
                return [
                    "error" => true,
                    "message" => "Reference $reference not found or expired. Call the tool again without 'reference' to re-query."
                ];
            }
            if (($cached['pid'] ?? null) !== $pid) {
                return [
                    "error" => true,
                    "message" => "Reference $reference was cached for a different project."
                ];
            }
            $ids = $cached['ids'];
            $slice = array_slice($ids, $offset, $limit);
            return [
                "pid" => $pid,
                "filter" => $filter,
                "reference" => $reference,
                "count" => count($ids),
                "returned_count" => count($slice),
                "offset" => $offset,
                "limit" => $limit,
                "record_id_field" => $cached['record_id_field'] ?? null,
                "record_ids" => $slice,
                "truncated" => ($offset + count($slice)) < count($ids),
                "note" => "Served from session cache (reference $reference).",
            ];
        }

        try {
            $data = \REDCap::getData(
                $pid,
                'array',
                null,
                null,
                null,
                null,
                false,
                false,
                false,
                $filter
            );

            $recordIdField = \REDCap::getRecordIdField($pid);
            $ids = is_array($data) ? array_keys($data) : [];
            sort($ids, SORT_NATURAL);

            // Large list: cache and return a ref + small preview
            if (count($ids) > $limit * 2) {
                $ref = $this->cappyCacheStore('records_listIds', [
                    'pid' => $pid,
                    'filter' => $filter,
                    'record_id_field' => $recordIdField,
                    'ids' => $ids,
                ]);
                $preview = array_slice($ids, 0, 10);
                return [
                    "pid" => $pid,
                    "filter" => $filter,
                    "reference" => $ref,
                    "count" => count($ids),
                    "returned_count" => 0,
                    "offset" => 0,
                    "limit" => 10,
                    "record_id_field" => $recordIdField,
                    "record_ids_preview" => $preview,
                    "record_ids" => [],
                    "truncated" => true,
                    "note" => "Result is large (" . count($ids) . " IDs). Cached server-side in the PHP session. Use records.listIds(pid=$pid, reference=\"$ref\", offset=N, limit=M) to page through."
                ];
            }

            $slice = array_slice($ids, $offset, $limit);

            return [
                "pid"             => $pid,
                "filter"          => $filter,
                "count"           => count($ids),
                "returned_count"  => count($slice),
                "offset"          => $offset,
                "limit"           => $limit,
                "record_id_field" => $recordIdField,
                "record_ids"      => $slice,
                "truncated"       => ($offset + count($slice)) < count($ids),
                "note"            => "Record IDs only — no field data returned. For full record contents use records.get or Data Exports."
            ];
        } catch (\Exception $e) {
            $this->emError("listRecordIds error for pid $pid: " . $e->getMessage());
            return [
                "error"   => true,
                "message" => "Failed to list record IDs: " . $e->getMessage()
            ];
        }
    }

    /**
     * Tool 7: survey.getLink
     * Generate survey link for a specific instrument and record
     */
    public function toolGetSurveyLink(array $payload)
    {
        if (empty($payload['pid'])) {
            return [
                "error" => true,
                "message" => "Missing required parameter: pid"
            ];
        }

        if (empty($payload['record_id'])) {
            return [
                "error" => true,
                "message" => "Missing required parameter: record_id"
            ];
        }

        if (empty($payload['instrument'])) {
            return [
                "error" => true,
                "message" => "Missing required parameter: instrument"
            ];
        }

        $pid = (int)$payload['pid'];
        $record_id = $payload['record_id'];
        $instrument = $payload['instrument'];
        $event = $payload['event'] ?? null; // Optional (for longitudinal)
        $instance = $payload['instance'] ?? 1; // Optional (for repeating instruments)

        try {
            $survey_url = \REDCap::getSurveyLink($record_id, $instrument, $event, $instance, $pid);

            if (empty($survey_url)) {
                return [
                    "error" => true,
                    "message" => "Could not generate survey link (instrument may not be a survey, or record/event invalid)"
                ];
            }

            return [
                "pid" => $pid,
                "record_id" => $record_id,
                "instrument" => $instrument,
                "event" => $event,
                "survey_url" => $survey_url
            ];
        } catch (\Exception $e) {
            $this->emError("getSurveyLink error for pid $pid: " . $e->getMessage());
            return [
                "error" => true,
                "message" => "Failed to generate survey link: " . $e->getMessage()
            ];
        }
    }

    /**
     * Tool 8: records.evaluateLogic
     * Evaluate REDCap logic expression for a specific record
     */
    public function toolEvaluateLogic(array $payload)
    {
        if (empty($payload['pid'])) {
            return [
                "error" => true,
                "message" => "Missing required parameter: pid"
            ];
        }

        if (empty($payload['record_id'])) {
            return [
                "error" => true,
                "message" => "Missing required parameter: record_id"
            ];
        }

        if (empty($payload['logic'])) {
            return [
                "error" => true,
                "message" => "Missing required parameter: logic"
            ];
        }

        $pid = (int)$payload['pid'];
        $record_id = $payload['record_id'];
        $logic = $payload['logic'];
        $event = $payload['event'] ?? null; // Optional (for longitudinal)

        try {
            $result = \REDCap::evaluateLogic($logic, $pid, $record_id, null, $instance = 1, null, $event);

            return [
                "pid" => $pid,
                "record_id" => $record_id,
                "logic" => $logic,
                "event" => $event,
                "result" => (bool)$result, // normalize to boolean
                "raw_result" => $result      // also include raw value for debugging
            ];
        } catch (\Exception $e) {
            $this->emError("evaluateLogic error for pid $pid, record $record_id: " . $e->getMessage());
            return [
                "error" => true,
                "message" => "Failed to evaluate logic: " . $e->getMessage()
            ];
        }
    }

    /**
     * Tool 9: projects.search
     * Search for projects by name/description (fuzzy match)
     */
    public function toolSearchProjects(array $payload)
    {
        if (empty($payload['query'])) {
            return [
                "error" => true,
                "message" => "Missing required parameter: query"
            ];
        }

        $query = $payload['query'];
        $limit = $payload['limit'] ?? 10; // Optional: limit results

        try {
            // Get current user from session or API context
            global $userid;

            // If no userid (API context), try to get from USERID constant or defined()
            if (empty($userid)) {
                if (defined('USERID')) {
                    $userid = USERID;
                } elseif (!empty($_SESSION['username'])) {
                    $userid = $_SESSION['username'];
                }
            }

            // PHI-safe: log query length only — never the query itself (may
            // contain project names or patient identifiers from user input).
            $this->emDebug("projects.search debug", [
                'query_length' => strlen($query),
                'userid' => $userid,
                'has_session' => isset($_SESSION['username'])
            ]);

            // For now: search ALL projects (no user filtering)
            // TODO: Add proper permission filtering in governance layer
            $sql = "SELECT project_id, app_title, purpose, creation_time
                    FROM redcap_projects
                    WHERE (
                        app_title LIKE ?
                        OR purpose LIKE ?
                        OR project_id = ?
                    )
                    ORDER BY
                        CASE
                            WHEN app_title LIKE ? THEN 1
                            WHEN app_title LIKE ? THEN 2
                            ELSE 3
                        END,
                        creation_time DESC
                    LIMIT ?";

            $searchTerm = '%' . $query . '%';
            $exactStart = $query . '%';
            $projectId = is_numeric($query) ? (int)$query : 0;

            $result = db_query($sql, [
                $searchTerm,
                $searchTerm,
                $projectId,
                $exactStart,
                $searchTerm,
                $limit
            ]);

            $projects = [];
            while ($row = db_fetch_assoc($result)) {
                $projects[] = [
                    'pid' => (int)$row['project_id'],
                    'title' => $row['app_title'],
                    'purpose' => $row['purpose'] ? (int)$row['purpose'] : null,
                    'creation_time' => $row['creation_time']
                ];
            }

            if (empty($projects)) {
                return [
                    "query" => $query,
                    "project_count" => 0,
                    "projects" => [],
                    "message" => "No projects found matching '$query'"
                ];
            }

            return [
                "query" => $query,
                "project_count" => count($projects),
                "projects" => $projects
            ];
        } catch (\Exception $e) {
            $this->emError("searchProjects error: " . $e->getMessage());
            return [
                "error" => true,
                "message" => "Failed to search projects: " . $e->getMessage()
            ];
        }
    }

    /**
     * Tool 10: records.save — WRITE DISABLED 2026-08-24
     * Guarantees no record writes via Cappy pending real per-pid authorization
     * (see the redcap_module_api() docblock above). Three independent gates:
     * pulled from tools.json, no case in routeToolCall(), and this hard return.
     * The method stays public so a direct cross-EM call lands here too.
     * The original \REDCap::saveData() implementation was removed in this same
     * commit — recover it from git history if this is ever re-enabled.
     */
    public function toolSaveRecords(array $payload)
    {
        return [
            "error" => true,
            "message" => "records.save is disabled — Cappy agent mode cannot write record data."
        ];
    }

    /**
     * Validate that every [field] referenced in a REDCap logic filter exists
     * in the project's data dictionary. Unknown fields silently evaluate to
     * 0 matching records, which sends the agent guessing — fail loudly with
     * ranked suggestions (best_match + candidates with labels) instead.
     * Event names in [event][field] syntax are allowed through. Returns an
     * error array, or null when all fields are OK.
     */
    private function cappyValidateFilterFields(int $pid, string $filter): ?array
    {
        $referenced = $this->cappyExtractFilterFields($filter);
        if (empty($referenced)) return null;

        try {
            $dd = \REDCap::getDataDictionary($pid, 'array');
            $valid = array_fill_keys(array_keys($dd), true);
            // Unique event names are legal in [event][field] syntax
            foreach ((array)\REDCap::getEventNames(true) as $uniqueName) {
                $valid[$uniqueName] = true;
            }
        } catch (\Exception $e) {
            return null; // can't validate — let the query run
        }

        $unknown = [];
        foreach ($referenced as $f) {
            if (!isset($valid[$f])) $unknown[] = $f;
        }
        if (empty($unknown)) return null;

        // Rank candidates per unknown field by combined name+label similarity.
        // The agent should pick best_match unless its label clearly doesn't
        // match the user's intent; labels are exposed so the agent (LLM) can
        // make the final semantic call without another round-trip.
        $suggestions = [];
        foreach ($unknown as $f) {
            $scored = [];
            foreach ($dd as $candName => $candInfo) {
                $label = (string)($candInfo['field_label'] ?? '');
                similar_text($f, $candName, $namePct);
                $labelPct = 0;
                if ($label !== '') {
                    similar_text(strtolower($f), strtolower($label), $labelPct);
                }
                // Combined score: label match weighted slightly higher because
                // field_label is what a human would actually call this concept.
                $score = max($namePct, $labelPct * 1.1);
                if ($score >= 40) {
                    $scored[] = [
                        'field_name'  => $candName,
                        'field_label' => $label,
                        'score'       => (int)round($score),
                    ];
                }
            }
            usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
            $top = array_slice($scored, 0, 3);
            $suggestions[$f] = [
                'best_match'  => $top[0]['field_name']  ?? null,
                'best_label'  => $top[0]['field_label'] ?? null,
                'candidates'  => $top,
            ];
        }

        $summary = [];
        foreach ($suggestions as $bad => $info) {
            $summary[] = "{$bad} -> {$info['best_match']} (label: \"{$info['best_label']}\")";
        }

        return [
            "error" => true,
            "message" => "Unknown field(s) in filter: " . implode(', ', $unknown)
                . ". These fields do not exist in project $pid — the filter would silently match 0 records. "
                . "Best guesses: " . implode('; ', $summary)
                . ". Use best_match unless its label clearly doesn't match the user's intent.",
            "unknown_fields" => $unknown,
            "suggestions" => $suggestions,
        ];
    }

    /**
     * Flatten getData('array') output into a list of [record_id, fields] rows —
     * one per event row AND one per repeating-instrument instance. Without
     * this, projects whose data lives on repeating forms (like PIVIE phase 2)
     * produce empty previews: the base event row is empty and everything real
     * is under 'repeat_instances'.
     */
    private function cappyFlattenRows(array $data): array
    {
        $rows = [];
        foreach ($data as $rid => $events) {
            if (!is_array($events)) continue;
            $recordHasData = false;
            $recordRows = [];
            foreach ($events as $eventId => $eventData) {
                if ($eventId === 'repeat_instances' || !is_array($eventData)) continue;
                $recordRows[] = [$rid, $eventData];
            }
            if (isset($events['repeat_instances']) && is_array($events['repeat_instances'])) {
                foreach ($events['repeat_instances'] as $eventId => $instruments) {
                    if (!is_array($instruments)) continue;
                    foreach ($instruments as $instrument => $instances) {
                        if (!is_array($instances)) continue;
                        foreach ($instances as $instance => $fields) {
                            if (!is_array($fields)) continue;
                            $recordRows[] = [$rid, $fields];
                        }
                    }
                }
            }
            // Drop rows that are entirely empty UNLESS they're the record's only row
            $nonEmpty = array_filter($recordRows, function ($r) {
                foreach ($r[1] as $v) {
                    if (is_array($v)) { if (array_filter($v, fn($x) => $x !== '' && $x !== null)) return true; }
                    elseif ($v !== '' && $v !== null) return true;
                }
                return false;
            });
            $rows = array_merge($rows, !empty($nonEmpty) ? array_values($nonEmpty) : $recordRows);
        }
        return $rows;
    }

    /**
     * Build a ready-to-render markdown preview table from a page of getData
     * rows. The LLM is instructed to echo this verbatim — eliminates the
     * "would you like to see the records?" dance when records are wide.
     *
     * Rows: one per record instance (repeating instruments flattened).
     * Columns: record_id + fields referenced in the filter + the fields with
     * the most non-empty values in the sample (checkbox arrays and form
     * status fields excluded), capped at $maxCols. Rows capped at $maxRows.
     *
     * Checkbox fields: REDCap returns these as array values
     * (`{code => '0'|'1', ...}` per option). The preview renders each row's
     * checked options as a comma-joined list of labels — so users see e.g.
     * "Blister, Bruised, Redness" instead of an opaque array.
     */
    private function cappyBuildPreview(int $pid, array $page, ?string $filter, int $maxRows = 20, int $maxCols = 8): string
    {
        if (empty($page)) return '';
        $recordIdField = \REDCap::getRecordIdField($pid);

        $rows = $this->cappyFlattenRows($page);
        if (empty($rows)) return '';

        // Identify checkbox fields: scan rows for any field whose value is an
        // array (the checkbox shape REDCap returns). Confirm each candidate
        // against the data dictionary so we don't accidentally promote scalar
        // fields that happen to be array-typed for some other reason.
        $checkboxFields = $this->cappyDetectCheckboxFields($pid, $rows);

        // Column selection
        $cols = [$recordIdField];
        foreach ($this->cappyExtractFilterFields($filter ?? '') as $f) {
            if ($f === $recordIdField || in_array($f, $cols)) continue;
            foreach ($rows as [, $fields]) {
                if (!array_key_exists($f, $fields)) continue;
                // Allow checkbox bases (array values) and scalar values alike.
                if (isset($checkboxFields[$f])) { $cols[] = $f; break; }
                if (!is_array($fields[$f])) { $cols[] = $f; break; }
            }
        }
        // Rank remaining fields by non-empty count across the sample.
        // - Scalar fields: count rows where the value is non-empty.
        // - Checkbox fields: count rows where any option is checked (truthy).
        // Both kinds are sorted into one ranking so the most informative columns
        // win the remaining slots regardless of type.
        $scores = [];
        foreach (array_slice($rows, 0, 50) as [, $fields]) {
            foreach ($fields as $field => $val) {
                if (in_array($field, $cols)) continue;
                if (strpos($field, '___') !== false || substr($field, -9) === '_complete') continue;
                if (!isset($scores[$field])) $scores[$field] = 0;
                if (isset($checkboxFields[$field])) {
                    if (is_array($val) && $this->cappyRowHasCheckedCheckbox($val)) $scores[$field]++;
                } else {
                    if (!is_array($val) && $val !== '' && $val !== null) $scores[$field]++;
                }
            }
        }
        arsort($scores);
        foreach ($scores as $field => $n) {
            if (count($cols) >= $maxCols) break;
            if ($n === 0) break; // never add all-empty columns
            $cols[] = $field;
        }

        // Fetch metadata for the chosen columns so coded fields render as
        // LABELS ("Female") instead of raw codes ("2") — otherwise the LLM
        // does its own code→label translation and gets it wrong.
        $meta = $this->cappyChoiceMaps($pid, $cols);

        // Per-cell length cap — keeps the markdown table from collapsing into a
        // wall of text when a textarea field holds 1000+ chars. Capped AFTER
        // label resolution so "Mild/Moderate/Severe" enum labels (which are
        // short) never get truncated; raw long values get cut with a
        // single-character ellipsis. Full value is still available
        // via include_records=true for users who need it.
        $maxCellLen = 120;
        $esc = function ($v) use ($maxCellLen) {
            $escaped = str_replace(["|", "\n", "\r"], ['\|', ' ', ' '], trim((string)$v));
            return $this->cappyTruncate($escaped, $maxCellLen);
        };
        $label = function ($field, $val) use ($meta, $esc) {
            return $esc($this->cappyLabelValue($meta[$field] ?? [], $val));
        };
        $out = '| ' . implode(' | ', $cols) . " |\n";
        $out .= '| ' . implode(' | ', array_fill(0, count($cols), '---')) . " |\n";
        foreach (array_slice($rows, 0, $maxRows) as [$rid, $fields]) {
            $cells = [];
            foreach ($cols as $c) {
                $cells[] = $c === $recordIdField ? $esc($rid) : $label($c, $fields[$c] ?? '');
            }
            $out .= '| ' . implode(' | ', $cells) . " |\n";
        }
        return $out;
    }

    /**
     * Identity keys for one records.get row: which event it belongs to and,
     * for repeating data, which instrument and instance.
     *
     * Deliberately sparse — a classic non-repeating project gets an empty
     * array, so the common case stays a flat field => value map with no
     * wrapper. Event name is only included for longitudinal projects, where
     * it's the difference between an answer and a follow-up question.
     */
    private function cappyRowContext(bool $longitudinal, array $eventNames, $eventId, $instrument, $instance): array
    {
        $ctx = [];
        if ($longitudinal) {
            $ctx['event'] = $eventNames[$eventId] ?? (string)$eventId;
        }
        // Repeating EVENTS come through with an empty instrument key.
        if ($instrument !== null && $instrument !== '') {
            $ctx['instrument'] = (string)$instrument;
        }
        if ($instance !== null) {
            $ctx['instance'] = (int)$instance;
        }
        return $ctx;
    }

    /**
     * Trim a string to $max characters with a single-character ellipsis.
     */
    private function cappyTruncate(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) return $s;
        return mb_substr($s, 0, $max - 1) . '…';
    }

    /**
     * Trim labelled records.get rows to fit $budget bytes of JSON, dropping
     * trailing FIELDS (then trailing rows) so 'values' always survives the
     * outer tool-result cap instead of being dropped wholesale.
     *
     * Fields arrive in data-dictionary order, so what gets cut is the tail of
     * the form sequence — deterministic, and the caller reports the count so
     * the omission is never silent.
     *
     * Returns [rows, omitted_field_count].
     */
    private function cappyFitRecordRows(array $built, int $budget): array
    {
        $size = 2; // [] brackets
        $out = [];
        $omitted = 0;
        $exhausted = false;
        foreach ($built as $b) {
            if ($exhausted) {
                $omitted += count($b['fields']);
                continue;
            }
            $size += strlen(json_encode($b['ctx'], JSON_UNESCAPED_UNICODE)) + 16; // ctx + "fields":{}
            $kept = [];
            foreach ($b['fields'] as $f => $v) {
                $fsz = strlen($f) + strlen(json_encode($v, JSON_UNESCAPED_UNICODE)) + 4;
                if ($size + $fsz > $budget) {
                    $exhausted = true;
                    $omitted++;
                    continue;
                }
                $kept[$f] = $v;
                $size += $fsz;
            }
            if (!empty($kept)) {
                $out[] = ['ctx' => $b['ctx'], 'fields' => $kept];
            }
        }
        return [$out, $omitted];
    }

    /**
     * Keep as many leading rows as fit within $budget bytes of JSON.
     *
     * Deliberately self-limiting: SecureChatAI's cap would otherwise drop the
     * entire list-valued key, leaving the model with a response that has no
     * data and no indication of how much it was missing. Returns
     * [kept_rows, dropped_count] so the caller can say exactly what it cut.
     */
    private function cappyFitRows(array $rows, int $budget): array
    {
        $size = 2; // [] brackets
        $kept = [];
        foreach ($rows as $row) {
            $size += strlen(json_encode($row, JSON_UNESCAPED_UNICODE)) + 1; // +1 comma
            if ($size > $budget) break;
            $kept[] = $row;
        }
        return [$kept, count($rows) - count($kept)];
    }

    /**
     * Build `field_name => [code => label]` maps for every coded field in
     * $fields (or the whole project when $fields is null).
     *
     * This is the single source of truth for code→label resolution. Every tool
     * that hands values back to the model MUST route them through here rather
     * than shipping raw codes: an LLM shown `d_legal_sex = 0` guesses "Female"
     * about as often as "Male", and when the field is a 141-option checkbox
     * like d_spoken_language it cannot even guess — it asks the user for the
     * mapping, which is both a terrible experience and information the tool
     * already had.
     *
     * REDCap's parseEnum() only splits on "\n" (legacy separator); modern
     * deployments store enums with "|" separators, which makes parseEnum treat
     * the whole string as one option. We parse both formats here.
     *
     * Labels are best-effort by design — on any dictionary failure this returns
     * [] and callers fall back to raw values rather than erroring out.
     */
    private function cappyChoiceMaps(int $pid, ?array $fields = null): array
    {
        $maps = [];
        try {
            $dd = \REDCap::getDataDictionary($pid, 'array', false, $fields);
            foreach ($dd as $fname => $info) {
                $type = $info['field_type'] ?? '';
                if (!in_array($type, ['radio', 'select', 'dropdown', 'checkbox', 'yesno', 'truefalse'], true)) {
                    continue;
                }
                if ($type === 'yesno') {
                    $maps[$fname] = ['1' => 'Yes', '0' => 'No'];
                    continue;
                }
                if ($type === 'truefalse') {
                    $maps[$fname] = ['1' => 'True', '0' => 'False'];
                    continue;
                }
                $enum = (string)($info['select_choices_or_calculations'] ?? '');
                if ($enum === '') continue;
                // Split on either pipe or literal "\n" — see cappyBuildLabelMap.
                $pairs = [];
                foreach (preg_split('/\\\\n|\|/', $enum) as $opt) {
                    $opt = trim(preg_replace('/\s+/', ' ', $opt));
                    if ($opt === '' || strpos($opt, ',') === false) continue;
                    [$code, $label] = explode(',', $opt, 2);
                    $code  = trim($code);
                    $label = trim($label);
                    if ($code === '' || $label === '') continue;
                    $pairs[$code] = $label;
                }
                $maps[$fname] = $pairs;
            }
        } catch (\Exception $e) {
            return []; // labels are best-effort; callers fall back to raw values
        }
        return $maps;
    }

    /**
     * Resolve one stored value to its human label using a choice map from
     * cappyChoiceMaps(). Pass an empty map for uncoded fields — the value
     * comes back untouched, so callers can run every field through this
     * without checking types first.
     *
     * Checkbox values arrive from getData('array') as `{code => '0'|'1'}` for
     * EVERY option, checked or not (141 keys for d_spoken_language). This
     * collapses them to the comma-joined CHECKED labels, which is both the
     * correct rendering and a large size win — most of those keys are zeros.
     */
    private function cappyLabelValue(array $choiceMap, $val): string
    {
        if (is_array($val)) {
            $checked = [];
            foreach ($val as $code => $flag) {
                if ((string)$flag !== '' && (string)$flag !== '0') {
                    $checked[] = $choiceMap[$code] ?? $code;
                }
            }
            return implode(', ', $checked);
        }
        $val = trim((string)$val);
        if ($val === '') return '';
        return array_key_exists($val, $choiceMap) ? $choiceMap[$val] : $val;
    }

    /**
     * Scan a row batch for fields whose value is an array (the checkbox
     * shape REDCap returns: `{code => '0'|'1', ...}` per option). Confirm
     * each candidate against the data dictionary so we don't accidentally
     * promote scalar fields that happen to be array-typed for some other
     * reason. Returns base field name => true for fast lookup.
     */
    private function cappyDetectCheckboxFields(int $pid, array $rows): array
    {
        $candidates = [];
        foreach ($rows as [, $fields]) {
            foreach ($fields as $field => $val) {
                if (!is_array($val)) continue;
                if (strpos($field, '___') !== false) continue; // legacy column-form
                $candidates[$field] = true;
            }
        }
        if (empty($candidates)) return [];
        try {
            $dd = \REDCap::getDataDictionary($pid, 'array', false, array_keys($candidates));
        } catch (\Exception $e) {
            return [];
        }
        $bases = [];
        foreach ($dd as $fname => $info) {
            if (($info['field_type'] ?? '') === 'checkbox') {
                $bases[$fname] = true;
            }
        }
        return $bases;
    }

    /**
     * True if a checkbox-shaped array value has any checked option.
     * Accepts both numeric-keyed arrays (REDCap checkbox shape) and
     * legacy `code => '0'|'1'` strings.
     */
    private function cappyRowHasCheckedCheckbox($val): bool
    {
        if (!is_array($val)) return false;
        foreach ($val as $flag) {
            if ((string)$flag !== '' && (string)$flag !== '0') return true;
        }
        return false;
    }

    /**
     * Tool 6d: records.aggregate
     * Compute a single aggregate statistic over one field, optionally filtered.
     * No raw rows returned — just the answer. Use this for "across the whole
     * dataset" questions (median, mode, mean) instead of fetching every row.
     *
     * Supported functions: count, sum, mean, median, mode, min, max, stddev.
     * For choice fields (radio/dropdown), the stored code is used numerically;
     * value_label resolves the result back to its human label when possible.
     */
    public function toolAggregateRecords(array $payload)
    {
        if (empty($payload['pid'])) {
            return ["error" => true, "message" => "Missing required parameter: pid"];
        }

        $pid = (int)$payload['pid'];
        $field = $payload['field'] ?? null;
        if (!$field) {
            return ["error" => true, "message" => "Missing required parameter: field"];
        }
        $function = strtolower($payload['function'] ?? '');
        $validFns = ['count', 'distinct_count', 'sum', 'mean', 'median', 'mode', 'min', 'max', 'stddev'];
        if (!in_array($function, $validFns, true)) {
            return ["error" => true, "message" => "Invalid function '$function'. Must be one of: " . implode(', ', $validFns)];
        }

        $filter = $payload['filter'] ?? null;

        if (!empty($filter)) {
            $fieldError = $this->cappyValidateFilterFields($pid, $filter);
            if ($fieldError !== null) return $fieldError;
        }
        $filterExpansions = [];
        $filterHints = [];
        if (!empty($filter)) {
            $expanded = $this->cappyExpandFilterLabels($pid, $filter);
            $filter = $expanded['filter'];
            $filterExpansions = $expanded['translations'];
            $filterHints = $expanded['hints'] ?? [];
        }

        try {
            // Determine field type so we can reject nonsense aggregations
            // (e.g. mean on a radio field whose codes are categorical).
            try {
                $dd = \REDCap::getDataDictionary($pid, 'array');
                $fieldType = strtolower((string)($dd[$field]['field_type'] ?? ''));
            } catch (\Exception $e) {
                $fieldType = '';
            }

            // Function allow-list by field type. Categorical radio/dropdown/yesno
            // codes get count/mode/median/min/max (median is meaningful for
            // ordinal scales like severity). When the LABELS themselves are
            // numeric ("1, 2, 3, 4, 5+" instead of "1, Mild, 2, Moderate"),
            // promote to full numeric support — mean of insertion attempts is
            // a sensible question. Agent can also force numeric with
            // treat_as_numeric=true.
            $treatAsNumeric = !empty($payload['treat_as_numeric']);
            // distinct_count is meaningful for EVERY field type — it counts
            // distinct stored values and never coerces anything to a number.
            $categoricalFns = ['count', 'distinct_count', 'mode', 'median', 'min', 'max'];
            $numericFns     = ['count', 'distinct_count', 'mode', 'median', 'min', 'max', 'sum', 'mean', 'stddev'];
            // Dates are stored Y-M-D by REDCap regardless of the field's DISPLAY
            // validation (date_mdy on this instance stores '2023-01-01'), so
            // lexical ordering is chronological and min/max are safe as STRINGS.
            // They are NOT safe as numbers: CAST('2023-01-01' AS DECIMAL) is 2023.
            $dateFns        = ['count', 'distinct_count', 'mode', 'min', 'max'];
            $textFreeFns    = ['count', 'distinct_count', 'mode'];

            // A 'text' field can hold a number, a date, or prose. Only the
            // validation type distinguishes them, and getting this wrong is not
            // a degraded answer but a fabricated one: before this check,
            // mean(pt_name) returned 0.0 over 1005 patient names, and
            // min(p2_admission_date) returned 2023 for '2023-01-01'.
            $validation = strtolower($this->cappyFieldValidation($dd[$field] ?? []));
            $isDateField = $validation !== '' && preg_match('/^date|^datetime/', $validation);
            if ($isDateField) {
                $textFns = $dateFns;
            } elseif ($validation !== '' && preg_match('/^(number|integer)/', $validation)) {
                $textFns = $numericFns;
            } else {
                // Unvalidated text is prose until proven otherwise.
                $textFns = $treatAsNumeric ? $numericFns : $textFreeFns;
            }

            // Evaluated once: the map below is built eagerly, so calling this
            // inline in both the radio and dropdown rows ran it twice on every
            // aggregate — including for fields that are neither.
            $codedAsNumeric = $treatAsNumeric || $this->cappyFieldHasNumericLabels($pid, $field);

            $allowed = [
                'radio'      => $codedAsNumeric ? $numericFns : $categoricalFns,
                'dropdown'   => $codedAsNumeric ? $numericFns : $categoricalFns,
                'yesno'      => $categoricalFns, // 0/1 with "Yes/No" labels — don't treat as continuous
                'checkbox'   => ['count', 'distinct_count', 'mode'],
                'text'       => $textFns,
                // REDCap's data dictionary calls a textarea 'notes'. The old
                // 'textarea' key never matched, so all 28 notes fields on a
                // typical project fell through to the default instead.
                'notes'      => $textFreeFns,
                'calc'       => $numericFns,
                'descriptive'=> ['count'],
                'file'       => ['count'],
                'sql'        => $numericFns,
                'slider'     => $numericFns,
            ];
            // Default — unknown / missing type — allow the type-agnostic set.
            $effectiveAllowed = $allowed[$fieldType] ?? $textFreeFns;
            if (!in_array($function, $effectiveAllowed, true)) {
                // Say WHY per actual field shape. The old text claimed "stored
                // codes are categorical" for every type, which is nonsense for a
                // free-text or date field and teaches the model the wrong lesson
                // about what it just did.
                if ($fieldType === '') {
                    $why = "this field's type could not be determined";
                } elseif ($isDateField) {
                    $why = "'$field' holds dates ($validation); use min/max for earliest/latest, not arithmetic";
                } elseif (in_array($fieldType, ['text', 'notes'], true)) {
                    $why = "'$field' holds free text with no numeric validation, so arithmetic on it would be meaningless"
                         . " (pass treat_as_numeric=true only if you know the values really are numbers)";
                } else {
                    $why = "$fieldType values are categorical codes, not continuous numbers";
                }
                $reason = "Function '$function' cannot be applied here: $why."
                    . " Allowed functions for this field: " . implode(', ', $effectiveAllowed) . ".";
                return [
                    "error" => true,
                    "message" => $reason,
                    "field_type" => $fieldType ?: null,
                    "allowed_functions" => $effectiveAllowed,
                ];
            }

            // FAST PATH: direct SQL on redcap_data for the common case — single
            // field, no filter, non-checkbox. Sub-millisecond on 1000 records
            // vs ~50ms via REDCap::getData (which pulls full row objects).
            // Skip fast path for checkbox (checkbox codes live as field___N in
            // redcap_data and need the getData array form) and for any filter
            // (we don't translate REDCap logic to SQL — getData does it correctly).
            // Also skip when the project has Data Access Groups — direct SQL
            // would compute aggregates over records the caller cannot view,
            // bypassing REDCap::getData's automatic DAG scoping.
            $useFastPath = empty($filter)
                && $fieldType !== 'checkbox'
                && !$this->cappyProjectHasDAGs($pid);
            $n = 0;
            $recordCount = 0;
            $values = null; // only used by slow path
            $sqlResult = null;
            if ($useFastPath) {
                $sqlResult = $this->cappySqlAggregate($pid, $field, $function, $isDateField);
            }

            if ($sqlResult !== null) {
                $n = $sqlResult['n'];
                $recordCount = $sqlResult['record_count'];
            } else {
                // Slow path — fall back to REDCap::getData for checkbox / filtered.
                $data = \REDCap::getData(
                    $pid, 'array',
                    null,            // records (all)
                    [$field],        // narrow to the field of interest
                    null,            // events
                    null,            // groups
                    false,           // combine checkbox values
                    false, false,    // DAG, survey fields
                    $filter
                );
                $values = [];
                $recordsContributing = [];
                foreach ($this->cappyFlattenRows($data) as $row) {
                    [$rid, $fields] = $row;
                    $recordsContributing[$rid] = true;
                    $v = $fields[$field] ?? null;
                    if ($v === null || $v === '') continue;
                    if (is_array($v)) {
                        foreach ($v as $code => $val) {
                            if ($val === '1' || $val === 1 || $val === true) {
                                $values[] = (string)$code;
                            }
                        }
                    } else {
                        $values[] = (string)$v;
                    }
                }
                $n = count($values);
                $recordCount = count($recordsContributing);
            }

            $result = [
                "pid" => $pid,
                "field" => $field,
                "field_type" => $fieldType ?: null,
                "function" => $function,
                "value_count" => $n,
                "record_count" => $recordCount,
                "filter" => $filter,
                "filter_translations" => $filterExpansions,
                // Present only when a filter literal is not a choice of the
                // field it was compared against but IS a choice elsewhere —
                // turns a true-but-useless 0 into a legible wrong-field hint.
                "value_not_on_this_field" => $filterHints,
                "source" => $sqlResult !== null ? "sql" : "getdata",
            ];

            if ($n === 0) {
                $result["value"] = null;
                $result["note"] = "No non-empty values found for this field/filter.";
                return $result;
            }

            switch ($function) {
                case 'count':
                    $result["value"] = $n;
                    break;
                case 'distinct_count':
                    $result["value"] = $sqlResult !== null
                        ? $sqlResult['distinct_count']
                        : count(array_unique($values));
                    // Say WHAT was counted. On a checkbox this is the number of
                    // distinct options ever ticked across the cohort — which can
                    // equal the whole option list and is not "options per
                    // record". Naming it stops that being read as a per-person
                    // figure.
                    $result["note"] = $fieldType === 'checkbox'
                        ? "Number of distinct options checked by at least one record (not options per record)."
                        : "Number of distinct stored values across the matching records.";
                    break;
                case 'min':
                case 'max':
                    if ($isDateField) {
                        // String comparison — see the allow-list note above.
                        if ($sqlResult !== null && isset($sqlResult[$function])) {
                            $v = $sqlResult[$function];
                        } else {
                            $strs = array_map('strval', $values);
                            $v = $function === 'min' ? min($strs) : max($strs);
                        }
                        $result["value"] = $v;
                        break;
                    }
                    // fall through to the numeric handling below
                case 'sum':
                case 'mean':
                case 'median':
                case 'stddev':
                    if ($sqlResult !== null && isset($sqlResult[$function])) {
                        $v = $sqlResult[$function];
                    } else {
                        // Slow path: compute from $values in PHP.
                        $numeric = array_map('floatval', $values);
                        switch ($function) {
                            case 'sum':   $v = array_sum($numeric); break;
                            case 'mean':  $v = array_sum($numeric) / $n; break;
                            case 'median':
                                sort($numeric);
                                $mid = intdiv($n, 2);
                                $v = ($n % 2 === 0)
                                    ? ($numeric[$mid - 1] + $numeric[$mid]) / 2
                                    : $numeric[$mid];
                                break;
                            case 'min':   $v = min($numeric); break;
                            case 'max':   $v = max($numeric); break;
                            case 'stddev':
                                $mean = array_sum($numeric) / $n;
                                $sq = 0.0;
                                foreach ($numeric as $x) $sq += ($x - $mean) ** 2;
                                // Sample stddev (n-1) — standard for descriptive stats.
                                $v = sqrt($sq / max(1, $n - 1));
                                break;
                        }
                    }
                    $result["value"] = $v;
                    $result["value_label"] = $this->cappyResolveValueLabel($pid, $field, (string)$v);
                    break;
                case 'mode':
                    if ($sqlResult !== null) {
                        $top = $sqlResult['mode'];
                        $result["value"] = $top;
                        $result["frequency"] = $sqlResult['mode_count'];
                    } else {
                        $counts = array_count_values($values);
                        arsort($counts);
                        $top = array_keys($counts)[0];
                        $result["value"] = $top;
                        $result["frequency"] = $counts[$top];
                    }
                    $result["value_label"] = $this->cappyResolveValueLabel($pid, $field, $top);
                    break;
            }

            return $result;
        } catch (\Exception $e) {
            $this->emError("aggregateRecords error for pid $pid: " . $e->getMessage());
            return ["error" => true, "message" => "Failed to aggregate: " . $e->getMessage()];
        }
    }

    /**
     * Pull a field's validation type ('date_mdy', 'number', 'time', …) out of an
     * already-loaded data-dictionary row. '' when it has none.
     *
     * This is the ONLY thing distinguishing a text field holding a number from
     * one holding a date from one holding prose, and aggregate's correctness
     * depends on it: treating prose as numeric produced mean(pt_name) = 0.0
     * over 1005 patient names.
     *
     * Takes the row rather than (pid, field) deliberately — every caller already
     * has the dictionary in hand, and looking it up again here cost a second
     * getDataDictionary() call per aggregate.
     */
    private function cappyFieldValidation(array $fieldInfo): string
    {
        return (string)($fieldInfo['text_validation_type_or_show_slider_number']
            ?? ($fieldInfo['element_validation_type'] ?? ''));
    }

    /**
     * Look up the human-readable label for a stored CODE value on a choice
     * field. Returns null for numeric/text fields or unknown codes.
     */
    private function cappyResolveValueLabel(int $pid, string $field, string $codeValue): ?string
    {
        $map = $this->cappyBuildLabelMap($pid);
        if (!isset($map['fields'][$field])) return null;
        $fieldMap = $map['fields'][$field];
        $label = array_search($codeValue, $fieldMap, true);
        return $label !== false ? $label : null;
    }

    /**
     * Detect whether a radio/dropdown field's LABELS are numeric — e.g.
     * "1, 1 | 2, 2 | 3, 3 | 4, 4 | 5, 5+" — so we can promote it from
     * "categorical" to "numeric-scale" and allow sum/mean/stddev. Returns
     * true if at least half the labels parse as numbers (after stripping
     * range suffixes like "+").
     */
    private function cappyFieldHasNumericLabels(int $pid, string $field): bool
    {
        $map = $this->cappyBuildLabelMap($pid);
        if (!isset($map['fields'][$field]) || empty($map['fields'][$field])) {
            return false;
        }
        $labels = array_keys($map['fields'][$field]);
        $numeric = 0;
        foreach ($labels as $label) {
            // Strip range suffixes ("5+" → "5", "10+" → "10") then check.
            $cleaned = preg_replace('/\++\s*$/', '', (string)$label);
            if ($cleaned !== '' && is_numeric($cleaned)) {
                $numeric++;
            }
        }
        return $numeric >= max(1, (int)ceil(count($labels) / 2));
    }

    /**
     * Return true when the project has Data Access Groups configured. Used to
     * gate the SQL fast path — direct SQL would skip REDCap::getData's
     * automatic DAG scoping and compute aggregates over records the caller
     * cannot view. Projects with DAGs fall back to the (slower) getData path.
     */
    /**
     * Return true when the project has Data Access Groups configured. Used to
     * gate the SQL fast path — direct SQL would skip REDCap::getData's
     * automatic DAG scoping and compute aggregates over records the caller
     * cannot view. Projects with DAGs fall back to the (slower) getData path.
     */
    private function cappyProjectHasDAGs(int $pid): bool
    {
        try {
            $q = db_query(
                "SELECT COUNT(*) AS c FROM redcap_data_access_groups WHERE project_id = ?",
                [(int)$pid]
            );
            if (!$q) return false;
            $row = db_fetch_assoc($q);
            return (int)($row['c'] ?? 0) > 0;
        } catch (\Exception $e) {
            // Fail-closed: if we can't determine, assume DAGs may exist and
            // use the slow path so REDCap's own scoping applies.
            $this->emError("cappyProjectHasDAGs failed for pid={$pid}: " . $e->getMessage());
            return true;
        }
    }

    /**
     * Fast-path aggregate over the project's data table via direct SQL.
     *
     * Skips REDCap::getData (which loads full row objects and runs through
     * PHI / DAG / branching hooks) — for simple "across the whole dataset"
     * stats this is ~50-100x faster on projects with 1000+ records.
     *
     * Caller MUST already have gated to non-checkbox fields with no REDCap
     * logic filter (we don't translate the logic expression here) and
     * confirmed the project has no DAGs (via cappyProjectHasDAGs).
     *
     * All queries use bound parameters — nothing is interpolated into SQL.
     * Table name comes from REDCap's \Records::getDataTable() whitelist.
     *
     * Returns:
     *   [
     *     'n'           => int,         // non-empty value count
     *     'record_count'=> int,         // distinct records
     *     'sum'         => float|null,  // when function requires
     *     'mean'        => float|null,
     *     'min'         => float|null,
     *     'max'         => float|null,
     *     'stddev'      => float|null,  // sample stddev (n-1)
     *     'median'      => float|null,
     *     'mode'        => string|null, // most common value
     *     'mode_count'  => int|null,
     *   ]
     * Returns null on any SQL failure (caller should fall back to getData).
     */
    private function cappySqlAggregate(int $pid, string $field, string $function, bool $asString = false): ?array
    {
        try {
            // REDCap moves large projects to per-project tables (redcap_dataN).
            // \Records::getDataTable returns the right one for this project.
            $table = \Records::getDataTable((int)$pid);
            $pidI = (int)$pid;
            $fieldE = (string)$field;

            // Numeric aggregates in one query: count + sum/mean/min/max/stddev.
            // CAST(value AS DECIMAL(20,6)) coerces REDCap's text storage to a
            // numeric type; non-numeric strings evaluate to 0 (acceptable —
            // they're data-entry errors the user already sees as warnings).
            $aggSql = "SELECT
                        COUNT(*)                                  AS n,
                        COUNT(DISTINCT record)                    AS record_count,
                        COUNT(DISTINCT value)                     AS dc,
                        SUM(CAST(value AS DECIMAL(20,6)))         AS s,
                        AVG(CAST(value AS DECIMAL(20,6)))         AS m,
                        MIN(CAST(value AS DECIMAL(20,6)))         AS mn,
                        MAX(CAST(value AS DECIMAL(20,6)))         AS mx,
                        MIN(value)                                AS mn_s,
                        MAX(value)                                AS mx_s,
                        STDDEV_SAMP(CAST(value AS DECIMAL(20,6))) AS sd
                    FROM {$table}
                    WHERE project_id = ?
                      AND field_name = ?
                      AND value != ''";
            $q = db_query($aggSql, [$pidI, $fieldE]);
            if (!$q) return null;
            $row = db_fetch_assoc($q);
            if (!$row) return null;

            $n = (int)$row['n'];
            if ($n === 0) {
                return ['n' => 0, 'record_count' => 0];
            }
            $out = [
                'n'              => $n,
                'record_count'   => (int)$row['record_count'],
                'distinct_count' => (int)$row['dc'],
            ];

            if ($function === 'count' || $function === 'distinct_count') {
                // already in $out; caller uses those.
            } elseif ($asString && ($function === 'min' || $function === 'max')) {
                // Date/datetime fields: compare as strings. REDCap stores them
                // Y-M-D so lexical order is chronological, whereas the numeric
                // cast would turn '2023-01-01' into 2023.
                $key = $function === 'min' ? 'mn_s' : 'mx_s';
                if ($row[$key] !== null) {
                    $out[$function] = (string)$row[$key];
                }
            } elseif (in_array($function, ['sum', 'mean', 'min', 'max', 'stddev'], true)) {
                $map = ['sum' => 's', 'mean' => 'm', 'min' => 'mn', 'max' => 'mx', 'stddev' => 'sd'];
                if ($row[$map[$function]] !== null) {
                    $out[$function] = (float)$row[$map[$function]];
                }
            } elseif ($function === 'median') {
                // MySQL has no MEDIAN(). Pull the sorted values once.
                $valSql = "SELECT CAST(value AS DECIMAL(20,6)) AS v
                           FROM {$table}
                           WHERE project_id = ?
                             AND field_name = ?
                             AND value != ''
                           ORDER BY v";
                $vq = db_query($valSql, [$pidI, $fieldE]);
                if (!$vq) return null;
                $vals = [];
                while ($vr = db_fetch_assoc($vq)) {
                    $vals[] = (float)$vr['v'];
                }
                $mid = intdiv($n, 2);
                $out['median'] = ($n % 2 === 0)
                    ? ($vals[$mid - 1] + $vals[$mid]) / 2
                    : $vals[$mid];
            } elseif ($function === 'mode') {
                $modeSql = "SELECT value, COUNT(*) AS c
                            FROM {$table}
                            WHERE project_id = ?
                              AND field_name = ?
                              AND value != ''
                            GROUP BY value
                            ORDER BY c DESC, value ASC
                            LIMIT 1";
                $mq = db_query($modeSql, [$pidI, $fieldE]);
                if (!$mq) return null;
                $mr = db_fetch_assoc($mq);
                if (!$mr) return null;
                $out['mode'] = (string)$mr['value'];
                $out['mode_count'] = (int)$mr['c'];
            }
            return $out;
        } catch (\Exception $e) {
            $this->emError("cappySqlAggregate failed for pid={$pid} field={$field}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract bare field names referenced in a REDCap logic filter.
     */
    private function cappyExtractFilterFields(string $filter): array
    {
        $fields = [];
        if (preg_match_all('/\[([^\[\]]+)\]/', $filter, $m)) {
            foreach ($m[1] as $token) {
                $token = preg_replace('/[:(\[].*$/', '', $token);
                if (strpos($token, '.') !== false) {
                    $parts = explode('.', $token);
                    $token = end($parts);
                }
                $token = trim($token);
                if ($token !== '' && preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $token)) {
                    $fields[$token] = true;
                }
            }
        }
        return array_keys($fields);
    }

    /**
     * Build a per-project label↔code map for every choice field, cached on
     * disk with a dictionary signature so data-dictionary edits auto-invalidate.
     *
     * Returns:
     *   [
     *     'fields' => [ field_name => [ label_lower => code, ... ], ... ],
     *     'by_label' => [ label_lower => [ fields_with_that_label, ... ] ],
     *     'checkbox' => [ field_name => true, ... ],
     *   ]
     *
     * Only includes fields with parseable choices (radio/dropdown/yesno/checkbox).
     * Fields with no `element_enum` are skipped — free text can't be translated.
     *
     * 'checkbox' matters because checkbox fields need a DIFFERENT logic syntax
     * from every other choice field — see cappyExpandFilterLabels().
     */
    private function cappyBuildLabelMap(int $pid): array
    {
        try {
            $dd = \REDCap::getDataDictionary($pid, 'array');
        } catch (\Exception $e) {
            return ['fields' => [], 'by_label' => [], 'checkbox' => []];
        }
        if (empty($dd)) return ['fields' => [], 'by_label' => [], 'checkbox' => []];

        // Signature includes the relevant parts of the dictionary so any edit
        // that could change a label/code pair forces a fresh parse. field_type
        // is in here too: flipping a field radio->checkbox changes the logic
        // syntax we must emit without necessarily changing its choices.
        $sigInput = '';
        foreach ($dd as $name => $info) {
            $sigInput .= $name . "\t" . ($info['field_type'] ?? '')
                . "\t" . ($info['select_choices_or_calculations'] ?? '') . "\n";
        }
        $signature = substr(md5($sigInput), 0, 12);
        // BUMP THIS VERSION whenever the payload shape or the parsing rules
        // change. The signature only covers dictionary CONTENT, so an older
        // cache file for an unchanged dictionary would still look valid and
        // silently serve a map built by the previous rules.
        //   v2: added the 'checkbox' key
        //   v3: yesno/truefalse fields gained implicit label pairs
        $cacheFile = sys_get_temp_dir() . "/redcap_label_map_v3_{$pid}_{$signature}.json";
        $cacheTtl  = 3600;
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
            $cached = @json_decode(@file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['fields'], $cached['checkbox'])) return $cached;
        }

        $fields = [];
        $byLabel = [];
        $checkbox = [];
        foreach ($dd as $name => $info) {
            $type = $info['field_type'] ?? '';
            $enum = (string)($info['select_choices_or_calculations'] ?? '');
            // yesno/truefalse are coded fields that carry NO element_enum, so
            // the $enum === '' skip below used to drop them entirely — and
            // "Yes" is about the most natural literal a caller can write.
            // [workflow_challenges] = "Yes" silently matched 0 records when the
            // true answer was 499. Their labels are implicit in the type.
            if ($type === 'yesno') {
                $pairs = ['yes' => '1', 'no' => '0'];
            } elseif ($type === 'truefalse') {
                $pairs = ['true' => '1', 'false' => '0'];
            } elseif ($enum === '') {
                continue;
            } else {
                // REDCap stores element_enum with literal "\n" between options
                // (and historically "|" in some installs). Split on either, then
                // collapse any actual whitespace so labels normalize cleanly.
                $pairs = [];
                foreach (preg_split('/\\\\n|\|/', $enum) as $opt) {
                    $opt = trim(preg_replace('/\s+/', ' ', $opt));
                    if ($opt === '' || strpos($opt, ',') === false) continue;
                    [$code, $label] = explode(',', $opt, 2);
                    $code  = trim($code);
                    $label = trim($label);
                    if ($code === '' || $label === '') continue;
                    $pairs[strtolower($label)] = $code;
                }
            }
            if (empty($pairs)) continue;
            $fields[$name] = $pairs;
            if ($type === 'checkbox') {
                $checkbox[$name] = true;
            }
            foreach (array_keys($pairs) as $labelLower) {
                $byLabel[$labelLower][] = $name;
            }
        }

        $out = ['fields' => $fields, 'by_label' => $byLabel, 'checkbox' => $checkbox];
        @file_put_contents($cacheFile, json_encode($out));
        return $out;
    }

    /**
     * Expand REDCap logic filter literals that are choice labels into an OR
     * with the underlying code, so:
     *   [d_legal_sex] = "Female"
     * becomes:
     *   ([d_legal_sex] = "2" or [d_legal_sex] = "Female")
     *
     * Rules:
     *  - Only quoted string literals are considered (numeric comparisons ignored).
     *  - The literal must be a unique label for that field (one field, one code).
     *  - Already-a-code literals are skipped (dedupe — nerd path is a no-op).
     *  - Ambiguous labels (same label in 2+ fields) are NOT translated — the
     *    match is bound to a single field, so ambiguity across fields is moot,
     *    but duplicate (label → code) pairs WITHIN one field are skipped.
     *  - Wrapped in parens so AND/OR precedence is preserved in complex filters.
     *
     * Returns:
     *   ['filter' => expanded_filter_string, 'translations' => [ {field,op,from,to}, ... ]]
     */
    private function cappyExpandFilterLabels(int $pid, string $filter): array
    {
        $translations = [];
        $hints = [];
        if (trim($filter) === '') {
            return ['filter' => $filter, 'translations' => $translations, 'hints' => $hints];
        }

        $map = $this->cappyBuildLabelMap($pid);
        if (empty($map['fields'])) {
            return ['filter' => $filter, 'translations' => $translations, 'hints' => $hints];
        }

        // Match [field] op "value"  — op is one of = != <> >= <= > <
        $pattern = '/\[([a-zA-Z][a-zA-Z0-9_]*)\]\s*(<>|!=|>=|<=|=|>|<)\s*("[^"]*"|\'[^\']*\')/';
        if (!preg_match_all($pattern, $filter, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return ['filter' => $filter, 'translations' => $translations, 'hints' => $hints];
        }

        // Walk right-to-left so byte offsets remain valid as we splice in parens.
        for ($i = count($matches) - 1; $i >= 0; $i--) {
            $m = $matches[$i];
            $field = $m[1][0];
            $op    = $m[2][0];
            $raw   = $m[3][0]; // includes the surrounding quotes
            $offset = $m[0][1];
            $length = strlen($m[0][0]);

            // Strip outer quotes.
            $literal = substr($raw, 1, -1);
            if ($literal === '') continue;
            $literalLower = strtolower($literal);

            // Only act if this field has a parseable label map.
            if (!isset($map['fields'][$field])) continue;
            $fieldMap = $map['fields'][$field];
            $isCheckbox = !empty($map['checkbox'][$field]);

            // Resolve the literal to a code. It's either already a code, or a
            // label we can look up.
            $literalIsCode = in_array($literal, $fieldMap, true);
            if ($literalIsCode) {
                $code = $literal;
            } elseif (isset($fieldMap[$literalLower])) {
                $code = $fieldMap[$literalLower];
                // Duplicate (label → code) within this field? If two different
                // labels collapse to the same code (rare in REDCap data), skip
                // rather than guess which one the agent meant.
                if (count(array_keys($fieldMap, $code, true)) > 1) continue;
            } else {
                // Not a label or code for this field. Before giving up, check
                // whether it IS a choice on some OTHER field — that is the
                // difference between "0 records have this" and "you asked the
                // wrong field". Observed: [roles] = "Unit Secretary" returned a
                // true-but-useless 0 because the value lives elsewhere.
                // (This is what the by_label index was built for; it had never
                // been read by anything.)
                $elsewhere = $map['by_label'][$literalLower] ?? [];
                $elsewhere = array_values(array_diff($elsewhere, [$field]));
                if (!empty($elsewhere)) {
                    $hints[] = [
                        'field'            => $field,
                        'literal'          => $literal,
                        'not_a_choice_for' => $field,
                        'is_a_choice_for'  => array_slice($elsewhere, 0, 3),
                    ];
                }
                continue;
            }

            if ($isCheckbox) {
                // CHECKBOX FIELDS USE A DIFFERENT LOGIC SYNTAX. REDCap stores
                // each option as its own column, so membership is expressed as
                //   [field(code)] = "1"
                // An equality test against the BASE field name — which is what
                // the else-branch below produces, and what this function used to
                // emit for every field type — matches NOTHING. Silently. That
                // turned "how many females speak Spanish?" into a confident
                // "0" when the real answer was 86: the filter was accepted, a
                // filter_translations entry claimed success, and zero records
                // came back. A wrong answer that looks like a right one.
                if ($op === '=') {
                    $checked = '1';
                } elseif ($op === '!=' || $op === '<>') {
                    $checked = '0'; // "not Spanish" = that option is unchecked
                } else {
                    // >, <, >=, <= against one checkbox option is meaningless.
                    // Leave the filter untouched rather than invent a reading.
                    continue;
                }
                $expanded = "[{$field}({$code})] = \"{$checked}\"";
                $to       = "{$field}({$code}) = \"{$checked}\"";
            } else {
                // Non-checkbox: OR the code with the original literal so both
                // the code path and the label path match.
                if ($literalIsCode) continue; // already a code — no-op
                $expanded = "([{$field}] {$op} \"{$code}\" or [{$field}] {$op} {$raw})";
                $to = "({$field} {$op} \"{$code}\" or {$field} {$op} \"{$literal}\")";
            }

            $filter = substr($filter, 0, $offset) . $expanded . substr($filter, $offset + $length);
            $translations[] = [
                'field' => $field,
                'op'    => $op,
                'from'  => $literal,
                'to'    => $to,
            ];
        }

        return ['filter' => $filter, 'translations' => $translations, 'hints' => $hints];
    }

    /**
     * Apply a NEW REDCap logic filter against a cached recordset in memory —
     * no getData round trip. Uses REDCap::evaluateLogic with the cached row
     * passed in as $record_data.
     *
     * If the filter references fields that aren't present in the cached rows
     * (e.g. the original search used a narrow 'fields' list), falls back to a
     * single getData scoped to the cached record IDs (still avoids a
     * full-project scan).
     *
     * @param int    $pid
     * @param array  $data   Cached getData('array') result: [record => [event => [field => value]]]
     * @param string $filter REDCap logic expression
     * @return array Filtered subset, same shape as $data
     */
    private function cappyFilterCachedRecords(int $pid, array $data, string $filter): array
    {
        if (empty($data) || trim($filter) === '') {
            return $data;
        }

        // Collect field names referenced by the filter: [field], [event][field],
        // [field:value], [field(code)] — normalize to bare field names.
        $referenced = array_fill_keys($this->cappyExtractFilterFields($filter), true);

        // Check the cached rows actually contain every referenced field.
        // Collect from base event rows AND repeating-instance rows.
        $available = [];
        foreach (array_slice($this->cappyFlattenRows($data), 0, 5) as [, $fields]) {
            foreach (array_keys($fields) as $fieldName) {
                $available[$fieldName] = true;
            }
        }
        $missing = array_diff_key($referenced, $available);

        if (!empty($missing)) {
            // Fallback: scoped getData limited to the cached record IDs.
            $this->emDebug("cappyFilterCachedRecords: fields missing from cache, scoped re-query", [
                'pid' => $pid,
                'missing_fields' => array_keys($missing),
            ]);
            $scoped = \REDCap::getData(
                $pid,
                'array',
                array_keys($data), // only records in the cached set
                null, null, null, false, false, false,
                $filter
            );
            return is_array($scoped) ? $scoped : [];
        }

        // Large-set guard: REDCap::evaluateLogic constructs a Project object
        // per call — fine for a few dozen records, object churn for hundreds.
        // Past the threshold, one scoped getData (single SQL query limited to
        // the cached record IDs) beats N per-record evaluations.
        if (count($data) > self::CAPPY_FILTER_INLINE_MAX) {
            $this->emDebug("cappyFilterCachedRecords: set too large for in-memory filter, scoped re-query", [
                'pid' => $pid,
                'cached_count' => count($data),
            ]);
            $scoped = \REDCap::getData(
                $pid,
                'array',
                array_keys($data), // only records in the cached set
                null, null, null, false, false, false,
                $filter
            );
            return is_array($scoped) ? $scoped : [];
        }

        // In-memory path: evaluate the logic per record against the cached row.
        $subset = [];
        foreach ($data as $recordId => $row) {
            try {
                $res = \REDCap::evaluateLogic(
                    $filter,
                    $pid,
                    $recordId,
                    null,           // event_name
                    1,              // repeat_instance
                    '',             // repeat_instrument
                    '',             // current_context_instrument
                    [$recordId => $row], // record_data — inline, no DB hit
                    false,          // returnValue
                    false           // checkRecordExists (skip DB existence check)
                );
                if ($res === true) {
                    $subset[$recordId] = $row;
                }
            } catch (\Exception $e) {
                $this->emError("cappyFilterCachedRecords: evaluateLogic failed for record $recordId in pid $pid: " . $e->getMessage());
            }
        }
        return $subset;
    }

    // -----------------------------------------------------------------
    // File-backed cache for large tool results. The LLM gets a short reference
    // id; the full data lives in a per-session cache FILE for the lifetime of
    // the session (default 30 min, configurable below). Survives iframe
    // reloads, full page reloads, and any same-session navigation.
    //
    // Why a file and not $_SESSION: the chatbot agent loop can run for tens of
    // seconds (multiple LLM round-trips + tool calls). PHP holds an EXCLUSIVE
    // lock on the session file for the whole request, so keeping the cache in
    // $_SESSION forced every concurrent tab (they share one session cookie) to
    // serialize behind that loop — surfacing to users as "timeout" toasts.
    // Keeping the cache OUT of $_SESSION lets the chatbot release the session
    // write-lock (session_write_close) for the duration of the loop. Same
    // single-host locality assumption as the previous $_SESSION-file approach.
    // -----------------------------------------------------------------

    /** TTL for cached tool results, in seconds. 30 min — long enough for a
     *  multi-turn analysis conversation to keep reusing the same recordset. */
    const CAPPY_CACHE_TTL = 1800;

    /** Max records to filter in memory via per-record evaluateLogic. Past
     *  this, the per-call Project construction outweighs one scoped query. */
    const CAPPY_FILTER_INLINE_MAX = 200;

    /** Directory holding the file-backed tool-result caches. Created 0700 on
     *  first use. Lives under the system temp dir — same PHI posture as PHP's
     *  own session files, which already serialize this exact recordset data. */
    private function cappyCacheDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cappy_data_cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir;
    }

    /** Current PHP session id, or '' when there is no session (e.g. a pure
     *  token-API request). session_id() stays readable after
     *  session_write_close(), so the normal Cappy browser flow always has one. */
    private function cappyCurrentSid(): string
    {
        return session_id() ?: '';
    }

    /** Absolute path for a (session, ref) pair. Session-scoped via the PHP
     *  session id so one user can never read another's cached recordset even
     *  if a ref id leaks. */
    private function cappyCachePath(string $ref, string $sid): string
    {
        return $this->cappyCacheDir() . DIRECTORY_SEPARATOR
            . hash('sha256', $sid . '|' . $ref) . '.cache';
    }

    /**
     * Stash $payload in a per-session cache file under a short random reference
     * id and return that reference. Caller passes the reference back to
     * retrieve the data (via cappyCacheFetch). Opportunistically prunes stale
     * files.
     *
     * When there is no PHP session (a stateless token-API request), the data is
     * NOT persisted: a session-less caller can't be isolated from other
     * session-less callers (they'd share one namespace) and can't paginate
     * across requests anyway. It still gets a ref back; a later fetch simply
     * misses and the tool re-runs. This matches the pre-file ($_SESSION)
     * behavior, where a session-less request had no persistent cache.
     */
    private function cappyCacheStore(string $key, $payload): string
    {
        $now = time();
        $this->cappyCachePrune($now);

        $ref = 'ref_' . bin2hex(random_bytes(4));
        $sid = $this->cappyCurrentSid();
        if ($sid === '') {
            return $ref; // no session → don't persist (see docblock)
        }

        $entry = [
            'tool'       => $key,
            'sid'        => $sid,
            'data'       => $payload,
            'expires_at' => $now + self::CAPPY_CACHE_TTL,
            'stored_at'  => $now,
        ];

        // serialize() (not json_encode) to round-trip PHP types faithfully and
        // survive any non-UTF-8 bytes in record data. Atomic publish via
        // temp-file + rename so a concurrent reader never sees a partial write.
        $path = $this->cappyCachePath($ref, $sid);
        $tmp  = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, serialize($entry), LOCK_EX) !== false) {
            @chmod($tmp, 0600);
            if (!@rename($tmp, $path)) {
                @unlink($tmp); // don't leak the temp file if the publish failed
            }
        }
        return $ref;
    }

    /**
     * Delete expired cache files and orphaned temp files. Opportunistic: to
     * avoid globbing the shared cache dir on every single tool call under
     * concurrent multi-user load, a full sweep runs only ~1-in-10 calls.
     */
    private function cappyCachePrune(int $now): void
    {
        if (random_int(1, 10) !== 1) {
            return;
        }
        $dir = $this->cappyCacheDir();

        // Expired published entries. mtime ≈ stored_at, so mtime older than the
        // TTL ⟺ the entry has expired.
        $files = @glob($dir . DIRECTORY_SEPARATOR . '*.cache');
        if (is_array($files)) {
            foreach ($files as $f) {
                if (@filemtime($f) < $now - self::CAPPY_CACHE_TTL) {
                    @unlink($f);
                }
            }
        }

        // Orphaned temp files from a crash or failed rename between write and
        // publish. A live .tmp exists for microseconds, so anything older than a
        // minute is dead. The *.cache sweep above never matches these, so
        // without this they would accumulate forever.
        $tmps = @glob($dir . DIRECTORY_SEPARATOR . '*.tmp');
        if (is_array($tmps)) {
            foreach ($tmps as $t) {
                if (@filemtime($t) < $now - 60) {
                    @unlink($t);
                }
            }
        }
    }

    /**
     * Fetch a cached payload by reference. Returns null if missing, expired,
     * or (when $expectedKey is given) cached by a different tool.
     */
    private function cappyCacheFetch(string $ref, ?string $expectedKey = null)
    {
        // Reject anything that isn't our own ref format before touching the FS.
        if (!preg_match('/^ref_[0-9a-f]{8}$/', $ref)) {
            return null;
        }
        // No session → nothing was persisted for us to read (see cappyCacheStore).
        $sid = $this->cappyCurrentSid();
        if ($sid === '') {
            return null;
        }
        $path = $this->cappyCachePath($ref, $sid);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        // allowed_classes=false: never instantiate objects from cache files.
        $entry = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($entry)) {
            @unlink($path);
            return null;
        }
        if (($entry['expires_at'] ?? 0) < time()) {
            @unlink($path);
            return null;
        }
        // Defense-in-depth: the file name is already session-scoped, but verify
        // the stored session id matches too.
        if (($entry['sid'] ?? null) !== $sid) {
            return null;
        }
        if ($expectedKey !== null && ($entry['tool'] ?? null) !== $expectedKey) {
            return null;
        }
        return $entry['data'];
    }
}
