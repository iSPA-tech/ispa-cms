<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Ai;

use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;

/**
 * `ai_translate`: translate `text` into `target_locale`. `glossary` (`{term: translation}` map, list of
 * `{source, target}`, or an expression) fixes terms; `html_preserve` keeps the HTML tags (each chunk's tag sequence must
 * come back unchanged, one retry then fail); `chunk_chars` splits long text on block boundaries, one AI call per chunk
 * (at most `settings.max_chunks`). Result `{text, segments, target_locale}`.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Ai
 * @since   2026-11-24
 */
class AiTranslateRunner extends AiNodeRunner
{
    protected function type(): string
    {
        return 'ai_translate';
    }

    protected function execute(array $config, WorkflowContext $context, StepDefinition $step): StepRunResult
    {
        $locale = $this->renderText($config['target_locale'] ?? '', $context);
        if (!preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})?$/', $locale)) {
            throw new AiNodeFailure('target_locale must be a locale code such as vi or en-US.');
        }
        $html = filter_var($config['html_preserve'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $text = $this->requiredText($config, 'text', $context);
        $chunkChars = max(500, (int) ($config['chunk_chars'] ?? config('core.workflow_nodes.ai.settings.chunk_chars', 6000)));
        $chunks = TextChunker::chunks($text, $chunkChars, $html);
        $maxChunks = (int) config('core.workflow_nodes.ai.settings.max_chunks', 20);
        if (count($chunks) > $maxChunks) {
            throw new AiNodeFailure("the text needs " . count($chunks) . " chunks; the limit is {$maxChunks}.");
        }

        $glossary = $this->glossary($this->renderer->renderMixed($config['glossary'] ?? [], $context));
        $system = "Translate the text into the language with code {$locale}."
            . ($html ? ' The text is HTML: keep every tag, attribute and the tag order exactly; translate only the visible text.' : '')
            . ($glossary !== [] ? "\nThe data block has a glossary (source => target lines): translate those terms as it says." : '')
            . "\nAnswer as {\"text\": \"the translation\"}.";
        $task = trim($this->renderText($config['instructions'] ?? '', $context));
        $task = $task !== '' ? $task : 'Translate the text.';

        $out = [];
        foreach ($chunks as $chunk) {
            $untrusted = $this->textTrusted($config) ? [] : ['text' => $chunk];
            if ($glossary !== []) {
                $untrusted['glossary'] = $glossary;
            }
            $chunkTask = $this->textTrusted($config) ? $task . "\n\ntext: " . $chunk : $task;
            $expected = $html ? TextChunker::tagSequence($chunk) : null;
            $data = $this->callJson($config, $context, $system, $chunkTask, $untrusted, static function (array $d) use ($expected): ?string {
                if (!is_string($d['text'] ?? null) || trim($d['text']) === '') {
                    return 'text is missing';
                }
                if ($expected !== null && TextChunker::tagSequence($d['text']) !== $expected) {
                    return 'the HTML tags changed';
                }

                return null;
            });
            $out[] = $data['text'];
        }

        $result = ['text' => $html ? implode('', $out) : implode("\n\n", array_map('trim', $out)), 'segments' => count($chunks), 'target_locale' => $locale];
        $this->storeResult($config, $context, $result);

        return StepRunResult::success($result, $this->logs);
    }

    /**
     * @return list<string> "source => target" lines (at most 200)
     */
    protected function glossary(mixed $value): array
    {
        $lines = [];
        foreach (is_array($value) ? $value : [] as $source => $target) {
            if (is_array($target)) {
                $source = $target['source'] ?? $target['term'] ?? null;
                $target = $target['target'] ?? $target['translation'] ?? null;
            }
            if (is_scalar($source) && is_scalar($target) && trim((string) $source) !== '' && !is_int($source)) {
                $lines[] = trim((string) $source) . ' => ' . trim((string) $target);
            }
        }

        return array_slice($lines, 0, 200);
    }
}
