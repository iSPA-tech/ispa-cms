<?php

/*
 * AI node set (lane N1): ai_prompt, ai_classify, ai_extract, ai_translate, ai_rewrite, ai_score (P1),
 * ai_summarize, ai_guardrail (P2). Merged as `core.workflow_nodes.ai`; NodeRegistry::runnerMap()/definitionRows()
 * pick up `step_runners` and `definitions`.
 *
 * Every node: safety `ai_spend` (budget caps checked before it), `taint_output` (its output is untrusted), calls go
 * through AiTaskRuntime with `task_key` (default `workflow_<type>`, listed in core.ai_usage.ai_runtime_tasks so the
 * AI Runtime page can set provider/model per task), and the step reports real tokens and cost via withUsage().
 */

use Cms\Core\Workflows\Runtime\Runners\Ai\AiClassifyRunner;
use Cms\Core\Workflows\Runtime\Runners\Ai\AiExtractRunner;
use Cms\Core\Workflows\Runtime\Runners\Ai\AiGuardrailRunner;
use Cms\Core\Workflows\Runtime\Runners\Ai\AiPromptRunner;
use Cms\Core\Workflows\Runtime\Runners\Ai\AiRewriteRunner;
use Cms\Core\Workflows\Runtime\Runners\Ai\AiScoreRunner;
use Cms\Core\Workflows\Runtime\Runners\Ai\AiSummarizeRunner;
use Cms\Core\Workflows\Runtime\Runners\Ai\AiTranslateRunner;

$expr = ['type' => ['string', 'number', 'integer', 'boolean', 'null'], 'x-expr' => true];
$str = ['type' => ['string', 'null']];
$int = ['type' => ['integer', 'string', 'null']];
$num = ['type' => ['number', 'integer', 'string', 'null']];
$bool = ['type' => ['boolean', 'string', 'integer', 'null']];
$list = ['type' => ['array', 'object', 'null']];
$storeAs = ['type' => ['string', 'null'], 'x-store' => true];
$object = static fn (array $properties): array => ['type' => 'object', 'properties' => $properties];
$label = static fn (string $type): array => [
    'label_key' => "text.workflow.node.{$type}.title",
    'description_key' => "text.workflow.node.{$type}.desc",
];

// Config shared by every AI node.
$shared = [
    'task_key' => $str,
    // Optional "provider:model" from GET ai-runtime/selectable-models; empty = the AI Runtime task setting.
    'model_choice' => $str,
    'system' => $expr,
    'instructions' => $expr,
    'inputs' => $list,
    'max_tokens' => $int,
    'on_invalid_output' => ['type' => ['string', 'null'], 'enum' => ['retry', 'fail', null]],
    // Reviewer's rewrite request, e.g. `vars.approval` wired from an approval node's `changes_requested` output.
    'revision' => $expr,
    'storeAs' => $storeAs,
];
// Nodes whose main input is `text`; `text_trusted` keeps it out of the untrusted block (author text only).
$textNode = $shared + ['text' => $expr, 'text_trusted' => $bool];
$ai = static fn (string $type, array $properties, array $default, array $outputs = ['main']): array => $label($type) + [
    'group' => 'ai', 'icon' => 'robot',
    'safety' => ['ai_spend'], 'approval_default' => 'off', 'taint_output' => true,
    'config_schema' => $object($properties),
    'default_config' => $default,
    'outputs' => $outputs,
];

// One result field for the designer's data picker (NodeDefinition::$result); `item` = parts of each list element.
$part = static fn (string $key, string $type, string $label, array $item = []): array => ['key' => $key, 'type' => $type, 'label' => $label] + ($item === [] ? [] : ['item' => $item]);

return [
    'step_runners' => [
        'ai_prompt' => AiPromptRunner::class,
        'ai_classify' => AiClassifyRunner::class,
        'ai_extract' => AiExtractRunner::class,
        'ai_translate' => AiTranslateRunner::class,
        'ai_rewrite' => AiRewriteRunner::class,
        'ai_score' => AiScoreRunner::class,
        'ai_summarize' => AiSummarizeRunner::class,
        'ai_guardrail' => AiGuardrailRunner::class,
    ],

    'definitions' => [
        'ai_prompt' => $ai('ai_prompt', $shared + [
            'output' => ['type' => ['string', 'null'], 'enum' => ['text', 'json', null]],
            'schema' => $list,
        ], ['instructions' => '', 'inputs' => [], 'output' => 'text', 'storeAs' => 'vars.prompt']) + [
            // `data` (output json) holds the fields of the step's own `schema`.
            'result' => [$part('text', 'string', 'Text'), $part('data', 'object', 'Answer data') + ['dynamic' => true]], 'dynamic' => 'schema',
        ],
        // `labels` may come from a label preset (GET api/v2/admin/workflows/presets?kind=classify_labels); the chosen
        // label is one of them (`values_from`).
        'ai_classify' => $ai('ai_classify', $textNode + ['labels' => $list + ['x-required' => true, 'x-setup' => 'required_field'], 'multi' => $bool, 'fallback' => $str],
            ['text' => '', 'labels' => [], 'multi' => false, 'storeAs' => 'vars.classify'], ['default']) + [
            'result' => [
                $part('label', 'string', 'Chosen label') + ['values_from' => 'labels'],
                $part('labels', 'list', 'Chosen labels') + ['values_from' => 'labels'],
                $part('confidence', 'number', 'Confidence'),
            ],
        ],
        'ai_extract' => $ai('ai_extract', $textNode + ['schema' => $list],
            ['text' => '', 'schema' => ['type' => 'object', 'properties' => []], 'storeAs' => 'vars.extract']) + [
            'result' => [], 'dynamic' => 'schema',
        ],
        'ai_translate' => $ai('ai_translate', $textNode + [
            'target_locale' => $expr, 'glossary' => ['type' => ['array', 'object', 'string', 'null'], 'x-expr' => true],
            'html_preserve' => $bool, 'chunk_chars' => $int,
        ], ['text' => '', 'target_locale' => 'vi', 'html_preserve' => false, 'storeAs' => 'vars.translate']) + [
            'result' => [
                $part('text', 'string', 'Text'),
                $part('segments', 'number', 'Number of parts'),
                $part('target_locale', 'string', 'Target language'),
            ],
        ],
        'ai_rewrite' => $ai('ai_rewrite', $textNode + ['style' => $expr, 'audience' => $expr, 'length' => $expr, 'keep_facts' => $bool],
            ['text' => '', 'style' => '', 'keep_facts' => true, 'storeAs' => 'vars.rewrite']) + [
            'result' => [$part('text', 'string', 'Text')],
        ],
        'ai_score' => $ai('ai_score', $textNode + ['rubric' => $list, 'threshold' => $num],
            ['text' => '', 'rubric' => [], 'threshold' => 70, 'storeAs' => 'vars.score'], ['pass', 'fail']) + [
            'result' => [
                $part('score', 'number', 'Score'),
                $part('scores', 'object', 'Score per criterion'),
                $part('reasons', 'object', 'Reasons'),
                $part('verdict', 'string', 'Verdict') + ['values' => [['value' => 'pass', 'label' => 'Pass'], ['value' => 'fail', 'label' => 'Fail']]],
                $part('threshold', 'number', 'Pass mark'),
            ],
        ],
        'ai_summarize' => $ai('ai_summarize', $textNode + ['length' => $expr, 'chunk_chars' => $int],
            ['text' => '', 'length' => 'short', 'storeAs' => 'vars.summarize']) + [
            'result' => [$part('text', 'string', 'Text'), $part('chunks', 'number', 'Number of parts read')],
        ],
        'ai_guardrail' => $ai('ai_guardrail', $shared + [
            'text' => $expr, 'checks' => $list, 'policy' => $expr,
        ], ['text' => '', 'checks' => ['prompt_injection', 'pii', 'abuse'], 'storeAs' => 'vars.guardrail'], ['pass', 'blocked']) + [
            'result' => [
                $part('safe', 'boolean', 'Safe'),
                $part('flags', 'list', 'Problems found', [$part('check', 'string', 'Check'), $part('reason', 'string', 'Reason')]),
            ],
        ],
    ],

    'settings' => [
        // Model sent when the task has no AI Runtime row (the row's provider/model wins). Cheap by default.
        'default_model' => 'gpt-5.6-luna',
        'max_tokens' => 1024,
        'max_tokens_cap' => 16000,
        // ai_translate / ai_summarize chunking: characters per chunk and the most chunks (= AI calls) per step.
        'chunk_chars' => 6000,
        'max_chunks' => 20,
    ],
];
