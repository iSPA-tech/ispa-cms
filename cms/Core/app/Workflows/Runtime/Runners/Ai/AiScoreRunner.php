<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Ai;

use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;

/**
 * `ai_score` (critic): score `text` against a `rubric` (`[{key, weight, description}]`), each criterion 0-100. The
 * weighted average is the score; `score >= threshold` (default 70) leaves by `pass`, else by `fail`.
 * Result `{score, scores, reasons, verdict, threshold}`. A `fail` never falls through to a lone `pass` edge.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Ai
 * @since   2026-11-24
 */
class AiScoreRunner extends AiNodeRunner
{
    public const OUTPUTS = ['pass', 'fail'];

    protected function type(): string
    {
        return 'ai_score';
    }

    protected function execute(array $config, WorkflowContext $context, StepDefinition $step): StepRunResult
    {
        $rubric = $this->rubric($config['rubric'] ?? []);
        if ($rubric === []) {
            throw new AiNodeFailure('rubric is empty.');
        }
        $threshold = is_numeric($config['threshold'] ?? null) ? max(0.0, min(100.0, (float) $config['threshold'])) : 70.0;

        $lines = [];
        foreach ($rubric as $key => $criterion) {
            $lines[] = "- {$key}" . ($criterion['description'] !== '' ? ': ' . $criterion['description'] : '');
        }
        $system = "Score the text against each criterion from 0 (fails it) to 100 (fully meets it). Be strict.\nCriteria:\n"
            . implode("\n", $lines)
            . "\nAnswer as {\"scores\": {criterion key: number}, \"reasons\": {criterion key: one short sentence}}.";
        $task = trim($this->renderText($config['instructions'] ?? '', $context));
        [$task, $untrusted] = $this->textInput($config, $context, $task !== '' ? $task : 'Score the text.');

        $data = $this->callJson($config, $context, $system, $task, $untrusted, static function (array $d) use ($rubric): ?string {
            foreach (array_keys($rubric) as $key) {
                if (!is_numeric($d['scores'][$key] ?? null)) {
                    return "score for {$key} is missing";
                }
            }

            return null;
        });

        $scores = [];
        $reasons = [];
        $total = 0.0;
        $weights = 0.0;
        foreach ($rubric as $key => $criterion) {
            $scores[$key] = max(0.0, min(100.0, (float) $data['scores'][$key]));
            $reasons[$key] = is_scalar($data['reasons'][$key] ?? null) ? mb_substr(trim((string) $data['reasons'][$key]), 0, 500) : '';
            $total += $scores[$key] * $criterion['weight'];
            $weights += $criterion['weight'];
        }
        $score = round($weights > 0 ? $total / $weights : 0.0, 2);
        $verdict = $score >= $threshold ? 'pass' : 'fail';

        $result = ['score' => $score, 'scores' => $scores, 'reasons' => $reasons, 'verdict' => $verdict, 'threshold' => $threshold];
        $this->storeResult($config, $context, $result);

        return $this->routeOutput(StepRunResult::success($result, $this->logs), $step, $verdict, self::OUTPUTS, $verdict === 'fail');
    }

    /**
     * @return array<string, array{weight: float, description: string}>
     */
    protected function rubric(mixed $value): array
    {
        $rubric = [];
        foreach ((array) $value as $index => $row) {
            $key = trim(is_array($row) ? (string) ($row['key'] ?? '') : (is_string($index) ? $index : (string) $row));
            if ($key === '' || !preg_match('/^[A-Za-z0-9_.\-]{1,60}$/', $key)) {
                continue;
            }
            $weight = is_array($row) && is_numeric($row['weight'] ?? null) ? max(0.0, (float) $row['weight']) : 1.0;
            $rubric[$key] = [
                'weight' => $weight,
                'description' => is_array($row) ? trim((string) ($row['description'] ?? '')) : (is_string($index) ? trim((string) $row) : ''),
            ];
        }

        return $rubric;
    }
}
