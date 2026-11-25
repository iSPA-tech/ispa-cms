<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Ai;

use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;

/**
 * `ai_summarize`: summarise `text` to a `length` (`short` | `medium` | `long` or free words). Long text is
 * map-reduced: each `chunk_chars` chunk is summarised, then the partial summaries are summarised once more
 * (at most `settings.max_chunks` chunks). Result `{text, chunks}`.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Ai
 * @since   2026-11-24
 */
class AiSummarizeRunner extends AiNodeRunner
{
    protected function type(): string
    {
        return 'ai_summarize';
    }

    protected function execute(array $config, WorkflowContext $context, StepDefinition $step): StepRunResult
    {
        $text = $this->requiredText($config, 'text', $context);
        $length = $this->renderText($config['length'] ?? 'short', $context);
        $length = match ($length) {
            'short', '' => 'two or three sentences',
            'medium' => 'one paragraph',
            'long' => 'three to five paragraphs',
            default => mb_substr($length, 0, 100),
        };
        $chunks = TextChunker::chunks($text, max(500, (int) ($config['chunk_chars'] ?? config('core.workflow_nodes.ai.settings.chunk_chars', 6000))), false);
        $maxChunks = (int) config('core.workflow_nodes.ai.settings.max_chunks', 20);
        if (count($chunks) > $maxChunks) {
            throw new AiNodeFailure('the text needs ' . count($chunks) . " chunks; the limit is {$maxChunks}.");
        }

        $task = trim($this->renderText($config['instructions'] ?? '', $context));
        $task = $task !== '' ? $task : 'Summarise the text.';
        $trusted = $this->textTrusted($config);

        $parts = [];
        foreach ($chunks as $chunk) {
            $parts[] = $this->summarise($config, $context, $task, $chunk, count($chunks) > 1 ? 'one paragraph' : $length, $trusted);
        }
        $summary = count($parts) > 1
            // Partial summaries come from the model's reading of untrusted text: still untrusted.
            ? $this->summarise($config, $context, $task, implode("\n\n", $parts), $length, $trusted)
            : $parts[0];

        $result = ['text' => $summary, 'chunks' => count($chunks)];
        $this->storeResult($config, $context, $result);

        return StepRunResult::success($result, $this->logs);
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function summarise(array $config, WorkflowContext $context, string $task, string $text, string $length, bool $trusted): string
    {
        $system = "Summarise the text in {$length}. Keep the language of the text and only facts it states.\n"
            . 'Answer as {"text": "the summary"}.';
        $data = $this->callJson(
            $config,
            $context,
            $system,
            $trusted ? $task . "\n\ntext: " . $text : $task,
            $trusted ? [] : ['text' => $text],
            static fn (array $d): ?string => is_string($d['text'] ?? null) && trim($d['text']) !== '' ? null : 'text is missing',
        );

        return trim($data['text']);
    }
}
