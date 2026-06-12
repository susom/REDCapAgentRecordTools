<?php

namespace Stanford\REDCapAgentRecordTools;

// SecureChatAI may not be loaded yet at EM boot — load its interfaces directly.
// Use glob so any installed version is found (not just v9.9.9).
if (!interface_exists('Stanford\SecureChatAI\PreToolUseHook')) {
    $scaDirs = glob(dirname(__DIR__, 2) . '/secure_chat_ai_*/classes');
    if (!empty($scaDirs)) {
        $scaClasses = $scaDirs[0];
        require_once $scaClasses . '/HookInterface.php';
        require_once $scaClasses . '/HookResult.php';
        require_once $scaClasses . '/ToolUse.php';
        require_once $scaClasses . '/ToolContext.php';
        require_once $scaClasses . '/AbortController.php';
    }
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
    /** Per-request cache: pid → ['phi' => [...], 'safe' => [...]] */
    private static array $fieldCache = [];

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

        $fields    = $this->getFieldSets($pid);
        $phiFields = $fields['phi'];

        if (empty($phiFields)) {
            return HookResult::allow();
        }

        $modelLabel = $model ?: 'unknown';

        if ($use->name === 'records.save') {
            // Block saves that write any PHI field
            $requested = $this->extractSaveFields($use->input['data'] ?? []);
            $blocked   = array_values(array_intersect($requested, $phiFields));
            if (empty($blocked)) {
                return HookResult::allow();
            }
            return HookResult::deny(
                "PHI write blocked: model '{$modelLabel}' does not have a BAA. " .
                "Cannot save identifier fields: " . implode(', ', $blocked) . "."
            );
        }

        // records.get / records.search
        $requested = $use->input['fields'] ?? null; // null = all fields

        if ($requested === null) {
            // All fields requested — tell the agent which ones are safe to ask for
            $safeList = implode(', ', $fields['safe']);
            return HookResult::deny(
                "Cannot return all fields: model '{$modelLabel}' does not have a BAA. " .
                "PHI fields blocked: " . implode(', ', $phiFields) . ". " .
                "Please re-request with specific non-PHI fields. Available fields: {$safeList}."
            );
        }

        // Specific fields requested — only block if they overlap with PHI
        $blocked = array_values(array_intersect($requested, $phiFields));
        if (empty($blocked)) {
            return HookResult::allow();
        }

        return HookResult::deny(
            "PHI field access blocked: model '{$modelLabel}' does not have a BAA. " .
            "Requested identifier fields: " . implode(', ', $blocked) . ". " .
            "Remove those fields from your request."
        );
    }

    /** Returns ['phi' => [...], 'safe' => [...]] for the project. Cached per request. */
    private function getFieldSets(int $pid): array
    {
        if (!isset(self::$fieldCache[$pid])) {
            $metadata = \REDCap::getDataDictionary($pid, 'array', false);
            $phi = [];
            $safe = [];
            foreach ($metadata as $fieldName => $f) {
                if (strtolower($f['identifier'] ?? '') === 'y') {
                    $phi[] = $fieldName;
                } else {
                    $safe[] = $fieldName;
                }
            }
            self::$fieldCache[$pid] = ['phi' => $phi, 'safe' => $safe];
        }
        return self::$fieldCache[$pid];
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
