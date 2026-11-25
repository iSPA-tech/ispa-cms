<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Ai;

use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;

/**
 * `ai_prompt`: one prompt to the model. `output` = `text` (result `{text}`) or `json` (result `{data}`; an optional
 * `schema` is checked with JsonSchemaShapeValidator, one retry on an invalid answer). Output handle: main.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Ai
 * @since   2026-11-24
 */
class AiPromptRunner extends AiNodeRunner
{
    protected function type(): string
    {
        return 'ai_prompt';
    }

    protected function execute(array $config, WorkflowContext $context, StepDefinition $step): StepRunResult
    {
        $task = $this->renderText($config['instructions'] ?? '', $context);
        if ($task === '') {
            throw new AiNodeFailure('instructions are empty.');
        }

        if (($config['output'] ?? 'text') === 'json') {
            $schema = is_array($config['schema'] ?? null) && $config['schema'] !== [] ? $config['schema'] : null;
            $system = 'You are a careful assistant inside an automated workflow.'
                . ($schema !== null ? "\nThe JSON object must match this JSON schema: " . json_encode($schema, JSON_UNESCAPED_UNICODE) : '');
            $data = $this->callJson($config, $context, $system, $task, [], fn (array $d): ?string => $schema !== null ? $this->schemaError($schema, $d) : null);
            $result = ['data' => $data];
        } else {
            $text = $this->call($config, $context, 'You are a careful assistant inside an automated workflow.', $task);
            if ($text === '') {
                throw new AiNodeFailure('the model returned no text.');
            }
            $result = ['text' => $text];
        }

        $this->storeResult($config, $context, $result);

        return StepRunResult::success($result, $this->logs);
    }
}
