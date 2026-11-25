<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Ai;

use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;

/**
 * `ai_classify`: pick label(s) for `text` from `labels` (`[{key, description}]` or a list of keys). `multi` allows
 * several labels; `fallback` is used when the model answers no valid label (else one retry, then fail).
 * Result `{label, labels, confidence}`; leaves by `case:<label>` (first label), or `default` when nothing matched.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Ai
 * @since   2026-11-24
 */
class AiClassifyRunner extends AiNodeRunner
{
    protected function type(): string
    {
        return 'ai_classify';
    }

    protected function execute(array $config, WorkflowContext $context, StepDefinition $step): StepRunResult
    {
        $labels = $this->labels($config['labels'] ?? []);
        if ($labels === []) {
            throw new AiNodeFailure('labels are empty.');
        }
        $multi = filter_var($config['multi'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $fallback = trim((string) ($config['fallback'] ?? ''));
        $fallback = isset($labels[$fallback]) ? $fallback : null;

        $list = [];
        foreach ($labels as $key => $description) {
            $list[] = '- ' . $key . ($description !== '' ? ': ' . $description : '');
        }
        $system = "Classify the text. Allowed labels:\n" . implode("\n", $list)
            . "\nAnswer as {\"labels\": [" . ($multi ? 'one or more label keys' : 'exactly one label key') . '], "confidence": a number from 0 to 1}.'
            . ' Use only the label keys above.';
        $task = trim($this->renderText($config['instructions'] ?? '', $context));
        [$task, $untrusted] = $this->textInput($config, $context, $task !== '' ? $task : 'Classify the text.');

        $chosen = [];
        $data = $this->callJson($config, $context, $system, $task, $untrusted, function (array $d) use ($labels, $multi, $fallback, &$chosen): ?string {
            $answer = array_values(array_unique(array_filter(
                array_map(static fn ($v): string => is_scalar($v) ? trim((string) $v) : '', (array) ($d['labels'] ?? $d['label'] ?? [])),
                static fn (string $v): bool => isset($labels[$v]),
            )));
            if (!$multi) {
                $answer = array_slice($answer, 0, 1);
            }
            if ($answer === [] && $fallback === null) {
                return 'no allowed label';
            }
            $chosen = $answer;

            return null;
        });
        if ($chosen === [] && $fallback !== null) {
            $chosen = [$fallback];
        }

        $confidence = is_numeric($data['confidence'] ?? null) ? max(0.0, min(1.0, (float) $data['confidence'])) : null;
        $result = ['label' => $chosen[0] ?? null, 'labels' => $chosen, 'confidence' => $confidence];
        $this->storeResult($config, $context, $result);

        return StepRunResult::success($result, $this->logs)->withOutput($chosen === [] ? 'default' : 'case:' . $chosen[0]);
    }

    /**
     * @return array<string, string> key => description
     */
    protected function labels(mixed $value): array
    {
        $labels = [];
        foreach ((array) $value as $index => $label) {
            $key = is_array($label) ? (string) ($label['key'] ?? '') : (is_string($index) ? $index : (string) $label);
            $description = is_array($label) ? (string) ($label['description'] ?? '') : (is_string($index) ? (string) $label : '');
            $key = trim($key);
            if ($key !== '' && preg_match('/^[A-Za-z0-9_.\-]{1,60}$/', $key)) {
                $labels[$key] = trim($description);
            }
        }

        return $labels;
    }
}
