<?php

namespace Cms\Core\Services\Workflow\Triggers;

use Cms\Core\Workflows\Security\WorkflowCallableGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Generic observer behind `trigger_model_event`: on every Eloquent created/updated/deleted event, starts the active
 * projects whose start node listens to that model.
 *
 * Only models WorkflowCallableGuard::allowsModel() accepts (registry models, never workflow/auth/sensitive ones).
 * Node config: `events` (created | updated | deleted, default created), `fields` (updated only: at least one of them
 * changed), `filter` (attribute => value, compared as strings), `debounce_seconds` (trailing, per record). The
 * record payload holds the visible attributes (hidden fields are left out). A write made by a run of the same
 * project (or of any run in its chain) never starts it again (TriggerChain).
 *
 * @package Cms\Core\Services\Workflow\Triggers
 * @since   2026.11 (2026-11-24)
 */
class ModelEventTriggerListener
{
    public const EVENTS = ['created', 'updated', 'deleted'];

    /**
     * Wildcard listener: ('eloquent.<event>: <class>', [$model]).
     *
     * @param array<int, mixed> $payload
     */
    public static function handle(string $eventName, array $payload): void
    {
        if (! preg_match('/^eloquent\.(created|updated|deleted): (.+)$/', $eventName, $match)) {
            return;
        }

        $entries = TriggerIndex::forModel($match[2]);
        $model = $payload[0] ?? null;
        if ($entries === [] || ! $model instanceof Model) {
            return;
        }

        try {
            self::dispatchFor($match[1], $model, $entries);
        } catch (\Throwable $e) {
            // A trigger problem never breaks the write that raised the event.
            Log::warning('Workflow model-event trigger failed', ['event' => $eventName, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param list<array{project_id: int, step_id: int, config: array<string, mixed>}> $entries
     */
    protected static function dispatchFor(string $event, Model $model, array $entries): void
    {
        $class = get_class($model);
        if (! app(WorkflowCallableGuard::class)->allowsModel($class)) {
            return;
        }

        $chain = TriggerChain::current();

        foreach ($entries as $entry) {
            $config = $entry['config'];
            if (! self::matches($event, $model, $config)) {
                continue;
            }

            $refusal = TriggerChain::refusal($entry['project_id'], $chain);
            if ($refusal !== null) {
                Log::info('Workflow model-event trigger skipped', ['project_id' => $entry['project_id'], 'reason' => $refusal]);
                continue;
            }

            $meta = ['record' => [
                'model' => $class,
                'event' => $event,
                'id' => $model->getKey(),
                'uuid' => $model->getAttribute('uuid'),
                'attributes' => $model->attributesToArray(),
                'changed' => $event === 'updated' ? array_keys($model->getChanges()) : [],
            ]];

            $debounce = min(
                max(0, (int) ($config['debounce_seconds'] ?? 0)),
                max(0, (int) WorkflowTriggers::setting('max_debounce_seconds', 3600)),
            );

            if ($debounce > 0) {
                $key = 'core:workflow:debounce:' . $entry['project_id'] . ':' . md5($class . '#' . $model->getKey());
                $token = Str::random(16);
                TriggerIndex::cache()->put($key, $token, $debounce + 60);
                StartTriggeredRunJob::dispatch($entry['project_id'], $entry['step_id'], 'model_event', $meta, $chain, $key, $token)
                    ->delay(now()->addSeconds($debounce));
                continue;
            }

            StartTriggeredRunJob::dispatch($entry['project_id'], $entry['step_id'], 'model_event', $meta, $chain);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function matches(string $event, Model $model, array $config): bool
    {
        $events = array_values(array_intersect((array) ($config['events'] ?? ['created']), self::EVENTS));
        if (! in_array($event, $events === [] ? ['created'] : $events, true)) {
            return false;
        }

        $fields = array_values(array_filter((array) ($config['fields'] ?? []), 'is_string'));
        if ($event === 'updated' && $fields !== [] && ! $model->wasChanged($fields)) {
            return false;
        }

        foreach ((array) ($config['filter'] ?? []) as $field => $expected) {
            if (! is_string($field) || in_array($field, $model->getHidden(), true)) {
                return false;
            }
            if (self::scalar($model->getAttribute($field)) !== self::scalar($expected)) {
                return false;
            }
        }

        return true;
    }

    protected static function scalar(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? '1' : '0',
            $value instanceof \BackedEnum => (string) $value->value,
            is_scalar($value) || $value instanceof \Stringable => (string) $value,
            default => json_encode($value) ?: null,
        };
    }
}
