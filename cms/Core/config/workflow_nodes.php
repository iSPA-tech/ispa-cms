<?php

/*
 * Workflow node catalog (Core). Read by Cms\Core\Workflows\Nodes\NodeRegistry.
 *
 * `definitions` describe the runner keys of `core.workflow.step_runners`; a runner without a row still gets a
 * permissive generic definition (hidden from the palette). Schemas are deliberately lenient: they must accept every
 * config saved before schemas existed (no `required`, no `additionalProperties: false`, wide types). Every property
 * declares a `type`, because JsonSchemaShapeValidator infers `object` when it is missing.
 *
 * Fields: version, group, label_key, description_key, safety (read|internal_write|external_call|ai_spend|publish),
 * approval_default (required|on|off), taint_output, permission, config_schema, default_config, outputs, bindings,
 * upgrader (NodeConfigUpgrade class), icon, editor, palette, result (parts of the value stored under `storeAs`, for the
 * designer's data picker), result_type (type of the whole stored value), dynamic (result expanded from the step config:
 * schema | model | assignments | loop, see NodeDefinition::DYNAMIC_KINDS).
 */

$any = ['type' => ['string', 'number', 'integer', 'boolean', 'array', 'object', 'null']];
$expr = ['type' => ['string', 'number', 'integer', 'boolean', 'null'], 'x-expr' => true];
$str = ['type' => ['string', 'null']];
$int = ['type' => ['integer', 'string', 'null']];
$bool = ['type' => ['boolean', 'string', 'integer', 'null']];
$list = ['type' => ['array', 'object', 'null']];
$map = ['type' => ['object', 'array', 'null']];
$storeAs = ['type' => ['string', 'null'], 'x-store' => true];
$object = static fn (array $properties): array => ['type' => 'object', 'properties' => $properties];
$label = static fn (string $type): array => [
    'label_key' => "text.workflow.node.{$type}.title",
    'description_key' => "text.workflow.node.{$type}.desc",
];

// One result field for the designer's data picker (NodeDefinition::$result); `item` = parts of each list element.
$part = static fn (string $key, string $type, string $label, array $item = []): array => ['key' => $key, 'type' => $type, 'label' => $label] + ($item === [] ? [] : ['item' => $item]);

return [
    /*
     * Module services whose #[WorkflowNode] methods become node types. Filled at boot by
     * NodeRegistry::registerModuleService() from a module provider's `app()->booted()`.
     */
    'module_services' => [],

    /*
     * Namespace prefixes (ending in `\`) a module node service may live in. Deny by default: a module adds its own
     * prefix in `app()->booted()` next to registerModuleService().
     */
    'allowlisted_namespaces' => [],

    /* Step types the taint lint accepts as an approval (any mode: human, auto, jev). */
    'approval_types' => ['approval'],

    'groups' => [
        'trigger' => ['label_key' => 'text.workflow.group.trigger', 'order' => 10],
        'flow' => ['label_key' => 'text.workflow.group.flow', 'order' => 20],
        'data' => ['label_key' => 'text.workflow.group.data', 'order' => 30],
        'cms' => ['label_key' => 'text.workflow.group.cms', 'order' => 40],
        'ai' => ['label_key' => 'text.workflow.group.ai', 'order' => 50],
        'human' => ['label_key' => 'text.workflow.group.human', 'order' => 60],
        'integration' => ['label_key' => 'text.workflow.group.integration', 'order' => 70],
        'utility' => ['label_key' => 'text.workflow.group.utility', 'order' => 80],
        'module' => ['label_key' => 'text.workflow.group.module', 'order' => 90],
        'advanced' => ['label_key' => 'text.workflow.group.advanced', 'order' => 100],
    ],

    'definitions' => [
        // Flow
        'decision' => $label('decision') + [
            'group' => 'flow',
            'config_schema' => $object([
                // `ask_site: true` turns the decision into a yes/no setup question (`x-setup: choice`): the site's
                // answer writes `expression` as 'true' or 'false' (WorkflowSetupService). Used by samples for an
                // optional branch that is off until the site switches it on (e.g. the AI spam label of the contact
                // template).
                'expression' => $expr + ['x-setup' => 'choice', 'x-setup-when' => ['ask_site' => ['1', 'true']]],
                'ask_site' => $bool,
                'condition' => $expr, 'combinator' => $str,
                'conditions' => ['type' => ['array', 'null'], 'items' => $object(['left' => $expr, 'operator' => $str, 'right' => $any, 'rightExpr' => $expr])],
            ]),
            'default_config' => ['expression' => 'true'],
            'outputs' => ['true', 'false'],
        ],
        'condition' => $label('decision') + [
            'group' => 'flow', 'palette' => false,
            'config_schema' => $object(['expression' => $expr, 'condition' => $expr]),
            'outputs' => ['true', 'false'],
        ],
        'loop_each' => $label('loop_each') + [
            'result' => [], 'dynamic' => 'loop',
            'group' => 'flow',
            'config_schema' => $object(['collectionExpr' => $expr, 'itemVar' => $str, 'indexVar' => $str, 'maxItems' => $int]),
            'default_config' => ['collectionExpr' => 'vars.items', 'itemVar' => 'vars.item'],
            'outputs' => ['each', 'done'],
        ],
        'break_loop' => $label('break_loop') + ['group' => 'flow', 'config_schema' => $object([])],
        'assert_condition' => $label('assert_condition') + [
            'group' => 'flow',
            'config_schema' => $object(['expression' => $expr, 'message' => $str]),
            'default_config' => ['expression' => 'true', 'message' => ''],
        ],
        'delay' => $label('delay') + [
            'group' => 'flow',
            'config_schema' => $object(['seconds' => $int]),
            'default_config' => ['seconds' => 60],
        ],
        'terminate_workflow' => $label('terminate_workflow') + [
            'group' => 'flow',
            'config_schema' => $object(['status' => $str, 'message' => $str]),
            'default_config' => ['status' => 'success', 'message' => ''],
            'outputs' => [],
        ],
        'throw_exception' => $label('throw_exception') + [
            'group' => 'flow',
            'config_schema' => $object(['message' => $str, 'code' => $any]),
            'default_config' => ['message' => ''],
            'outputs' => [],
        ],

        // Data and variables
        'set_variable' => $label('set_variable') + [
            'result' => [], 'dynamic' => 'assignments',
            'group' => 'data',
            'config_schema' => $object(['assignments' => ['type' => ['array', 'null'], 'items' => $object(['key' => $str, 'valueExpr' => $expr])]]),
            'default_config' => ['assignments' => [['key' => 'vars.foo', 'valueExpr' => 'root.id']]],
        ],
        'copy_variable' => $label('copy_variable') + [
            'group' => 'data',
            'config_schema' => $object(['fromExpr' => $expr, 'to' => $storeAs]),
            'default_config' => ['fromExpr' => '', 'to' => 'vars.copy'],
        ],
        'clear_variable' => $label('clear_variable') + [
            'group' => 'data',
            'config_schema' => $object(['targets' => $list]),
            'default_config' => ['targets' => ['vars.temp']],
        ],
        'aggregate_list' => $label('aggregate_list') + [
            'group' => 'data',
            'config_schema' => $object(['collectionExpr' => $expr, 'operation' => $str, 'field' => $str, 'storeAs' => $storeAs]),
            'default_config' => ['collectionExpr' => 'vars.items', 'operation' => 'count', 'storeAs' => 'vars.total'],
        ],
        'list_operation' => $label('list_operation') + [
            'group' => 'data',
            'config_schema' => $object([
                'collectionExpr' => $expr, 'operation' => $str, 'conditionExpr' => $expr, 'mapExpr' => $expr,
                'sortField' => $str, 'sortDir' => $str, 'take' => $int, 'field' => $str, 'storeAs' => $storeAs,
            ]),
            'default_config' => ['collectionExpr' => 'vars.items', 'operation' => 'filter', 'storeAs' => 'vars.list'],
        ],
        'log_message' => $label('log_message') + [
            'group' => 'data',
            'config_schema' => $object(['level' => $str, 'message' => $str, 'context' => $map]),
            'default_config' => ['level' => 'info', 'message' => 'Hello {{ root.id }}', 'context' => []],
        ],

        // CMS data (model access is also checked by WorkflowCallableGuard)
        'retrieve' => $label('retrieve') + [
            'result' => [], 'dynamic' => 'model',
            'group' => 'cms',
            'config_schema' => $object([
                'model' => $str, 'where' => $list, 'orderBy' => $list, 'limit' => $int, 'first' => $bool,
                'includeTrashed' => $bool, 'storeAs' => $storeAs,
            ]),
            'default_config' => ['model' => '', 'where' => [], 'first' => true, 'limit' => 1, 'orderBy' => [], 'storeAs' => 'vars.result', 'includeTrashed' => false],
        ],
        'change' => $label('change') + [
            'group' => 'cms', 'safety' => ['internal_write'],
            'config_schema' => $object(['targetExpr' => $expr, 'changes' => $list, 'save' => $bool]),
            'default_config' => ['targetExpr' => 'vars.target', 'changes' => [['field' => '', 'valueExpr' => '']], 'save' => true],
        ],
        'create_object' => $label('create_object') + [
            'result' => [], 'dynamic' => 'model',
            'group' => 'cms', 'safety' => ['internal_write'],
            'config_schema' => $object(['model' => $str, 'fields' => $list, 'save' => $bool, 'storeAs' => $storeAs]),
            'default_config' => ['model' => '', 'fields' => [['field' => '', 'valueExpr' => '']], 'save' => true, 'storeAs' => 'vars.created'],
        ],
        'clone_object' => $label('clone_object') + [
            'group' => 'cms', 'safety' => ['internal_write'],
            'config_schema' => $object(['sourceExpr' => $expr, 'exclude' => $list, 'save' => $bool, 'storeAs' => $storeAs]),
            'default_config' => ['sourceExpr' => 'vars.target', 'exclude' => [], 'save' => true, 'storeAs' => 'vars.clone'],
        ],
        'delete_object' => $label('delete_object') + [
            'group' => 'cms', 'safety' => ['internal_write'], 'approval_default' => 'on',
            'config_schema' => $object(['targetExpr' => $expr, 'force' => $bool]),
            'default_config' => ['targetExpr' => 'vars.target', 'force' => false],
        ],
        'commit_objects' => $label('commit_objects') + [
            'group' => 'cms', 'safety' => ['internal_write'],
            'config_schema' => $object(['targetExpr' => $any, 'withEvents' => $bool]),
            'default_config' => ['targetExpr' => 'vars.target'],
        ],

        // AI (outputs are untrusted)
        'ai_action' => $label('ai_action') + [
            'group' => 'ai', 'safety' => ['ai_spend'], 'taint_output' => true,
            'config_schema' => $object([
                'agentProfileSlug' => $str, 'toolName' => $str, 'toolClass' => $str, 'moduleScope' => $str, 'goal' => $str,
                'toolInput' => $map, 'storeAs' => $storeAs, 'artifactType' => $str, 'environment' => $str,
                'policyScope' => $str, 'renderHint' => $str, 'sourceRunId' => $any, 'tenantId' => $any,
                'triggerMode' => $str, 'triggerReferenceId' => $any, 'triggerReferenceType' => $str,
                // Rewrite of a revisable tool proposal: off with `allow_revision: false`; round cap 1..5.
                'allow_revision' => $bool, 'max_rounds' => $int,
                // Assignee and SLA of the proposal approval (every round): user uuids (max 10) and a target time to
                // decide (seconds or `30m`, `4h`, `2d`; 5 minutes..30 days). Unknown uuids are refused on save.
                'assignees' => ['type' => ['array', 'null'], 'items' => ['type' => 'string'], 'maxItems' => 10],
                'sla' => ['type' => ['string', 'integer', 'null']],
            ]),
            'default_config' => ['agentProfileSlug' => '', 'toolName' => '', 'moduleScope' => '', 'goal' => '', 'storeAs' => 'vars.agent_result', 'toolInput' => []],
            // `rejected` (strict): a reviewer rejected the proposal; wired, the run continues there with
            // `vars.<storeAs>` = {decision, reason, note, approval_id}; unwired, a reject ends the run as before.
            'outputs' => ['main', 'rejected'],
        ],
        'ai_reasoning' => $label('ai_reasoning') + [
            'group' => 'ai', 'safety' => ['ai_spend'], 'taint_output' => true,
            'config_schema' => $object([
                'agentProfileSlug' => $str, 'goal' => $str, 'maxIterations' => $int, 'moduleScope' => $str,
                'environment' => $str, 'renderHint' => $str, 'tenantId' => $any,
            ]),
            'default_config' => ['agentProfileSlug' => '', 'goal' => '', 'maxIterations' => 5],
        ],

        // Advanced
        'blueprint_callable' => $label('blueprint_callable') + [
            'group' => 'advanced', 'safety' => ['internal_write'],
            'config_schema' => $object([
                'callableType' => $str, 'class' => $str, 'method' => $str, 'argsMapping' => $map,
                'execution' => $map, 'storeAs' => $storeAs,
            ]),
            'palette' => false,
        ],
    ],
];
