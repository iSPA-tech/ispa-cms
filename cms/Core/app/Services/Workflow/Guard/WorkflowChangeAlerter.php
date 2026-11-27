<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Guard;

use App\Models\User;
use App\Services\Logger\TelegramService;
use Cms\Core\Models\Role;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Notifications\AdminNotification;
use Cms\Core\Workflows\Runtime\Runners\Human\HumanNotifier;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tells the people who can publish workflows that something changed on a live workflow (roadmap B5, decision
 * 2026-11-24: admin notifications + Telegram when the site has a bot).
 *
 * Recipients: every user holding `workflow_project.publish` plus every Webmaster (user id 1 or a role of level 0); when
 * the site named its reviewers (WorkflowChangeReviewers, "Người duyệt thay đổi quy trình") only those people.
 * Plain editors (`workflow_project.update` only) are not told. Each gets an AdminNotification of type
 * `workflow.change.<event>` (the bell row label is `text.workflow.notifications.change_<event>`). When the site's
 * Telegram logger is configured (`services.telegram.token` + `chat_id`, the same bot as the system alerts) one message
 * goes there too; without it nothing is sent and nothing is logged.
 *
 * The payload carries names, counts and an admin link only: never a step config, prompt, credential value or webhook
 * secret. `$details` is filtered to {@see self::DETAIL_KEYS}, scalars only, strings cut to 120 characters.
 *
 * @package Cms\Core\Services\Workflow\Guard
 * @since   2026-11-24
 */
class WorkflowChangeAlerter
{
    public const EVENTS = [
        'version_published',
        'version_pending',
        'version_rolled_back',
        'activation_changed',
        'auto_paused',
        'integrity_violated',
        'credential_changed',
        'global_pause_changed',
        'auto_approval_paused',
        'reviewers_changed',
    ];

    public const TYPE_PREFIX = 'workflow.change.';

    public const PERMISSION = 'workflow_project.publish';

    /** Detail keys an alert may carry (names, counts, numbers, reasons). */
    public const DETAIL_KEYS = [
        'version', 'from_version', 'to_version', 'reason', 'count', 'limit', 'credential', 'active', 'paused',
        'effective_at', 'instance_id', 'projects', 'step_code', 'agreement_rate',
    ];

    /** Plain English lines for the Telegram message (the admin bell uses translation keys). */
    private const LINES = [
        'version_published' => 'A new version of a workflow was published.',
        'version_pending' => 'A workflow change waits for a second person or a cooling-off period.',
        'version_rolled_back' => 'A workflow was rolled back to an earlier version.',
        'activation_changed' => 'A workflow was switched on or off.',
        'auto_paused' => 'A workflow was paused automatically because it went over a safety limit.',
        'integrity_violated' => 'A workflow was stopped: its published version does not match its seal.',
        'credential_changed' => 'A credential used by a live workflow was changed or deleted.',
        'global_pause_changed' => 'All workflows were paused or resumed.',
        'auto_approval_paused' => 'Auto-publish of a review step was paused: JEV agreed with people too rarely lately. A person decides every item until the agreement recovers.',
        'reviewers_changed' => 'The people who review workflow changes were changed.',
    ];

    public function __construct(private readonly HumanNotifier $notifier = new HumanNotifier())
    {
    }

    /**
     * @param array<string, mixed> $details
     */
    public function notify(string $event, ?WorkflowProject $project, array $details = [], ?User $actor = null): void
    {
        if (!in_array($event, self::EVENTS, true)) {
            throw new \InvalidArgumentException("Unknown workflow alert event [{$event}].");
        }

        $this->notifyUsers($this->recipients(), $event, $project, $details, $actor);
    }

    /**
     * Tell the given people (and the site's Telegram bot) about one event.
     *
     * @param list<User>           $users
     * @param array<string, mixed> $details
     */
    public function notifyUsers(array $users, string $event, ?WorkflowProject $project, array $details = [], ?User $actor = null): void
    {
        if (!in_array($event, self::EVENTS, true)) {
            throw new \InvalidArgumentException("Unknown workflow alert event [{$event}].");
        }

        $payload = $this->payload($event, $project, $details, $actor);

        foreach ($users as $user) {
            try {
                $user->notify(new AdminNotification(self::TYPE_PREFIX . $event, $payload));
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->telegram($event, $payload);
    }

    /**
     * The named reviewers when the site set them, else publishers and Webmasters, de-duplicated.
     *
     * @return list<User>
     */
    public function recipients(): array
    {
        $max = max(1, (int) config('core.workflow_nodes.human.notify.max_recipients', 50));

        // The site named its reviewers: only they are told and asked (WorkflowChangeReviewers).
        $named = app(WorkflowChangeReviewers::class)->users();
        if ($named !== []) {
            return array_slice($named, 0, $max);
        }

        $users = [];
        foreach ($this->notifier->recipients(self::PERMISSION, [], $max) as $user) {
            $users[$user->getKey()] = $user;
        }

        $webmasterRoleIds = Role::query()->get(['id', 'custom'])
            ->filter(static fn (Role $role): bool => (int) data_get($role->custom, 'level', 99) === 0)
            ->pluck('id')->all();
        $ids = $webmasterRoleIds === [] ? [] : DB::table('role_user')->whereIn('role_id', $webmasterRoleIds)->pluck('user_id')->all();
        $ids[] = 1;

        foreach (User::query()->whereIn('id', array_unique($ids))->limit($max)->get() as $user) {
            $users[$user->getKey()] = $user;
        }

        return array_slice(array_values($users), 0, $max);
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, scalar|null>
     */
    public function payload(string $event, ?WorkflowProject $project, array $details, ?User $actor): array
    {
        $payload = [
            'event' => $event,
            'project_id' => $project?->getKey(),
            'project_name' => $project ? mb_substr((string) $project->name, 0, 120) : null,
            'actor_name' => $actor ? mb_substr((string) $actor->name, 0, 80) : null,
            'url' => rtrim((string) config('app.url'), '/') . '/iPanel/setting/automation/workflows/manage',
        ];

        foreach (self::DETAIL_KEYS as $key) {
            if (!array_key_exists($key, $details)) {
                continue;
            }
            $value = $details[$key];
            if (is_array($value)) {
                $value = implode(', ', array_filter($value, 'is_scalar'));
            }
            if ($value !== null && !is_scalar($value)) {
                continue;
            }
            $payload[$key] = is_string($value) ? mb_substr($value, 0, 120) : $value;
        }

        return $payload;
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    private function telegram(string $event, array $payload): void
    {
        if ((string) config('services.telegram.token') === '' || (string) config('services.telegram.chat_id') === '') {
            return;
        }

        $lines = [self::LINES[$event]];
        if ($payload['project_name'] !== null) {
            $lines[] = 'Workflow: ' . $payload['project_name'] . ' (#' . $payload['project_id'] . ')';
        }
        foreach ($payload as $key => $value) {
            if (in_array($key, ['event', 'project_id', 'project_name', 'url'], true) || $value === null) {
                continue;
            }
            $lines[] = str_replace('_', ' ', $key) . ': ' . (is_bool($value) ? ($value ? 'yes' : 'no') : $value);
        }
        $lines[] = 'Open: ' . $payload['url'];

        try {
            TelegramService::send(['title' => 'Workflow alert', 'message' => implode("\n", $lines)]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
