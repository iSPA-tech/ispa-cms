<?php

/*
 * Trigger, sub-workflow and error-workflow nodes (lane N3). Merged as `core.workflow_nodes.triggers`;
 * NodeRegistry::runnerMap()/definitionRows() pick up `step_runners` and `definitions`.
 *
 * A project's start node is its first `trigger_*` step (WorkflowProjectRunService::start() points the run at it).
 * Project-level settings the triggers read live on the project row: schedule_cron/schedule_timezone (synced from a
 * `trigger_schedule` node on save), scheduled_by_user_id (the user unattended runs act for), webhook_token/secret,
 * error_workflow_project_id.
 */

use Cms\Core\Workflows\Runtime\Runners\Trigger\ExecuteWorkflowRunner;
use Cms\Core\Workflows\Runtime\Runners\Trigger\TriggerStartRunner;
use Cms\Core\Workflows\Runtime\Runners\Trigger\WebhookRespondRunner;

$expr = ['type' => ['string', 'number', 'integer', 'boolean', 'null'], 'x-expr' => true];
$str = ['type' => ['string', 'null']];
$int = ['type' => ['integer', 'string', 'null']];
$list = ['type' => ['array', 'null']];
$map = ['type' => ['object', 'array', 'null']];
$storeAs = ['type' => ['string', 'null'], 'x-store' => true];
$enum = static fn (array $values): array => ['type' => ['string', 'null'], 'enum' => array_merge($values, [null])];
$object = static fn (array $properties): array => ['type' => 'object', 'properties' => $properties];
$label = static fn (string $type): array => [
    'label_key' => "text.workflow.node.{$type}.title",
    'description_key' => "text.workflow.node.{$type}.desc",
];
// One result field for the designer's data picker (NodeDefinition::$result); `item` = parts of each list element.
$part = static fn (string $key, string $type, string $label, array $item = []): array => ['key' => $key, 'type' => $type, 'label' => $label] + ($item === [] ? [] : ['item' => $item]);

// What each start node stores under `storeAs` (TriggerStartRunner); every one also carries {source, at}.
$results = [
    'trigger_manual' => [
        $part('input', 'object', 'Input'),
        $part('source', 'string', 'Source'),
        $part('at', 'datetime', 'Started at'),
    ],
    'trigger_sub_workflow' => [
        $part('input', 'object', 'Input'),
        $part('source', 'string', 'Source'),
        $part('at', 'datetime', 'Started at'),
    ],
    'trigger_schedule' => [
        $part('fired_at', 'datetime', 'Fired at'),
        $part('cron', 'string', 'Schedule'),
        $part('source', 'string', 'Source'),
        $part('at', 'datetime', 'Started at'),
    ],
    'trigger_model_event' => [
        $part('record.model', 'string', 'Record type'),
        $part('record.event', 'string', 'Change'),
        $part('record.id', 'number', 'Record number'),
        $part('record.uuid', 'string', 'Record ID'),
        $part('record.attributes', 'object', 'Record fields'),
        $part('record.changed', 'list', 'Changed fields'),
        $part('source', 'string', 'Source'),
        $part('at', 'datetime', 'Started at'),
    ],
    // `event.payload` holds the fields of the node's own `schema` (copied from the event's `trigger_events` entry).
    'trigger_event' => [
        $part('event.name', 'string', 'Event name'),
        $part('event.payload', 'object', 'Event data') + ['dynamic' => true],
        $part('source', 'string', 'Source'),
        $part('at', 'datetime', 'Started at'),
    ],
    'trigger_webhook' => [
        $part('body', 'object', 'Body'),
        $part('query', 'object', 'Query parameters'),
        $part('headers', 'object', 'Headers'),
        $part('method', 'string', 'Method'),
        $part('tainted', 'boolean', 'Came from outside'),
        $part('source', 'string', 'Source'),
        $part('at', 'datetime', 'Started at'),
    ],
    'trigger_error' => [
        $part('failed_instance.id', 'number', 'Failed run number'),
        $part('failed_instance.project_id', 'number', 'Failed workflow number'),
        $part('failed_instance.project_slug', 'string', 'Failed workflow code'),
        $part('failed_instance.status', 'string', 'Failed run status'),
        $part('failed_instance.finished_at', 'datetime', 'Failed run ended at'),
        $part('step', 'string', 'Failed step'),
        $part('error', 'string', 'Error'),
        $part('source', 'string', 'Source'),
        $part('at', 'datetime', 'Started at'),
    ],
];
// `taint_output`: what a start node carries may come from outside the site's staff (review 2026-11-24 findings 4, 25):
// webhook bodies, records saved by visitors (public contact / registration forms raise model events) and app events
// built from visitor input (a sign-up's display name). A step that sends or writes such data needs an approval before
// it; an in-app notice to the site's admins does not (the `notify` node is no taint sink). Manual, schedule,
// sub-workflow and error starts carry staff- or system-made data.
$trigger = static fn (string $type, string $icon, array $properties, array $defaults, bool $taint = false) => $label($type) + [
    'result' => $results[$type],
    'group' => 'trigger', 'icon' => $icon,
    'safety' => ['read'], 'approval_default' => 'off', 'taint_output' => $taint,
    'config_schema' => $object($properties + ['storeAs' => $storeAs]),
    'default_config' => $defaults,
];

return [
    'step_runners' => [
        'trigger_manual' => TriggerStartRunner::class,
        'trigger_schedule' => TriggerStartRunner::class,
        'trigger_model_event' => TriggerStartRunner::class,
        'trigger_event' => TriggerStartRunner::class,
        'trigger_webhook' => TriggerStartRunner::class,
        'trigger_sub_workflow' => TriggerStartRunner::class,
        'trigger_error' => TriggerStartRunner::class,
        'execute_workflow' => ExecuteWorkflowRunner::class,
        'webhook_respond' => WebhookRespondRunner::class,
    ],

    'definitions' => [
        'trigger_manual' => $trigger('trigger_manual', 'play', ['input_schema' => $map], ['input_schema' => null]),
        'trigger_schedule' => $trigger('trigger_schedule', 'clock', [
            'cron' => $str, 'timezone' => $str,
        ], ['cron' => '0 7 * * *', 'timezone' => null]),
        'trigger_model_event' => $trigger('trigger_model_event', 'database', [
            'model' => $str,
            'events' => $list,
            'fields' => $list,
            'filter' => $map,
            'debounce_seconds' => $int,
        ], ['model' => '', 'events' => ['created'], 'fields' => [], 'filter' => [], 'debounce_seconds' => 0], true),
        // Version 2 (2026-11-25): the event must be on the `trigger_events` allowlist, which declares its payload schema.
        // A version 1 config (any event name) upgrades unchanged. Templates built here declare version 2, so a site from
        // before the allowlist (version 1 node) refuses them with `node_version_unsupported` instead of installing a
        // workflow whose event never fires there.
        'trigger_event' => $trigger('trigger_event', 'flash', ['event' => $str, 'schema' => $map], ['event' => ''], true) + ['dynamic' => 'schema', 'version' => 2],
        'trigger_webhook' => $trigger('trigger_webhook', 'link', [
            'auth' => $enum(['hmac', 'token']),
            'respond' => $enum(['immediate', 'wait']),
        ], ['auth' => 'hmac', 'respond' => 'immediate'], true),
        'trigger_sub_workflow' => $trigger('trigger_sub_workflow', 'enter', ['input_schema' => $map], ['input_schema' => null]),
        'trigger_error' => $trigger('trigger_error', 'warning', [], []),
        'execute_workflow' => $label('execute_workflow') + [
            'result' => [
                $part('instance_id', 'number', 'Run number'),
                $part('status', 'string', 'Status'),
                $part('vars', 'object', 'Variables'),
            ],
            'group' => 'flow', 'icon' => 'flow',
            'safety' => ['internal_write'], 'approval_default' => 'off',
            'config_schema' => $object([
                'project' => ['type' => ['string', 'integer', 'null']],
                'input' => $map,
                'mode' => $enum(['wait', 'fire']),
                'storeAs' => $storeAs,
            ]),
            'default_config' => ['project' => '', 'input' => [], 'mode' => 'wait', 'storeAs' => 'vars.child'],
        ],
        'webhook_respond' => $label('webhook_respond') + [
            'group' => 'integration', 'icon' => 'reply',
            'safety' => ['read'], 'approval_default' => 'off',
            'config_schema' => $object(['status' => $int, 'body' => $expr]),
            'default_config' => ['status' => 200, 'body' => ''],
        ],
    ],

    'settings' => [
        // Longest chain of runs that start other runs (sub-workflows, model events raised by a run, error workflows).
        'max_chain_depth' => 5,
        // Seconds the trigger index (projects by model / event) is cached; saves of steps and projects clear it.
        'index_cache_seconds' => 300,
        // Longest debounce a model-event trigger may ask for.
        'max_debounce_seconds' => 3600,
        // Webhook: largest body accepted, runs per minute per project, and the longest a `wait` webhook holds the
        // request for a `webhook_respond` answer.
        'webhook_max_bytes' => 262144,
        'webhook_per_minute' => 60,
        'webhook_wait_seconds' => 10,
        // Largest trigger payload (bytes of JSON) stored on a run; bigger payloads are cut to their keys.
        'max_payload_bytes' => 65536,
    ],
];
