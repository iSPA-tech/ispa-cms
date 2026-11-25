<?php

namespace Cms\Core\Services\Workflow\Triggers;

use Cms\Core\Workflows\Security\WorkflowCallableGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Listener behind `trigger_event`: starts the active projects whose start node names the app event that fired.
 *
 * Only events on the `core.workflow_security.trigger_events` or `events` allowlists are listened to (deny by default)
 * and the guard is asked again when one fires. The payload is the event's public properties; models become {model, id, uuid}.
 *
 * @package Cms\Core\Services\Workflow\Triggers
 * @since   2026.11 (2026-11-24)
 */
class AppEventTriggerListener
{
    /**
     * Wildcard-capable listener: ($eventName, $payload).
     *
     * @param array<int, mixed> $payload
     */
    public static function handle(string $eventName, array $payload): void
    {
        $entries = TriggerIndex::forEvent($eventName);
        if ($entries === []) {
            return;
        }

        try {
            if (! app(WorkflowCallableGuard::class)->allowsTriggerEvent($eventName)) {
                return;
            }

            $data = self::normalize(is_object($payload[0] ?? null) ? get_object_vars($payload[0]) : $payload, 0);
            $chain = TriggerChain::current();
            foreach ($entries as $entry) {
                if (TriggerChain::refusal($entry['project_id'], $chain) !== null) {
                    continue;
                }

                StartTriggeredRunJob::dispatch($entry['project_id'], $entry['step_id'], 'event', [
                    'event' => ['name' => $eventName, 'payload' => $data],
                ], $chain);
            }
        } catch (\Throwable $e) {
            Log::warning('Workflow event trigger failed', ['event' => $eventName, 'error' => $e->getMessage()]);
        }
    }

    protected static function normalize(mixed $value, int $depth): mixed
    {
        if ($value instanceof Model) {
            return ['model' => get_class($value), 'id' => $value->getKey(), 'uuid' => $value->getAttribute('uuid')];
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (is_array($value)) {
            if ($depth >= 4) {
                return null;
            }
            $out = [];
            foreach (array_slice($value, 0, 100, true) as $key => $item) {
                $out[$key] = self::normalize($item, $depth + 1);
            }

            return $out;
        }

        return is_scalar($value) || $value === null ? $value : null;
    }
}
