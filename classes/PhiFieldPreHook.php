<?php

namespace Stanford\REDCapAgentRecordTools;

// SecureChatAI may not be loaded yet at EM boot — load its interfaces directly.
if (!interface_exists('Stanford\SecureChatAI\PreToolUseHook')) {
    $scaClasses = dirname(__DIR__, 2) . '/secure_chat_ai_v9.9.9/classes';
    require_once $scaClasses . '/HookInterface.php';
    require_once $scaClasses . '/HookResult.php';
    require_once $scaClasses . '/ToolUse.php';
    require_once $scaClasses . '/ToolContext.php';
    require_once $scaClasses . '/AbortController.php';
}

use Stanford\SecureChatAI\HookResult;
use Stanford\SecureChatAI\PreToolUseHook;
use Stanford\SecureChatAI\ToolContext;
use Stanford\SecureChatAI\ToolUse;

/**
 * Blocks record data access/write for PHI fields when a non-BAA-covered model is in use.
 *
 * Gemini models are the only ones with a verified BAA at Stanford — all others are
 * blocked from touching fields marked as identifiers in the REDCap data dictionary.
 *
 * Register this hook by adding to SecureChatAI system setting 'pre_tool_use_hooks':
 *   Stanford\REDCapAgentRecordTools\PhiFieldPreHook
 */
class PhiFieldPreHook implements PreToolUseHook
{
    /** Per-request cache: pid → [phi_field, ...] */
    private static array $phiFieldCache = [];

    private const RECORD_ACTIONS = ['records.get', 'records.search', 'records.save'];

    public function handle(ToolUse $use, ToolContext $context): HookResult
    {
        if (!in_array($use->name, self::RECORD_ACTIONS, true)) {
            return HookResult::allow();
        }

        $model = strtolower($context->get('model', ''));

        // Gemini has a BAA — allow unconditionally
        if (str_contains($model, 'gemini')) {
            return HookResult::allow();
        }

        $pid = (int)($use->input['pid'] ?? $context->projectId ?? 0);
        if (empty($pid)) {
            return HookResult::allow();
        }

        $phiFields = $this->getPhiFields($pid);
        if (empty($phiFields)) {
            return HookResult::allow();
        }

        $requestedFields = $use->name === 'records.save'
            ? $this->extractSaveFields($use->input['data'] ?? [])
            : ($use->input['fields'] ?? null); // null = all fields

        // null means all fields were requested — PHI would be included
        $blocked = $requestedFields === null
            ? $phiFields
            : array_values(array_intersect($requestedFields, $phiFields));

        if (empty($blocked)) {
            return HookResult::allow();
        }

        $modelLabel = $model ?: 'unknown';
        return HookResult::deny(
            "PHI field access blocked: model '{$modelLabel}' does not have a BAA. " .
            "Identifier fields requested: " . implode(', ', $blocked) . ". " .
            "Switch to a Gemini model to access PHI fields, or exclude these fields from your request."
        );
    }

    /** Returns field names marked as identifiers for the given project. Cached per request. */
    private function getPhiFields(int $pid): array
    {
        if (!isset(self::$phiFieldCache[$pid])) {
            $metadata = \REDCap::getDataDictionary($pid, 'array', false);
            self::$phiFieldCache[$pid] = array_keys(
                array_filter($metadata, fn($f) => strtolower($f['identifier'] ?? '') === 'y')
            );
        }
        return self::$phiFieldCache[$pid];
    }

    /** Extracts field names from the save payload (handles both flat and nested record format). */
    private function extractSaveFields(array $data): array
    {
        if (empty($data)) {
            return [];
        }
        $first = reset($data);
        $keys = is_array($first) ? array_merge(...array_map('array_keys', array_filter($data, 'is_array'))) : array_keys($data);
        return array_unique($keys);
    }
}
