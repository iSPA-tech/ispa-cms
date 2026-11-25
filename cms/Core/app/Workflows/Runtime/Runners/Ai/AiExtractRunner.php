<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Ai;

use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;

/**
 * `ai_extract`: structured fields from `text` (text or HTML). `schema` (JSON-schema subset, object) is required; the
 * answer must match it (one retry, then fail). Result: the extracted object.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Ai
 * @since   2026-11-24
 */
class AiExtractRunner extends AiNodeRunner
{
    protected function type(): string
    {
        return 'ai_extract';
    }

    protected function execute(array $config, WorkflowContext $context, StepDefinition $step): StepRunResult
    {
        $schema = $config['schema'] ?? null;
        if (!is_array($schema) || ($schema['type'] ?? null) !== 'object') {
            throw new AiNodeFailure('schema must be a JSON schema of type object.');
        }

        $system = 'Extract the requested fields from the text. Use only facts found in the text; leave a field null or '
            . 'out when the text does not say it. The JSON object must match this JSON schema: '
            . json_encode($schema, JSON_UNESCAPED_UNICODE);
        $task = trim($this->renderText($config['instructions'] ?? '', $context));
        [$task, $untrusted] = $this->textInput($config, $context, $task !== '' ? $task : 'Extract the fields.');

        $data = $this->callJson($config, $context, $system, $task, $untrusted, fn (array $d): ?string => $this->schemaError($schema, $d));
        $this->storeResult($config, $context, $data);

        return StepRunResult::success($data, $this->logs);
    }
}
