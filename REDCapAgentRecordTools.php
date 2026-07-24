<?php
namespace Stanford\REDCapAgentRecordTools;

require_once "emLoggerTrait.php";
require_once "classes/PhiFieldPreHook.php";

class REDCapAgentRecordTools extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    // Hard cap on records.search results per call — prevents dumping an entire
    // project's record set into the chat window. Callers page through with
    // offset/limit if they genuinely need more (e.g. for analysis), and the
    // "truncated" flag + message steer the agent toward Data Exports for
    // full-record-set requests instead.
    const MAX_RECORDS_RETURNED = 50;

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
        $fields = $payload['fields'] ?? null; // Optional
        $return_format = $payload['return_format'] ?? 'array'; // 'array' or 'json'
        $offset = max(0, (int)($payload['offset'] ?? 0));
        $limit = (int)($payload['limit'] ?? self::MAX_RECORDS_RETURNED);
        if ($limit <= 0 || $limit > self::MAX_RECORDS_RETURNED) {
            $limit = self::MAX_RECORDS_RETURNED;
        }

        // Reference path: load from PHP session and return a slice. Avoids
        // re-querying when the LLM is paging through a previously-cached result.
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
            $ids = $cached['ids'];
            $page = array_slice($ids, $offset, $limit, true);
            $returned_count = count($page);
            return [
                "pid" => $pid,
                "filter" => $filter,
                "reference" => $reference,
                "total_record_count" => count($ids),
                "returned_count" => $returned_count,
                "offset" => $offset,
                "limit" => $limit,
                "truncated" => ($offset + $returned_count) < count($ids),
                "records" => $return_format === 'json' ? json_encode($page) : $page,
                "note" => "Served from session cache (reference $reference).",
            ];
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

            // Large result: cache the full thing in PHP session and return a ref +
            // a small preview. The LLM can call back with the ref to page through.
            // Threshold = double the per-call cap (50) since showing 50 inline is fine.
            if ($total_record_count > self::MAX_RECORDS_RETURNED * 2) {
                $ref = $this->cappyCacheStore('records_search', [
                    'pid' => $pid,
                    'filter' => $filter,
                    'ids' => $data,
                ]);
                $preview_ids = array_slice($data, 0, 10, true);
                return [
                    "pid" => $pid,
                    "filter" => $filter,
                    "total_record_count" => $total_record_count,
                    "returned_count" => 0,
                    "offset" => 0,
                    "limit" => 10,
                    "truncated" => true,
                    "reference" => $ref,
                    "preview" => $return_format === 'json' ? json_encode($preview_ids) : $preview_ids,
                    "records" => [],
                    "message" => "Result is large ($total_record_count records). Cached server-side in the PHP session. Use the 'reference' parameter to page through it: records.search(pid=$pid, reference=\"$ref\", offset=N, limit=M). The cache auto-expires after 10 minutes."
                ];
            }

            $page = is_array($data) ? array_slice($data, $offset, $limit, true) : [];
            $returned_count = count($page);
            $truncated = ($offset + $returned_count) < $total_record_count;

            $result = [
                "pid" => $pid,
                "filter" => $filter,
                "total_record_count" => $total_record_count,
                "returned_count" => $returned_count,
                "offset" => $offset,
                "limit" => $limit,
                "truncated" => $truncated,
                "records" => $return_format === 'json' ? json_encode($page) : $page
            ];

            if ($truncated) {
                $result["message"] = "Showing $returned_count of $total_record_count matching records "
                    . "(offset $offset). Do not try to list or summarize the full record set in chat — if the "
                    . "user wants the complete record set, tell them to use Data Exports, Reports, and Stats "
                    . "(left nav under Applications) instead, and offer to highlight that link with page.highlight "
                    . "if page actions are available. Use offset/limit to page through results only if you need "
                    . "more records for a specific analysis.";
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

    // -----------------------------------------------------------------
    // Session cache for large tool results. The LLM gets a short reference
    // id; the full data lives in $_SESSION for the lifetime of the session
    // (default 10 min, configurable below). Survives iframe reloads, full
    // page reloads, and any same-session navigation — the whole point of
    // building this client-of-server instead of the earlier sessionStorage
    // plan.
    // -----------------------------------------------------------------

    /** TTL for cached tool results, in seconds. */
    const CAPPY_CACHE_TTL = 600;

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
            'data' => $payload,
            'expires_at' => $now + self::CAPPY_CACHE_TTL,
            'stored_at' => $now,
        ];
        return $ref;
    }

    /**
     * Fetch a cached payload by reference. Returns null if missing or expired.
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
        return $entry['data'];
    }
}
