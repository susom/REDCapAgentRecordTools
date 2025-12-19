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
        if (empty($payload)) {
            $raw = file_get_contents("php://input");
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $payload = $decoded;
            } else {
                $payload = $_POST;
            }
        }

        $this->emDebug("AgentRecordTools API call", [
            'action' => $action,
            'payload' => $payload
        ]);

        switch ($action) {
            case "records_getUserIdByFullName":
                return $this->wrapResponse(
                    $this->getUserIdByFullName($payload)
                );

            case "records_getClinicalData":
                return $this->wrapResponse(
                    $this->getClinicalData($payload)
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
     * Tool 1: records.getUserIdByFullName
     * STUB — deterministic
     */
    private function getUserIdByFullName(array $payload)
    {
        if (empty($payload['full_name'])) {
            return [
                "error" => true,
                "message" => "Missing required parameter: full_name"
            ];
        }

        return [
            "record_id" => "12345",
            "debug" => [
                "tool" => "records.getUserIdByFullName",
                "received_full_name" => trim($payload['full_name']),
                "note" => "Stubbed deterministic record ID"
            ]
        ];
    }

    /**
     * Tool 2: records.getClinicalData
     * STUB — chainable, deterministic
     */
    private function getClinicalData(array $payload)
    {
        if (empty($payload['record_id'])) {
            return [
                "error" => true,
                "message" => "Missing required parameter: record_id"
            ];
        }

        if (empty($payload['condition'])) {
            return [
                "error" => true,
                "message" => "Missing required parameter: condition"
            ];
        }

        $months = $payload['months'] ?? null;

        return [
            "record_id" => $payload['record_id'],
            "condition" => $payload['condition'],
            "time_window_months" => $months,
            "records" => [
                [
                    "date" => "2025-01-10",
                    "treatment" => "Lisinopril 10mg",
                    "note" => "Initial hypertension diagnosis"
                ],
                [
                    "date" => "2025-02-14",
                    "treatment" => "Lisinopril increased to 20mg",
                    "note" => "BP improving"
                ],
                [
                    "date" => "2025-03-05",
                    "treatment" => "Diet + exercise counseling",
                    "note" => "Continued improvement"
                ]
            ],
            "debug" => [
                "tool" => "records.getClinicalData",
                "stub" => true
            ]
        ];
    }
}
