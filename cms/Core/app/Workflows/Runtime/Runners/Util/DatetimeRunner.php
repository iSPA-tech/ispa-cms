<?php

namespace Cms\Core\Workflows\Runtime\Runners\Util;

use Carbon\CarbonImmutable;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;

/**
 * Date/time operations stored at `storeAs` (ISO 8601 strings unless `format` is given).
 *
 * Ops: now, parse, format, add, sub (`amount`, `unit`), diff (`otherExpr`, result in `unit`), start_of, end_of
 * (`unit`), timezone (convert to `timezone`). `inputExpr` is the date (default now); `timezone` applies to output.
 */
class DatetimeRunner implements StepRunner
{
    use NodeHelpers;

    public const OPS = ['now', 'parse', 'format', 'add', 'sub', 'diff', 'start_of', 'end_of', 'timezone'];
    protected const UNITS = ['second', 'minute', 'hour', 'day', 'week', 'month', 'year'];

    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $config = $step->stepConfig;
        $op = (string) ($config['op'] ?? '');
        $path = $this->storePath($config, 'vars.datetime');
        $unit = rtrim(strtolower((string) ($config['unit'] ?? 'day')), 's');
        $tz = is_string($config['timezone'] ?? null) && $config['timezone'] !== '' ? $config['timezone'] : null;

        if (!in_array($op, self::OPS, true)) {
            return StepRunResult::failed('datetime: unknown op');
        }
        if ($path === null) {
            return StepRunResult::failed('datetime: storeAs must start with vars.');
        }
        if (in_array($op, ['add', 'sub', 'diff', 'start_of', 'end_of'], true) && !in_array($unit, self::UNITS, true)) {
            return StepRunResult::failed('datetime: unit must be one of ' . implode(', ', self::UNITS));
        }

        try {
            if ($tz !== null) {
                new \DateTimeZone($tz);
            }
            $date = $op === 'now' ? CarbonImmutable::now() : $this->toDate($this->evalField($config, 'inputExpr', $context), $tz);

            $value = match ($op) {
                'now', 'parse', 'format' => $date,
                'add' => $date->add($unit, (int) ($config['amount'] ?? 0)),
                'sub' => $date->sub($unit, (int) ($config['amount'] ?? 0)),
                'start_of' => $date->startOf($unit),
                'end_of' => $date->endOf($unit),
                'timezone' => $tz ? $date->setTimezone($tz) : $date,
                'diff' => (int) $date->diffInUnit($unit, $this->toDate($this->evalField($config, 'otherExpr', $context), $tz), false),
            };
        } catch (\Throwable $e) {
            return StepRunResult::failed('datetime: ' . $e->getMessage());
        }

        if ($value instanceof CarbonImmutable) {
            if ($tz !== null) {
                $value = $value->setTimezone($tz);
            }
            $format = is_string($config['format'] ?? null) && $config['format'] !== '' ? $config['format'] : null;
            $value = $format ? $value->format($format) : $value->toIso8601String();
        }

        $context->setVarPath($path, $value);

        return StepRunResult::success(['op' => $op, 'value' => $value]);
    }

    protected function toDate(mixed $value, ?string $tz): CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return CarbonImmutable::createFromTimestamp((int) $value);
        }
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException('input is not a date/time');
        }

        return CarbonImmutable::parse($value, $tz);
    }
}
