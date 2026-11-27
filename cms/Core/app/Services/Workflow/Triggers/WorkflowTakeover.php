<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Triggers;

use Cms\Core\Models\EmailTemplate;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\Mail\ManagedEmailTemplates;
use Cms\Core\Services\Workflow\Setup\WorkflowSetupService;
use Cms\Core\Services\Workflow\Versioning\RunGraph;
use Cms\Core\Workflows\Runtime\Runners\Integration\EmailSendPolicy;
use Illuminate\Support\Facades\Log;

/**
 * Whether an active workflow already does a job that the site's built-in code would also do for an app event, so the
 * built-in path can step aside: one mail per job, never two ("bật workflow thì không gửi đường
 * thường, không bật thì gửi đường thường").
 *
 * Duties:
 * - SUBJECT_MAIL: an `email_send` step in template mode that mails the trigger's own person (the contact who wrote, the
 *   account that registered) with a template that will go out (class system, or business with its switch on).
 * - STAFF_NOTICE: a `notify` step (a notice to staff).
 *
 * Counted only on the PUBLISHED version of a project that is switched on right now (re-read here: the trigger index can
 * still list a project the circuit breaker paused, for up to its cache time), whose start node is `trigger_event` on
 * that event, and only for steps a setup answer did not switch off. Decided before the run, so a run that fails later
 * sends nothing; a failing run is reported by the workflow's own alerts. Fails open: any error answers false, so the
 * built-in path sends.
 *
 * @package Cms\Core\Services\Workflow\Triggers
 * @since   2026.11 (2026-11-28)
 */
final class WorkflowTakeover
{
    public const SUBJECT_MAIL = 'subject_mail';

    public const STAFF_NOTICE = 'staff_notice';

    public static function covers(string $event, string $duty): bool
    {
        try {
            $ids = array_values(array_unique(array_map(
                static fn (array $entry): int => (int) $entry['project_id'],
                TriggerIndex::forEvent($event),
            )));
            if ($ids === []) {
                return false;
            }

            $projects = WorkflowProject::query()->whereKey($ids)->where('is_active', true)
                ->whereNotNull('published_version_id')->get();
            foreach ($projects as $project) {
                $graph = RunGraph::publishedGraph($project);
                if ($graph === null || !self::startsOn($graph, $event)) {
                    continue;
                }
                $off = WorkflowSetupService::switchedOffSteps($graph);
                foreach ((array) ($graph['steps'] ?? []) as $step) {
                    if (!isset($off[(string) ($step['step_code'] ?? '')]) && self::does($step, $duty)) {
                        return true;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Workflow takeover check failed; the built-in path sends', [
                'event' => $event, 'duty' => $duty, 'error' => $e->getMessage(),
            ]);
        }

        return false;
    }

    /** @param array<string, mixed> $graph */
    private static function startsOn(array $graph, string $event): bool
    {
        foreach ((array) ($graph['steps'] ?? []) as $step) {
            if (!empty($step['is_start']) && ($step['step_type'] ?? null) === 'trigger_event'
                && (($step['step_config']['event'] ?? null) === $event)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $step */
    private static function does(array $step, string $duty): bool
    {
        $config = is_array($step['step_config'] ?? null) ? $step['step_config'] : [];

        return match ($duty) {
            self::SUBJECT_MAIL => ($step['step_type'] ?? null) === 'email_send' && self::mailsSubject($config),
            self::STAFF_NOTICE => ($step['step_type'] ?? null) === 'notify',
            default => false,
        };
    }

    /** @param array<string, mixed> $config */
    private static function mailsSubject(array $config): bool
    {
        if (EmailSendPolicy::mode($config) !== EmailSendPolicy::MODE_TEMPLATE
            || EmailSendPolicy::recipient($config) !== EmailSendPolicy::RECIPIENT_TRIGGER_SUBJECT) {
            return false;
        }
        $key = EmailSendPolicy::templateKey($config);
        $template = $key === null ? null : app(ManagedEmailTemplates::class)->find(EmailTemplate::GROUP_WORKFLOW, $key);
        if ($template === null) {
            return false;
        }

        // Same rule as EmailSendRunner: an unknown class counts as business, which sends only with its switch on.
        return $template->mail_class === EmailTemplate::CLASS_SYSTEM || EmailSendPolicy::businessEnabled($config);
    }
}
