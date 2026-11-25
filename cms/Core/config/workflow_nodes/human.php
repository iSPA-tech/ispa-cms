<?php

/*
 * Human node set (lane N2): approval, jev_review, notify, ask_and_wait.
 * Merged as `core.workflow_nodes.human`; NodeRegistry::runnerMap()/definitionRows() pick up `step_runners` and
 * `definitions`; the runners read `settings`-style keys below (`reversible_fields`, `auto_artifact_types`, `jev`, ...).
 */

use Cms\Core\Workflows\Runtime\Runners\Human\ApprovalRunner;
use Cms\Core\Workflows\Runtime\Runners\Human\AskAndWaitRunner;
use Cms\Core\Workflows\Runtime\Runners\Human\JevReviewRunner;
use Cms\Core\Workflows\Runtime\Runners\Human\NotifyRunner;

$expr = ['type' => ['string', 'number', 'integer', 'boolean', 'array', 'object', 'null'], 'x-expr' => true];
$str = ['type' => ['string', 'null']];
$bool = ['type' => ['boolean', 'string', 'integer', 'null']];
$num = ['type' => ['number', 'integer', 'string', 'null']];
$duration = ['type' => ['string', 'integer', 'null']];
$list = ['type' => ['array', 'null'], 'items' => ['type' => 'string']];
$storeAs = ['type' => ['string', 'null'], 'x-store' => true];
$object = static fn (array $properties): array => ['type' => 'object', 'properties' => $properties];
$label = static fn (string $type): array => [
    'label_key' => "text.workflow.node.{$type}.title",
    'description_key' => "text.workflow.node.{$type}.desc",
];

// One result field for the designer's data picker (NodeDefinition::$result); `item` = parts of each list element.
$part = static fn (string $key, string $type, string $label, array $item = []): array => ['key' => $key, 'type' => $type, 'label' => $label] + ($item === [] ? [] : ['item' => $item]);

// Known values of a result field (NodeDefinition::$result `values`): value => English label.
$values = static fn (array $labels): array => ['values' => array_map(
    static fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
    array_keys($labels),
    array_values($labels),
)];
$verdicts = $values(['pass' => 'Pass', 'fail' => 'Fail', 'unsure' => 'Unsure']);

/*
 * Setup questions (GET api/v2/admin/workflows/{project}/setup): `x-setup` marks a field the site must decide after a
 * sample is installed (reviewers | notify_channel | credential | required_field); `x-required` a field a run cannot do
 * without.
 */

return [
    'step_runners' => [
        'approval' => ApprovalRunner::class,
        'jev_review' => JevReviewRunner::class,
        'notify' => NotifyRunner::class,
        'ask_and_wait' => AskAndWaitRunner::class,
    ],

    'definitions' => [
        'approval' => $label('approval') + [
            'result' => [
                $part('decision', 'string', 'Decision') + $values(['approved' => 'Approved', 'rejected' => 'Rejected', 'expired' => 'Expired', 'changes_requested' => 'Changes requested']),
                $part('decided_by', 'string', 'Decided by'),
                $part('approval_id', 'number', 'Approval number'),
                $part('mode', 'string', 'Mode'),
                $part('payload', 'object', 'Reviewed content'),
                $part('edits', 'object', 'Reviewer edits'),
                $part('note', 'string', 'Reviewer note'),
                $part('reviewer_id', 'number', 'Reviewer'),
                $part('jev', 'object', 'JEV check'),
                $part('round', 'number', 'Review round'),
                $part('reason', 'string', 'Reason'),
                $part('feedback', 'string', 'Feedback'),
                $part('added_info', 'string', 'Added information'),
                $part('options', 'object', 'Chosen options'),
                $part('previous_payload', 'object', 'Previous draft'),
            ],
            'group' => 'human', 'icon' => 'clipboard-check-outline',
            'safety' => ['read'], 'approval_default' => 'off',
            // In jev mode the step calls the judge: the executor's budget gate checks the caps before it.
            'safety_when' => ['mode' => ['jev' => ['ai_spend']]],
            'config_schema' => $object([
                'mode' => ['type' => ['string', 'null'], 'enum' => ['human', 'auto', 'jev', null]],
                'title' => $str,
                'review' => $expr,
                'payload' => $expr,
                'subject_model' => $str,
                'subject_id' => $expr,
                'changes' => $expr,
                'editable_fields' => $list,
                'reviewer_permission' => $str + ['x-setup' => 'reviewers'],
                'notify' => $bool,
                'expires_after' => $duration,
                // Who must review (user uuids, max 10; notified instead of every permission holder, anyone with the
                // permission may still decide) and the target time to decide (seconds or `30m`, `4h`, `2d`; 5 minutes
                // ..30 days). Unknown uuids are refused on save; an assignee without the permission is skipped.
                'assignees' => ['type' => ['array', 'null'], 'items' => ['type' => 'string'], 'maxItems' => 10],
                'sla' => $duration,
                'reversible_skip' => $bool,
                'jev' => $object([
                    'criteria' => $str,
                    'min_confidence' => $num,
                    'on_unsure' => ['type' => ['string', 'null'], 'enum' => ['human', 'reject', null]],
                    // Auto-publish after calibration: reuse the verdict an earlier step stored (e.g. `vars.jev`, no
                    // second judge call) and hold the item for a person while `hold_if` is truthy.
                    'verdict_from' => ['type' => ['string', 'null'], 'x-expr' => true],
                    'hold_if' => ['type' => ['string', 'null'], 'x-expr' => true],
                ]),
                // Rewrite on request (wire `changes_requested` back to the drafting node): round cap and the choices
                // offered in the rewrite form, [{key, label_key, choices: [{value, label_key}]}].
                'max_rounds' => ['type' => ['integer', 'string', 'null']],
                'revision_options' => ['type' => ['array', 'null'], 'items' => ['type' => 'object']],
                // Adds a "model" rewrite option listing the selectable "provider:model" pairs.
                'revision_model_choice' => $bool,
                // Reasons a reviewer picks from when rejecting, [{key, label_key?, label?}] (keys a-z0-9_, at most 10);
                // the pick lands in `vars.<storeAs>.reason`.
                'reject_reasons' => ['type' => ['array', 'null'], 'items' => ['type' => 'object']],
                'storeAs' => $storeAs,
            ]),
            'default_config' => ['mode' => 'human', 'title' => '', 'review' => 'vars.draft.review', 'editable_fields' => [], 'expires_after' => '3d', 'storeAs' => 'vars.approval'],
            'outputs' => ['approved', 'rejected', 'expired', 'changes_requested'],
        ],
        'jev_review' => $label('jev_review') + [
            'result' => [
                $part('status', 'string', 'Status'),
                $part('verdict', 'string', 'Verdict') + $verdicts,
                $part('confidence', 'number', 'Confidence'),
                $part('reasons', 'list', 'Reasons'),
                $part('mode', 'string', 'Mode'),
                $part('model', 'string', 'AI model'),
                $part('output', 'string', 'Path taken') + $verdicts,
                $part('min_confidence', 'number', 'Lowest confidence accepted'),
            ],
            'group' => 'ai', 'icon' => 'shield-check',
            'safety' => ['ai_spend'], 'approval_default' => 'off', 'taint_output' => true,
            'config_schema' => $object([
                // The text the judge reads. The designer writes it from a criteria preset (GET
                // api/v2/admin/workflows/presets?kind=jev_criteria): `criteria_preset` names the set, `criteria_items`
                // the chosen item keys, `criteria` one line per chosen item plus any custom lines. Only `criteria` runs.
                'criteria' => $str + ['x-required' => true, 'x-setup' => 'required_field'],
                'criteria_preset' => $str,
                'criteria_items' => $list,
                'content' => $expr,
                'min_confidence' => $num,
                'mode' => ['type' => ['string', 'null'], 'enum' => ['active', 'shadow', null]],
                'storeAs' => $storeAs,
            ]),
            'default_config' => ['criteria' => '', 'content' => 'vars.draft', 'min_confidence' => 0.8, 'mode' => 'active', 'storeAs' => 'vars.jev'],
            'outputs' => ['pass', 'fail', 'unsure'],
        ],
        'notify' => $label('notify') + [
            'result' => [$part('count', 'number', 'Count'), $part('user_ids', 'list', 'People notified')],
            // An in-app notice to the site's own admins: nothing leaves the site, so AI or form text may reach it
            // without an approval. Taint
            // still flows on to any later step that publishes or sends.
            'group' => 'human', 'icon' => 'bell-badge',
            'safety' => ['internal_write'], 'approval_default' => 'off', 'taint_sink' => false,
            'config_schema' => $object([
                'permission' => $str + ['x-setup' => 'notify_channel'],
                'users' => $expr,
                'message' => $str,
                'approval_id' => $expr,
                'storeAs' => $storeAs,
            ]),
            'default_config' => ['permission' => 'agentic.approve', 'message' => '', 'storeAs' => 'vars.notified'],
        ],
        'ask_and_wait' => $label('ask_and_wait') + [
            'result' => [
                $part('status', 'string', 'Status'),
                $part('answer', 'string', 'Answer'),
                $part('note', 'string', 'Reviewer note'),
                $part('approval_id', 'number', 'Approval number'),
                $part('reviewer_id', 'number', 'Reviewer'),
            ],
            'group' => 'human', 'icon' => 'comment-question-outline',
            'safety' => ['read'], 'approval_default' => 'off',
            'config_schema' => $object([
                'question' => $str,
                'options' => $expr,
                'reviewer_permission' => $str + ['x-setup' => 'reviewers'],
                'notify' => $bool,
                'expires_after' => $duration,
                'storeAs' => $storeAs,
            ]),
            'default_config' => ['question' => '', 'options' => [], 'expires_after' => '1d', 'storeAs' => 'vars.answer'],
            'outputs' => ['answered', 'rejected', 'expired'],
        ],
    ],

    /*
     * Reversible internal fields: [ModelClass => ['field', ...]]. A workflow may change these
     * without a person approving first; every change is logged as an approval decided by `reversible_fields` and can
     * be undone (AgentApprovalService::undoReversible). Deny by default: modules add rows from their provider, e.g.
     * `Cms\Helpdesk\Models\HelpdeskTicket::class => ['priority']`.
     */
    'reversible_fields' => [],

    /*
     * Calibration of auto-publish (`jev.verdict_from`), per artifact type: [artifact_type => ['provider' => class
     * implementing ApprovalCalibrationProvider, 'source_nodes' => [node types that build that review envelope]]].
     * Modules add rows from their provider; a type without a row uses the step's own approval rows.
     */
    'calibration_providers' => [],

    /* Artifact types the `auto` and `jev` approval modes may decide (every other type needs a person). */
    'auto_artifact_types' => ['workflow.approval', 'workflow.field_patch', 'content_post_draft_patch'],

    /* Who reviews and is notified when a node does not name a permission. */
    'reviewer_permission' => 'agentic.approve',

    /* Drafts a reviewer may ask for before the approval stops offering a rewrite (a node's `max_rounds`, 1..5). */
    'max_revision_rounds' => 3,

    /* Longest `expires_after` a node may set. */
    'max_expires_after_seconds' => 30 * 86400,

    'notify' => [
        'max_recipients' => 50,
    ],

    'jev' => [
        // Lowest confidence at which a JEV verdict decides on its own (nodes may raise it, never below 0.5).
        'min_confidence' => 0.8,
        'default_model' => 'gpt-5.6-luna',
        'default_criteria' => 'The proposal is accurate, safe to publish and fit for the site.',
        // Auto-publish may be switched on after this many person decisions agreeing at least this often; the latest
        // `calibration_window` decisions below the minimum pause it. `auto_levels`: Dễ / Vừa / Chặt.
        'calibration_min_decisions' => 50,
        'calibration_min_agreement' => 0.9,
        'calibration_window' => 50,
        'auto_levels' => [0.6, 0.8, 0.9],
    ],
];
