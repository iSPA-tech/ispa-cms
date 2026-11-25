<?php

namespace Cms\Core\Services\Workflow\Triggers;

use Cms\Core\Jobs\RunWorkflowInstanceJob;
use Cms\Core\Workflows\Security\WorkflowCallableGuard;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Registers the listeners the triggers need, once per event dispatcher:
 * - Eloquent created/updated/deleted wildcard → ModelEventTriggerListener (`trigger_model_event`);
 * - every event on the `core.workflow_security.trigger_events` / `events` allowlists → AppEventTriggerListener
 *   (`trigger_event`);
 * - JobProcessing / JobAttempted of RunWorkflowInstanceJob → TriggerChain (which run is executing in this process).
 *
 * Called once per application boot from CoreServiceProvider::boot().
 *
 * @package Cms\Core\Services\Workflow\Triggers
 * @since   2026.11 (2026-11-24)
 */
final class WorkflowTriggerBootstrap
{
    /** @var \WeakMap<Dispatcher, true>|null Dispatchers already wired (a new application gets a new dispatcher). */
    private static ?\WeakMap $registered = null;

    public static function register(Dispatcher $events): void
    {
        self::$registered ??= new \WeakMap();
        if (isset(self::$registered[$events])) {
            return;
        }
        self::$registered[$events] = true;

        TriggerChain::reset();
        TriggerIndex::flushMemo();

        $events->listen(
            ['eloquent.created: *', 'eloquent.updated: *', 'eloquent.deleted: *'],
            static fn (string $eventName, array $payload) => ModelEventTriggerListener::handle($eventName, $payload),
        );

        $patterns = [...array_keys(app(WorkflowCallableGuard::class)->triggerEvents()), ...(array) config('core.workflow_security.events', [])];
        foreach (array_unique(array_filter($patterns, 'is_string')) as $pattern) {
            if (! is_string($pattern) || trim($pattern) === '') {
                continue;
            }
            $pattern = ltrim(trim($pattern), '\\');
            $name = str_ends_with($pattern, '\\') ? $pattern . '*' : $pattern;
            $events->listen($name, static function (...$arguments) use ($name): void {
                // Wildcard listeners get ($eventName, $payload); exact ones get the event itself.
                if (str_contains($name, '*')) {
                    AppEventTriggerListener::handle((string) $arguments[0], (array) ($arguments[1] ?? []));

                    return;
                }
                AppEventTriggerListener::handle($name, $arguments);
            });
        }

        $events->listen(JobProcessing::class, static function (JobProcessing $event): void {
            $id = self::instanceIdOf($event->job);
            if ($id !== null) {
                TriggerChain::enter($id);
            }
        });
        $events->listen(JobAttempted::class, static function (JobAttempted $event): void {
            $id = self::instanceIdOf($event->job);
            if ($id !== null) {
                TriggerChain::leave($id);
            }
        });
    }

    /**
     * Whether the listeners are already wired on this dispatcher.
     */
    public static function isRegistered(Dispatcher $events): bool
    {
        return self::$registered !== null && isset(self::$registered[$events]);
    }

    /**
     * Instance id of a queued RunWorkflowInstanceJob, or null for any other job.
     */
    protected static function instanceIdOf(mixed $job): ?int
    {
        try {
            $payload = $job->payload();
            if (($payload['displayName'] ?? null) !== RunWorkflowInstanceJob::class) {
                return null;
            }
            $command = unserialize((string) ($payload['data']['command'] ?? ''), ['allowed_classes' => [RunWorkflowInstanceJob::class]]);

            return $command instanceof RunWorkflowInstanceJob ? $command->workflowInstanceId() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
