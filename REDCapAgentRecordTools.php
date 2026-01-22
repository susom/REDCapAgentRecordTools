<?php
namespace Stanford\REDCapAgentRecordTools;

require_once "emLoggerTrait.php";

class REDCapAgentRecordTools extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Main API entry point for agent tools
     */
    public function redcap_module_api($action = null, $payload = [])
    {
        // Normalize payload
        // REDCap API framework passes payload as ['payload' => '{"json":"string"}']
        if (!empty($payload['payload'])) {
            $payloadData = json_decode($payload['payload'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $payload = $payloadData;
            }
        } elseif (empty($payload)) {
            // Fallback: check if payload is passed as a POST parameter (common for curl -d)
            if (!empty($_POST['payload'])) {
                $payload = json_decode($_POST['payload'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return $this->wrapResponse([
                        "error" => true,
                        "message" => "Invalid JSON in payload parameter"
                    ], 400);
                }
            } else {
                // Try reading raw input (for application/json content-type)
                $raw = file_get_contents("php://input");
                $decoded = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $payload = $decoded;
                } else {
                    $payload = $_POST;
                }
            }
        }

        $this->emDebug("AgentRecordTools API call", [
            'action' => $action,
            'payload' => $payload,
            'raw_POST' => $_POST,
            'payload_type' => gettype($payload)
        ]);

        // Debug endpoint
        if ($action === 'debug') {
            return $this->wrapResponse([
                "debug" => true,
                "action" => $action,
                "payload" => $payload,
                "payload_type" => gettype($payload),
                "POST" => $_POST
            ]);
        }

        switch ($action) {
            case "projects_getMetadata":
                return $this->wrapResponse(
                    $this->toolGetMetadata($payload)
                );

            case "projects_getInstruments":
                return $this->wrapResponse(
                    $this->toolGetInstruments($payload)
                );

            case "records_get":
                return $this->wrapResponse(
                    $this->toolGetRecord($payload)
                );

            case "records_search":
                return $this->wrapResponse(
                    $this->toolSearchRecords($payload)
                );

            case "survey_getLink":
                return $this->wrapResponse(
                    $this->toolGetSurveyLink($payload)
                );

            case "records_evaluateLogic":
                return $this->wrapResponse(
                    $this->toolEvaluateLogic($payload)
                );

            case "projects_search":
                return $this->wrapResponse(
                    $this->toolSearchProjects($payload)
                );

            case "records_save":
                return $this->wrapResponse(
                    $this->toolSaveRecords($payload)
                );

            default:
                return $this->wrapResponse([
                    "error" => true,
                    "message" => "Unknown action: $action"
                ], 400);
        }
    }

    private function wrapResponse(array $result, int $defaultStatus = 200){
        return [
            "status" => isset($result['error']) ? 400 : $defaultStatus,
            "body" => json_encode($result),
            "headers" => ["Content-Type" => "application/json"]
        ];
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

        try {
            $data = \REDCap::getData(
                $pid,
                $return_format,
                null,        // all records (filter applied via $filterLogic)
                $fields,
                null,        // events
                null,        // groups
                false,       // combine checkbox values
                false,       // DAG
                false,       // survey fields
                $filter      // REDCap logic filter
            );

            $record_count = is_array($data) ? count($data) : 0;

            return [
                "pid" => $pid,
                "filter" => $filter,
                "record_count" => $record_count,
                "records" => $data
            ];
        } catch (\Exception $e) {
            $this->emError("searchRecords error for pid $pid: " . $e->getMessage());
            return [
                "error" => true,
                "message" => "Failed to search records: " . $e->getMessage()
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

        // Ensure data is in array format (can be single record or multiple)
        // REDCap::saveData expects array of records
        if (!isset($data[0])) {
            // Single record object, wrap it in array
            $data = [$data];
        }

        try {
            $saveMode = $overwrite ? 'overwrite' : 'normal';

            // REDCap::saveData returns array with:
            // - 'errors' => array of error messages (empty if successful)
            // - 'warnings' => array of warnings
            // - 'item_count' => number of items saved
            // - 'ids' => array of record IDs saved
            $result = \REDCap::saveData(
                $pid,
                'array',
                $data,
                $saveMode
            );

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
}
