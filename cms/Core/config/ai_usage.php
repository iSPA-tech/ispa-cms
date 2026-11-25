<?php

return [
    // Model name => USD per 1M tokens: input, cached input (null = input price), output.
    // Standard-tier list prices read from the vendors' official pricing pages on 2026-10-21:
    //   OpenAI    https://developers.openai.com/api/docs/pricing
    //   Anthropic https://platform.claude.com/docs/en/about-claude/pricing
    //   xAI       https://docs.x.ai/developers/models
    // Only the base tier is listed; long-prompt tiers (OpenAI >272K, xAI >=200K, Claude Haiku >100K
    // input tokens) cost more, so the ledger under-estimates those calls. Models missing here
    // (e.g. gemini-3.5-flash, deepseek-v4-pro: no verified price) are recorded with tokens but cost 0.
    // Settings override per model:
    //   core.ai_runtime.pricing.{model}.input_per_1m_usd|cached_input_per_1m_usd|output_per_1m_usd
    'pricing' => [
        'text-embedding-3-small' => ['input_per_1m_usd' => 0.02, 'cached_input_per_1m_usd' => null, 'output_per_1m_usd' => 0.0],
        'gpt-5.6-luna' => ['input_per_1m_usd' => 0.20, 'cached_input_per_1m_usd' => 0.02, 'output_per_1m_usd' => 1.20],
        'gpt-5.6-terra' => ['input_per_1m_usd' => 2.00, 'cached_input_per_1m_usd' => 0.20, 'output_per_1m_usd' => 12.00],
        'gpt-5.6-sol' => ['input_per_1m_usd' => 4.00, 'cached_input_per_1m_usd' => 0.40, 'output_per_1m_usd' => 20.00],
        'claude-fable-5-1' => ['input_per_1m_usd' => 10.00, 'cached_input_per_1m_usd' => null, 'output_per_1m_usd' => 50.00],
        'claude-opus-5-5' => ['input_per_1m_usd' => 4.00, 'cached_input_per_1m_usd' => 0.20, 'output_per_1m_usd' => 20.00],
        'claude-sonnet-5-5' => ['input_per_1m_usd' => 2.00, 'cached_input_per_1m_usd' => 0.10, 'output_per_1m_usd' => 10.00],
        'claude-haiku-5-5' => ['input_per_1m_usd' => 0.10, 'cached_input_per_1m_usd' => 0.01, 'output_per_1m_usd' => 0.50],
        'claude-sonnet-4-5' => ['input_per_1m_usd' => 3.00, 'cached_input_per_1m_usd' => 0.30, 'output_per_1m_usd' => 15.00],
        'grok-4.3' => ['input_per_1m_usd' => 1.25, 'cached_input_per_1m_usd' => 0.20, 'output_per_1m_usd' => 2.50],
    ],

    // Percent of the monthly budget at which the one-off warning alert is raised.
    'warning_percent' => 80,

    // Daily spike alert (cms:ai-cost-spike-check): today's UTC cost vs the average daily cost of the
    // previous `baseline_days` full days. Alerts when today >= max(min_usd, multiplier x average),
    // at most once per UTC day. Settings override: core.ai_runtime.spike.enabled|multiplier|min_usd.
    'spike' => [
        'enabled' => true,
        'multiplier' => 3.0,
        'min_usd' => 2.0,
        'baseline_days' => 7,
    ],

    // Per-call trace log (core_ai_call_logs): metadata only, never prompt or response content.
    // sample_percent: share of successful calls logged (failures and refusals are always logged;
    // settings override core.ai_runtime.trace.sample_percent). retention_days: cms:ai-call-log-prune default.
    'trace' => [
        'sample_percent' => 100,
        'retention_days' => 14,
    ],

    // Operator-facing labels of the AI tasks owned by this module (task_key => label).
    'ai_runtime_tasks' => [
        'agentic_reasoning' => 'Suy luận của agent',
        'embeddings' => 'Tạo embedding',
        'jev_crawler_shadow' => 'Đối chiếu JEV (crawler, chạy ngầm)',
        'auto_moderation' => 'Kiểm duyệt tự động',
        'ai_runtime_connection_test' => 'Kiểm tra kết nối nhà cung cấp AI',
        'copyright_safe_news_fact_extraction' => 'Trích xuất dữ kiện tin (an toàn bản quyền)',
        'copyright_safe_news_drafting' => 'Soạn tin (an toàn bản quyền)',
        'copyright_safe_news_legal_editorial_review' => 'Rà soát pháp lý và biên tập tin',
        // Workflow AI nodes (cms/Core/config/workflow_nodes/ai.php): one task per node type.
        'workflow_ai_prompt' => 'Quy trình: câu lệnh AI',
        'workflow_ai_classify' => 'Quy trình: phân loại bằng AI',
        'workflow_ai_extract' => 'Quy trình: trích xuất dữ liệu bằng AI',
        'workflow_ai_translate' => 'Quy trình: dịch bằng AI',
        'workflow_ai_rewrite' => 'Quy trình: viết lại bằng AI',
        'workflow_ai_score' => 'Quy trình: chấm điểm bằng AI',
        'workflow_ai_summarize' => 'Quy trình: tóm tắt bằng AI',
        'workflow_ai_guardrail' => 'Quy trình: kiểm tra an toàn nội dung bằng AI',
        // Human nodes (cms/Core/config/workflow_nodes/human.php): the jev_review node's judge call.
        'workflow_jev_review' => 'Quy trình: JEV đánh giá nội dung',
    ],
];
