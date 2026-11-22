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
    'max_steps' => 200,
    'max_duration_seconds' => 60,
    'max_loop_items' => 100,
];
