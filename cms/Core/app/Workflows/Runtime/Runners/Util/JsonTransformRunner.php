<?php

namespace Cms\Core\Workflows\Runtime\Runners\Util;

use Cms\Core\Services\Ai\JsonSchemaShapeValidator;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Support\Arr;

/**
 * JSON operations on `inputExpr`: parse, stringify, pick / omit (`fields`, dot paths), validate (`schema`).
 *
 * `validate` leaves by `valid` or `invalid` and stores `{valid, errors}` at `storeAs`; the other ops store the value.
 */
class JsonTransformRunner implements StepRunner
{
    use NodeHelpers;

    public const OPS = ['parse', 'stringify', 'pick', 'omit', 'validate'];
    public const HANDLES = ['valid', 'invalid'];

    public function __construct(protected ?JsonSchemaShapeValidator $validator = null)
    {
    }

    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $config = $step->stepConfig;
        $op = (string) ($config['op'] ?? '');
        $path = $this->storePath($config, 'vars.json');

        if (!in_array($op, self::OPS, true)) {
            return StepRunResult::failed('json_transform: unknown op');
        }
        if ($path === null) {
            return StepRunResult::failed('json_transform: storeAs must start with vars.');
        }

        try {
            $input = $this->evalField($config, 'inputExpr', $context);
        } catch (\Throwable $e) {
            return StepRunResult::failed('json_transform: ' . $e->getMessage());
        }

        if ($op === 'parse') {
            if (!is_string($input)) {
                return StepRunResult::failed('json_transform: parse needs a JSON string');
            }
            try {
                $value = json_decode($input, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return StepRunResult::failed('json_transform: invalid JSON: ' . $e->getMessage());
            }
            $context->setVarPath($path, $value);

            return StepRunResult::success(['op' => $op]);
        }

        if ($op === 'stringify') {
            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | (!empty($config['pretty']) ? JSON_PRETTY_PRINT : 0);
            $context->setVarPath($path, (string) json_encode($input, $flags));

            return StepRunResult::success(['op' => $op]);
        }

        if ($op === 'validate') {
            $schema = $config['schema'] ?? null;
            if (is_string($schema)) {
                $schema = json_decode($schema, true);
            }
            if (!is_array($schema) || $schema === []) {
                return StepRunResult::failed('json_transform: validate needs a schema');
            }
            if (is_string($input)) {
                $decoded = json_decode($input, true);
                $input = json_last_error() === JSON_ERROR_NONE ? $decoded : $input;
            }
            $errors = array_values(($this->validator ?? new JsonSchemaShapeValidator())->validate($schema, $input));
            $valid = $errors === [];
            $context->setVarPath($path, ['valid' => $valid, 'errors' => $errors]);

            return $this->routeOutput(
                StepRunResult::success(['valid' => $valid, 'error_count' => count($errors)]),
                $step,
                $valid ? 'valid' : 'invalid',
                self::HANDLES,
                !$valid,
            );
        }

        $data = is_object($input) ? json_decode((string) json_encode($input), true) : $input;
        if (!is_array($data)) {
            return StepRunResult::failed("json_transform: {$op} needs an object");
        }
        $fields = array_values(array_filter((array) ($config['fields'] ?? []), 'is_string'));
        if ($op === 'pick') {
            $picked = [];
            foreach ($fields as $field) {
                if (Arr::has($data, $field)) {
                    Arr::set($picked, $field, Arr::get($data, $field));
                }
            }
            $data = $picked;
        } else {
            Arr::forget($data, $fields);
        }
        $context->setVarPath($path, $data);

        return StepRunResult::success(['op' => $op]);
    }
}
