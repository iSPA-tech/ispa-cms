<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Ai;

use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;

/**
 * `ai_rewrite`: rewrite `text` to a `style`, `audience` and `length`; `keep_facts` (default true) forbids adding or
 * dropping facts. Result `{text}`.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Ai
 * @since   2026-11-24
 */
class AiRewriteRunner extends AiNodeRunner
{
    protected function type(): string
    {
        return 'ai_rewrite';
    }

    protected function execute(array $config, WorkflowContext $context, StepDefinition $step): StepRunResult
    {
        $rules = array_filter([
            ($style = $this->renderText($config['style'] ?? '', $context)) !== '' ? 'Style: ' . $style : '',
            ($audience = $this->renderText($config['audience'] ?? '', $context)) !== '' ? 'Audience: ' . $audience : '',
            ($length = $this->renderText($config['length'] ?? '', $context)) !== '' ? 'Length: ' . $length : '',
            filter_var($config['keep_facts'] ?? true, FILTER_VALIDATE_BOOLEAN)
                ? 'Keep every fact, name, number and date; add none.' : '',
        ]);
        $system = "Rewrite the text.\n" . implode("\n", $rules) . "\nAnswer as {\"text\": \"the rewritten text\"}. Keep the language of the text.";
        $task = trim($this->renderText($config['instructions'] ?? '', $context));
        [$task, $untrusted] = $this->textInput($config, $context, $task !== '' ? $task : 'Rewrite the text.');

        $data = $this->callJson($config, $context, $system, $task, $untrusted, static fn (array $d): ?string => is_string($d['text'] ?? null) && trim($d['text']) !== '' ? null : 'text is missing');
        $result = ['text' => trim($data['text'])];
        $this->storeResult($config, $context, $result);

        return StepRunResult::success($result, $this->logs);
    }
}
