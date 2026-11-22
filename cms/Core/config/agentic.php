<?php

return [
    // Model used by OpenAiReasoningEngine (agent planning); GPT-4.x is being retired by OpenAI.
    'reasoning_model' => env('CORE_AGENTIC_REASONING_MODEL', 'gpt-5.6-terra'),
    'kill_switch' => (bool) env('AGENTIC_KILL_SWITCH', false),
    // Modules outside Core add their own namespaces, tool services and artifact appliers from their provider's boot().
    'discovery' => [
        'allowlisted_namespaces' => [
            'Cms\\Core\\Services\\',
            'Cms\\Content\\Services\\',
            'Cms\\Product\\Services\\',
            'Cms\\Education\\Services\\',
        ],
        'method_suffix' => '_tool',
        'require_attribute' => false,
        'default_environment' => env('APP_ENV', 'local'),
    ],
    'bootstrap_services' => [
        [
            'class' => \Cms\Core\Services\Agentic\WorkflowContextToolService::class,
            'module_scope' => 'core',
        ],
        [
            'class' => \Cms\Core\Services\Agentic\AgenticOpsToolService::class,
            'module_scope' => 'core',
        ],
        [
            'class' => \Cms\Content\Services\Agentic\PostReadToolService::class,
            'module_scope' => 'content',
        ],
        [
            'class' => \Cms\Content\Services\Agentic\PostProposalToolService::class,
            'module_scope' => 'content',
        ],
        [
            'class' => \Cms\Content\Services\Agentic\PostAnswerToolService::class,
            'module_scope' => 'content',
        ],
        [
            'class' => \Cms\Product\Services\Agentic\ProductReadToolService::class,
            'module_scope' => 'product',
        ],
        [
            'class' => \Cms\Product\Services\Agentic\ProductProposalToolService::class,
            'module_scope' => 'product',
        ],
        [
            'class' => \Cms\Education\Services\Agentic\AiEvaluationProposalToolService::class,
            'module_scope' => 'education',
        ],
    ],
    'artifact_appliers' => [
        'content_post_description_patch' => \Cms\Content\Services\Agentic\PostDescriptionArtifactApplier::class,
        'content_post_seo_patch' => \Cms\Content\Services\Agentic\PostSeoArtifactApplier::class,
        'product_catalog_normalization_patch' => \Cms\Product\Services\Agentic\ProductCatalogNormalizationArtifactApplier::class,
        'product_custom_flags_patch' => \Cms\Product\Services\Agentic\ProductCustomFlagsArtifactApplier::class,
        'product_content_patch' => \Cms\Product\Services\Agentic\ProductContentArtifactApplier::class,
        'product_description_patch' => \Cms\Product\Services\Agentic\ProductDescriptionArtifactApplier::class,
        'product_seo_patch' => \Cms\Product\Services\Agentic\ProductSeoArtifactApplier::class,
        'education_ai_evaluation_patch' => \Cms\Education\Services\Agentic\AiEvaluationArtifactApplier::class,
    ],
    'capability_classes' => [
        'class_0_read_only' => [
            'allowed_side_effect_classes' => ['read_only'],
            'approval_required' => false,
            'schedule_allowed' => false,
        ],
        'class_1_draft_generation' => [
            'allowed_side_effect_classes' => ['read_only', 'draft_write'],
            'approval_required' => false,
            'schedule_allowed' => false,
        ],
        'class_2_staged_mutation' => [
            'allowed_side_effect_classes' => ['read_only', 'draft_write', 'staged_write'],
            'approval_required' => true,
            'schedule_allowed' => false,
        ],
        'class_3_autonomous_maintenance' => [
            'allowed_side_effect_classes' => ['read_only', 'draft_write', 'staged_write', 'autonomous_maintenance'],
            'approval_required' => true,
            'schedule_allowed' => true,
        ],
    ],
    'policy_evaluation_order' => [
        'kill_switch',
        'capability_class',
        'profile_allowlist',
        'definition_safety',
        'binding_scope',
        'approval_requirement',
        'budget_guard',
    ],
    'scheduler' => [
        'enabled' => (bool) env('AGENTIC_SCHEDULER_ENABLED', false),
        'batch_limit' => (int) env('AGENTIC_SCHEDULER_BATCH_LIMIT', 20),
    ],
    'ai_provider' => [
        'driver' => env('AGENTIC_AI_PROVIDER_DRIVER', 'openai'),
    ],
    'embedding_provider' => [
        'driver' => env('AGENTIC_EMBEDDING_PROVIDER_DRIVER', 'openai'),
    ],
];
