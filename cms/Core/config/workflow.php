<?php

return [
    /**
     * Map of step_type => runner class.
     *
     * Register concrete runners here when they are implemented.
     */
    'step_runners' => [
        'set_variable' => \Cms\Core\Workflows\Runtime\Runners\SetVariableRunner::class,
        'decision' => \Cms\Core\Workflows\Runtime\Runners\DecisionRunner::class,
        'condition' => \Cms\Core\Workflows\Runtime\Runners\DecisionRunner::class, // backward-compat
        'retrieve' => \Cms\Core\Workflows\Runtime\Runners\RetrieveRunner::class,
        'change' => \Cms\Core\Workflows\Runtime\Runners\ChangeRunner::class,
        'blueprint_callable' => \Cms\Core\Workflows\Runtime\Runners\BlueprintCallableRunner::class,
        'ai_action' => \Cms\Core\Workflows\Runtime\Runners\AgentActionRunner::class,
        'ai_reasoning' => \Cms\Core\Workflows\Runtime\Runners\ReasoningStepRunner::class,
        'clear_variable' => \Cms\Core\Workflows\Runtime\Runners\ClearVariableRunner::class,
        'copy_variable' => \Cms\Core\Workflows\Runtime\Runners\CopyVariableRunner::class,
        'loop_each' => \Cms\Core\Workflows\Runtime\Runners\LoopEachRunner::class,
        'break_loop' => \Cms\Core\Workflows\Runtime\Runners\BreakLoopRunner::class,
        'assert_condition' => \Cms\Core\Workflows\Runtime\Runners\AssertConditionRunner::class,
        'delay' => \Cms\Core\Workflows\Runtime\Runners\DelayRunner::class,
        'terminate_workflow' => \Cms\Core\Workflows\Runtime\Runners\TerminateWorkflowRunner::class,
        'log_message' => \Cms\Core\Workflows\Runtime\Runners\LogMessageRunner::class,
        'create_object' => \Cms\Core\Workflows\Runtime\Runners\CreateObjectRunner::class,
        'delete_object' => \Cms\Core\Workflows\Runtime\Runners\DeleteObjectRunner::class,
        'commit_objects' => \Cms\Core\Workflows\Runtime\Runners\CommitObjectsRunner::class,
        'aggregate_list' => \Cms\Core\Workflows\Runtime\Runners\AggregateListRunner::class,
        'list_operation' => \Cms\Core\Workflows\Runtime\Runners\ListOperationRunner::class,
        'clone_object' => \Cms\Core\Workflows\Runtime\Runners\CloneObjectRunner::class,
        'throw_exception' => \Cms\Core\Workflows\Runtime\Runners\ThrowExceptionRunner::class,
    ],

    'schema_cache_ttl' => 600,
    'allow_expression_db_preview' => false,
    // Steps one run may take in all, counted across continuations, delays and retries. Sized for a full loop of
    // max_loop_items with a body of up to ~19 steps; a cycle through a delay node ends here too.
    'max_steps' => (int) env('WORKFLOW_MAX_STEPS', 2000),
    // Upper bound for a step's `retry.max` (attempts in all, counted across rescheduled backoffs).
    'max_retry_attempts' => (int) env('WORKFLOW_MAX_RETRY_ATTEMPTS', 10),
    // Seconds one execution may run before it continues in a new queued job.
    'max_duration_seconds' => (int) env('WORKFLOW_MAX_DURATION_SECONDS', 60),
    // How many times one instance may continue after running out of time before it fails.
    'max_duration_continuations' => (int) env('WORKFLOW_MAX_DURATION_CONTINUATIONS', 20),
    'max_loop_items' => 100,
    // Cache store for trigger state every host must share (trigger index, debounce tokens, webhook nonces). Defaults
    // to the `redis` cache store when the queue runs on Redis (workers may be other processes or hosts), else the app
    // default store. Set WORKFLOW_TRIGGER_CACHE_STORE when the default is a per-host file cache on several hosts.
    'trigger_cache_store' => env('WORKFLOW_TRIGGER_CACHE_STORE', env('QUEUE_CONNECTION') === 'redis' ? 'redis' : null),
    // Minutes a sensitive change waits before it goes live when no second publisher exists (guardrails B3).
    'cooling_off_minutes' => (int) env('WORKFLOW_COOLING_OFF_MINUTES', 60),
];
