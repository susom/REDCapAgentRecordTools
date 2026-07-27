<?php
namespace Stanford\REDCapAgentRecordTools;

require_once "emLoggerTrait.php";
require_once "classes/PhiFieldPreHook.php";

class REDCapAgentRecordTools extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    // No hard cap on records.search results — REDCap itself doesn't cap, and
    // the full result set is always cached server-side regardless. This is
    // just the DEFAULT page size; past ~1000 records the response notes steer
    // the agent toward suggesting the user narrow their filter.
    const MAX_RECORDS_RETURNED = 1000;

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
     */
    public function redcap_module_api($action = null, $payload = [])
    {
        $this->emDebug("Agent tool call", [
            'action' => $action,
            'payload' => $payload
        ]);

        $response = $this->routeToolCall($action, $payload);

        // Log the response (truncated) so we can verify what the LLM actually
        // received — e.g. that preview_markdown survived serialization.
        $this->emDebug("Agent tool response", [
            'action' => $action,
            'response_json' => substr((string)json_encode($response, JSON_UNESCAPED_UNICODE), 0, 8000),
        ]);

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

            case "records_listIds":
                return $this->toolListRecordIds($payload);

            case "survey_getLink":
                return $this->toolGetSurveyLink($payload);

            case "records_evaluateLogic":
                return $this->toolEvaluateLogic($payload);

            case "projects_search":
                return $this->toolSearchProjects($payload);

            case "records_save":
                return $this->toolSaveRecords($payload);

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

        try {
            // Get full data dictionary
            $metadata = \REDCap::getDataDictionary($pid, 'array', false, $fields);

            if (empty($metadata)) {
                return [
                    "error" => true,
                    "message" => "No metadata found for project $pid (may not exist or no access)"
                ];
            }

            // Convert to array of field objects for easier agent consumption
            $fields_array = [];
            foreach ($metadata as $field_name => $field_info) {
                $fields_array[] = [
                    'field_name' => $field_name,
                    'form_name' => $field_info['form_name'] ?? null,
                    'field_type' => $field_info['field_type'] ?? null,
                    'field_label' => $field_info['field_label'] ?? null,
                    'select_choices_or_calculations' => $field_info['select_choices_or_calculations'] ?? null,
                    'required_field' => $field_info['required_field'] ?? null,
                    'text_validation_type_or_show_slider_number' => $field_info['text_validation_type_or_show_slider_number'] ?? null,
                    'branching_logic' => $field_info['branching_logic'] ?? null,
                ];
            }

            return [
                "pid" => $pid,
                "field_count" => count($fields_array),
                "fields" => $fields_array
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
     * Get specific record data by record ID
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

        try {
            $data = \REDCap::getData($pid, 'array', [$record_id], $fields, $events);

            if (empty($data)) {
                return [
                    "error" => true,
                    "message" => "No data found for record '$record_id' in project $pid"
                ];
            }

            return [
                "pid" => $pid,
                "record_id" => $record_id,
                "data" => $data
            ];
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
                    . "IMPORTANT: render 'preview_markdown' to the user VERBATIM as a markdown table — do not ask which fields to show, do not summarize instead of showing. Raw rows are withheld by default; pass include_records=true only if you need them for computation."
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
                    . "IMPORTANT: render 'preview_markdown' to the user VERBATIM as a markdown table — do not ask which fields to show, do not summarize instead of showing. Raw rows are withheld by default; pass include_records=true only if you need them for computation. The full result set is cached as reference \"$ref\" — page with offset/limit (each page returns its own preview_markdown) or narrow with a new filter.",
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

            $this->emDebug("projects.search debug", [
                'query' => $query,
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
     * Tool 10: records.save
     * Create or update record data
     */
    public function toolSaveRecords(array $payload)
    {
        if (empty($payload['pid'])) {
            return [
                "error" => true,
                "message" => "Missing required parameter: pid"
            ];
        }

        if (empty($payload['data'])) {
            return [
                "error" => true,
                "message" => "Missing required parameter: data"
            ];
        }

        $pid = (int)$payload['pid'];
        $data = $payload['data'];
        $overwrite = $payload['overwrite'] ?? false; // Default: normal (not overwrite)

        try {
            $saveMode = $overwrite ? 'overwrite' : 'normal';

            // Normalize data to flat format for saveData('json').
            // The LLM may send either:
            //   Flat:   {"cas_id": "123", "field": "value"}
            //   Nested: {"123": {"cas_id": "123", "field": "value"}}
            // Detect nested format (values are arrays, keys are non-numeric) and flatten.
            $firstValue = reset($data);
            if (is_array($firstValue) && !isset($data[0])) {
                $data = array_values($data);
            }

            // Wrap single record in array
            if (!isset($data[0])) {
                $data = [$data];
            }

            $result = \REDCap::saveData($pid, 'json', json_encode($data), $saveMode);

            // Check for errors
            if (!empty($result['errors'])) {
                return [
                    "error" => true,
                    "message" => "Failed to save data",
                    "errors" => $result['errors'],
                    "warnings" => $result['warnings'] ?? [],
                    "data_submitted" => $data
                ];
            }

            return [
                "pid" => $pid,
                "success" => true,
                "records_saved" => $result['item_count'] ?? count($data),
                "record_ids" => $result['ids'] ?? [],
                "warnings" => $result['warnings'] ?? [],
                "overwrite_mode" => $overwrite
            ];
        } catch (\Exception $e) {
            $this->emError("saveRecords error for pid $pid: " . $e->getMessage());
            return [
                "error" => true,
                 "message" => "Failed to save records: " . $e->getMessage()
             ];
         }
     }

    /**
     * Validate that every [field] referenced in a REDCap logic filter exists
     * in the project's data dictionary. Unknown fields silently evaluate to
     * 0 matching records, which sends the agent guessing — fail loudly with
     * fuzzy suggestions instead. Event names in [event][field] syntax are
     * allowed through. Returns an error array, or null when all fields are OK.
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

        // Fuzzy suggestions per unknown field
        $allFields = array_keys($dd);
        $suggestions = [];
        foreach ($unknown as $f) {
            $scored = [];
            foreach ($allFields as $candidate) {
                similar_text($f, $candidate, $pct);
                if ($pct >= 40) $scored[$candidate] = $pct;
            }
            arsort($scored);
            $suggestions[$f] = array_slice(array_keys($scored), 0, 3);
        }

        return [
            "error" => true,
            "message" => "Unknown field(s) in filter: " . implode(', ', $unknown)
                . ". These fields do not exist in project $pid — the filter would silently match 0 records. "
                . "Use the suggested fields below (or call projects.getMetadata to browse all fields).",
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
     */
    private function cappyBuildPreview(int $pid, array $page, ?string $filter, int $maxRows = 20, int $maxCols = 8): string
    {
        if (empty($page)) return '';
        $recordIdField = \REDCap::getRecordIdField($pid);

        $rows = $this->cappyFlattenRows($page);
        if (empty($rows)) return '';

        // Column selection
        $cols = [$recordIdField];
        foreach ($this->cappyExtractFilterFields($filter ?? '') as $f) {
            if ($f === $recordIdField || in_array($f, $cols)) continue;
            foreach ($rows as [, $fields]) {
                if (array_key_exists($f, $fields) && !is_array($fields[$f])) { $cols[] = $f; break; }
            }
        }
        // Rank remaining scalar fields by non-empty count across the sample
        $scores = [];
        foreach (array_slice($rows, 0, 50) as [, $fields]) {
            foreach ($fields as $field => $val) {
                if (is_array($val) || in_array($field, $cols)) continue;
                if (strpos($field, '___') !== false || substr($field, -9) === '_complete') continue;
                if (!isset($scores[$field])) $scores[$field] = 0;
                if ($val !== '' && $val !== null) $scores[$field]++;
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
        $meta = [];
        try {
            $dd = \REDCap::getDataDictionary($pid, 'array', false, $cols);
            foreach ($dd as $fname => $info) {
                $type = $info['field_type'] ?? '';
                if (in_array($type, ['radio', 'select', 'dropdown', 'checkbox', 'yesno', 'truefalse'], true)) {
                    $enum = $info['select_choices_or_calculations'] ?? '';
                    $meta[$fname] = $enum !== '' ? parseEnum($enum) : [];
                    if ($type === 'yesno') $meta[$fname] = ['1' => 'Yes', '0' => 'No'];
                    if ($type === 'truefalse') $meta[$fname] = ['1' => 'True', '0' => 'False'];
                }
            }
        } catch (\Exception $e) {
            $meta = []; // labels are best-effort; raw values on failure
        }

        $esc = fn($v) => str_replace(["|", "\n", "\r"], ['\|', ' ', ' '], trim((string)$v));
        $label = function ($field, $val) use ($meta, $esc) {
            // Checkbox values arrive as arrays of code => '0'/'1' — render the checked labels
            if (is_array($val)) {
                $checked = [];
                foreach ($val as $code => $flag) {
                    if ((string)$flag !== '' && (string)$flag !== '0') {
                        $checked[] = $meta[$field][$code] ?? $code;
                    }
                }
                return $esc(implode(', ', $checked));
            }
            $val = trim((string)$val);
            if ($val === '') return '';
            if (isset($meta[$field]) && array_key_exists($val, $meta[$field])) {
                return $esc($meta[$field][$val]);
            }
            return $esc($val);
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
    // Session cache for large tool results. The LLM gets a short reference
    // id; the full data lives in $_SESSION for the lifetime of the session
    // (default 10 min, configurable below). Survives iframe reloads, full
    // page reloads, and any same-session navigation — the whole point of
    // building this client-of-server instead of the earlier sessionStorage
    // plan.
    // -----------------------------------------------------------------

    /** TTL for cached tool results, in seconds. 30 min — long enough for a
     *  multi-turn analysis conversation to keep reusing the same recordset. */
    const CAPPY_CACHE_TTL = 1800;

    /** Max records to filter in memory via per-record evaluateLogic. Past
     *  this, the per-call Project construction outweighs one scoped query. */
    const CAPPY_FILTER_INLINE_MAX = 200;

    /**
     * Stash $payload in the PHP session under a short random reference id
     * and return that reference. Caller passes the reference back to retrieve
     * the data (via cappyCacheFetch). Auto-prunes expired entries on every call.
     */
    private function cappyCacheStore(string $key, $payload): string
    {
        if (!isset($_SESSION['cappy_data_cache'])) {
            $_SESSION['cappy_data_cache'] = [];
        }
        $now = time();
        // Prune expired entries (cheap; usually a few)
        foreach ($_SESSION['cappy_data_cache'] as $k => $entry) {
            if (($entry['expires_at'] ?? 0) < $now) {
                unset($_SESSION['cappy_data_cache'][$k]);
            }
        }
        $ref = 'ref_' . bin2hex(random_bytes(4));
        $_SESSION['cappy_data_cache'][$ref] = [
            'tool' => $key,
            'data' => $payload,
            'expires_at' => $now + self::CAPPY_CACHE_TTL,
            'stored_at' => $now,
        ];
        return $ref;
    }

    /**
     * Fetch a cached payload by reference. Returns null if missing, expired,
     * or (when $expectedKey is given) cached by a different tool.
     */
    private function cappyCacheFetch(string $ref, ?string $expectedKey = null)
    {
        if (!isset($_SESSION['cappy_data_cache'][$ref])) {
            return null;
        }
        $entry = $_SESSION['cappy_data_cache'][$ref];
        if (($entry['expires_at'] ?? 0) < time()) {
            unset($_SESSION['cappy_data_cache'][$ref]);
            return null;
        }
        if ($expectedKey !== null && ($entry['tool'] ?? null) !== $expectedKey) {
            return null;
        }
        return $entry['data'];
    }
}
