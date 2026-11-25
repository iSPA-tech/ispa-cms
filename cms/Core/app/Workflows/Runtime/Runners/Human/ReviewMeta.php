<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Human;

use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Services\Agentic\AgentApprovalService;
use Cms\Core\Services\Ai\AiModelChoices;
use Cms\Core\Workflows\Runtime\Runners\Util\NodeHelpers;

/**
 * Review facts shared by the producers of a reviewable approval: the `approval` node (ApprovalRunner) and the
 * `ai_action` proposal (AgentActionRunner). Everything here ends up in `diff_data.workflow_node`.
 *
 * - editable fields: payload dot paths the reviewer may change before approving;
 * - revision options: `[{key, label_key, label?, type?, choices: [{value, label_key, label?}]}]` offered in the rewrite
 *   form; `type: model` is the model choice (choices `"provider:model"` with plain-text labels);
 * - reject reasons: `[{key, label_key, label}]` a reviewer picks one of when rejecting (`reject_reason`);
 * - rounds: every rewrite is a new approval row, chained by `previous_approval_id` / `next_approval_id`.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Human
 * @since   2026-11-24
 */
final class ReviewMeta
{
    /** Highest `max_rounds` a node may set. */
    public const MAX_ROUNDS_CAP = 5;

    /** Most reject reasons one approval may declare. */
    public const MAX_REJECT_REASONS = 10;

    /** Key and label of the model rewrite option. */
    public const MODEL_OPTION_KEY = 'model';

    public const MODEL_OPTION_LABEL_KEY = 'text.agentic.rewrite.model_option';

    private const KEY_PATTERN = '/^[a-z0-9_]{1,40}$/';

    /**
     * Editable payload paths from the given names; a plain name that is not a payload key but is a key of
     * `payload.proposed_change` becomes `proposed_change.<name>`, and a reversible-field change prefixes `changes.`.
     *
     * @param array<int, mixed>    $names
     * @param array<string, mixed> $envelope `artifact_type` and `payload`
     * @return list<string>
     */
    public static function editableFields(array $names, array $envelope): array
    {
        $payload = (array) ($envelope['payload'] ?? []);
        $paths = [];
        foreach ($names as $name) {
            if (!is_string($name) || trim($name) === '') {
                continue;
            }
            $name = trim($name);
            if (!str_contains($name, '.') && !array_key_exists($name, $payload) && is_array($payload['proposed_change'] ?? null)
                && array_key_exists($name, $payload['proposed_change'])) {
                $name = 'proposed_change.' . $name;
            }
            if (($envelope['artifact_type'] ?? null) === AgentApprovalService::WORKFLOW_FIELD_PATCH_TYPE && !str_starts_with($name, 'changes.')) {
                $name = 'changes.' . $name;
            }
            $paths[$name] = true;
        }

        return array_keys($paths);
    }

    /**
     * Declared rewrite options, keeping only well-formed entries (a key and at least one choice).
     *
     * `type` and plain `label` (option and choice) are kept when present.
     *
     * @return list<array<string, mixed>>
     */
    public static function revisionOptions(mixed $value): array
    {
        $options = [];
        foreach (is_array($value) ? $value : [] as $option) {
            $key = is_array($option) && is_string($option['key'] ?? null) ? trim($option['key']) : '';
            if ($key === '' || !preg_match(self::KEY_PATTERN, $key) || isset($options[$key])) {
                continue;
            }
            $choices = [];
            foreach ((array) ($option['choices'] ?? []) as $choice) {
                $choiceValue = is_array($choice) ? ($choice['value'] ?? null) : $choice;
                if (!is_scalar($choiceValue) || trim((string) $choiceValue) === '') {
                    continue;
                }
                $row = [
                    'value' => mb_substr(trim((string) $choiceValue), 0, 100),
                    'label_key' => is_array($choice) && is_string($choice['label_key'] ?? null) ? $choice['label_key'] : null,
                ];
                if (is_array($choice) && is_string($choice['label'] ?? null) && trim($choice['label']) !== '') {
                    $row['label'] = mb_substr(trim($choice['label']), 0, 200);
                }
                $choices[] = $row;
            }
            if ($choices === []) {
                continue;
            }
            $options[$key] = [
                'key' => $key,
                'label_key' => is_string($option['label_key'] ?? null) ? $option['label_key'] : null,
                'choices' => $choices,
            ];
            if (is_string($option['label'] ?? null) && trim($option['label']) !== '') {
                $options[$key]['label'] = mb_substr(trim($option['label']), 0, 200);
            }
            if (is_string($option['type'] ?? null) && preg_match(self::KEY_PATTERN, $option['type'])) {
                $options[$key]['type'] = $option['type'];
            }
        }

        return array_values($options);
    }

    /**
     * The model rewrite option (`type: model`) with the currently selectable "provider:model" choices, or null when no
     * provider has a key.
     *
     * @return array<string, mixed>|null
     */
    public static function modelOption(): ?array
    {
        $choices = app(AiModelChoices::class)->revisionChoices();

        return $choices === [] ? null : [
            'key' => self::MODEL_OPTION_KEY,
            'label_key' => self::MODEL_OPTION_LABEL_KEY,
            'type' => 'model',
            'choices' => $choices,
        ];
    }

    /**
     * Declared reject reasons, well-formed only: `[{key, label_key, label}]`, keys `[a-z0-9_]{1,40}`, at most 10.
     *
     * @return list<array{key: string, label_key: string|null, label: string|null}>
     */
    public static function rejectReasons(mixed $value): array
    {
        $reasons = [];
        foreach (is_array($value) ? $value : [] as $reason) {
            $key = is_array($reason) && is_string($reason['key'] ?? null) ? trim($reason['key']) : '';
            if ($key === '' || !preg_match(self::KEY_PATTERN, $key) || isset($reasons[$key])) {
                continue;
            }
            $reasons[$key] = [
                'key' => $key,
                'label_key' => is_string($reason['label_key'] ?? null) && $reason['label_key'] !== '' ? $reason['label_key'] : null,
                'label' => is_string($reason['label'] ?? null) && trim($reason['label']) !== '' ? mb_substr(trim($reason['label']), 0, 200) : null,
            ];
            if (count($reasons) >= self::MAX_REJECT_REASONS) {
                break;
            }
        }

        return array_values($reasons);
    }

    /**
     * Whether a workflow step has an outgoing edge on $handle (same edge reading as the executor), in the run's version.
     */
    public static function hasEdge(?int $stepId, string $handle, ?\Cms\Core\Models\Workflows\WorkflowInstance $instance = null): bool
    {
        if ($stepId === null) {
            return false;
        }
        $reader = new class () {
            use NodeHelpers;

            public static function handleOf(mixed $condition): ?string
            {
                return self::edgeHandle($condition);
            }
        };

        // The run's version (the one executing now, else $instance's), never the draft rows (RunGraph).
        return \Cms\Core\Services\Workflow\Versioning\RunGraph::edgesFrom($stepId, $instance)
            ->contains(static fn ($edge): bool => $reader::handleOf($edge->condition) === $handle);
    }

    /**
     * Round cap: an integer 1..5, else `core.workflow_nodes.human.max_revision_rounds` (3).
     */
    public static function maxRounds(mixed $value): int
    {
        $default = (int) config('core.workflow_nodes.human.max_revision_rounds', 3);
        $rounds = is_numeric($value) ? (int) $value : $default;

        return max(1, min(self::MAX_ROUNDS_CAP, $rounds));
    }

    /**
     * Round of a stored approval (1 when it predates rounds).
     */
    public static function roundOf(?AgentApproval $approval): int
    {
        $round = $approval === null ? null : data_get($approval->diff_data, 'workflow_node.round');

        return is_numeric($round) ? max(1, (int) $round) : 1;
    }

    /**
     * What the producer needs to write the next draft after a reviewer requested changes.
     *
     * @return array{round: int, previous_approval_id: int, feedback: string|null, added_info: string|null,
     *               options: array<string, string>, previous_payload: mixed}
     */
    public static function revision(AgentApproval $approval): array
    {
        $approval->loadMissing('artifact');
        $request = (array) data_get($approval->diff_data, 'workflow_node.revision_request', []);

        return [
            'round' => self::roundOf($approval),
            'previous_approval_id' => (int) $approval->id,
            'feedback' => is_string($request['feedback'] ?? null) ? $request['feedback'] : $approval->reviewer_note,
            'added_info' => is_string($request['added_info'] ?? null) ? $request['added_info'] : null,
            'options' => is_array($request['options'] ?? null) ? $request['options'] : [],
            'previous_payload' => $approval->artifact?->payload,
        ];
    }

    /**
     * Round facts of a new approval that follows $previous (or the first round when there is none).
     *
     * @return array{round: int, max_rounds: int, previous_approval_id: int|null}
     */
    public static function rounds(?AgentApproval $previous, mixed $maxRounds): array
    {
        return [
            'round' => $previous === null ? 1 : self::roundOf($previous) + 1,
            'max_rounds' => self::maxRounds($maxRounds),
            'previous_approval_id' => $previous?->id !== null ? (int) $previous->id : null,
        ];
    }

    /**
     * Record on the old round that the rewrite opened $next.
     */
    public static function linkNext(AgentApproval $previous, AgentApproval $next): void
    {
        $fresh = $previous->fresh();
        if ($fresh === null) {
            return;
        }
        $diff = (array) $fresh->diff_data;
        $diff['workflow_node'] = array_merge((array) ($diff['workflow_node'] ?? []), ['next_approval_id' => (int) $next->id]);
        $fresh->forceFill(['diff_data' => $diff])->save();
    }
}
