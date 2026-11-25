<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Human;

use App\Models\User;
use Cms\Core\Notifications\AdminNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * In-app admin notices for the human workflow nodes: to every user holding a permission (through their roles) and/or
 * to named users (uuid). Uses AdminNotification (database channel, flat payload ≤ 1 KB); the bell row opens the
 * approval when the payload carries `approval_id`. Failures to deliver to one user never stop the others.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Human
 * @since   2026-11-24
 */
class HumanNotifier
{
    /**
     * @param list<string>          $userUuids
     * @param array<string, scalar|null> $payload
     * @return list<int> ids of the users notified
     */
    public function send(string $type, array $payload, ?string $permission, array $userUuids = []): array
    {
        $max = max(1, (int) config('core.workflow_nodes.human.notify.max_recipients', 50));
        $recipients = $this->recipients($permission, $userUuids, $max);

        $sent = [];
        foreach ($recipients as $user) {
            try {
                $user->notify(new AdminNotification($type, $payload));
                $sent[] = (int) $user->getKey();
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }

    /**
     * @param list<string> $userUuids
     * @return list<User>
     */
    public function recipients(?string $permission, array $userUuids, int $max): array
    {
        $users = [];
        $uuids = array_values(array_filter($userUuids, static fn ($uuid): bool => is_string($uuid) && $uuid !== ''));
        if ($uuids !== []) {
            foreach (User::query()->whereIn('uuid', $uuids)->limit($max)->get() as $user) {
                $users[$user->getKey()] = $user;
            }
        }

        if (is_string($permission) && str_contains($permission, '.')) {
            // Split at the LAST dot, as permission rows are stored: a module permission's group holds dots
            // (`education.edu_research_result` + `view`, `sample-module.stories` + `review`).
            $dot = strrpos($permission, '.');
            [$group, $name] = [substr($permission, 0, $dot), substr($permission, $dot + 1)];
            $ids = DB::table('role_user')
                ->join('permission_role', 'permission_role.role_id', '=', 'role_user.role_id')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->where('permissions.group', $group)
                ->where('permissions.name', $name)
                ->distinct()
                ->limit($max)
                ->pluck('role_user.user_id')
                ->all();

            foreach (User::query()->whereIn('id', $ids)->get() as $user) {
                // The role table is the candidate list; the gate (module permission resolver) has the last word.
                if (Gate::forUser($user)->allows($permission)) {
                    $users[$user->getKey()] = $user;
                }
            }
        }

        return array_slice(array_values($users), 0, $max);
    }
}
