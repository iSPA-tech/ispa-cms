<?php

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\Workflow;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Reviewer picker of the workflow builder (approval assignees): `GET api/v2/admin/workflows/reviewers`.
 *
 * Needs `workflow_project.viewAny` (same as the node catalog), not the user-management rights, so a workflow editor can
 * pick reviewers. `q` searches by name among the users holding the reviewer permission
 * (`core.workflow_nodes.human.reviewer_permission`; roles give the candidates, the Gate has the last word, like
 * HumanNotifier::recipients()), at most 20. `uuids[]` resolves saved assignees to names (existing users only) and flags
 * those without the permission with `can_review: false`. Rows carry `uuid`, `name` and `can_review` only, never emails.
 *
 * @package Cms\Core\Http\Controllers\Workflow
 * @since   2026-11-24
 */
class WorkflowReviewerController extends Controller
{
    public const MAX_RESULTS = 20;

    public function index(Request $request)
    {
        if (!user()?->can('workflow_project.viewAny')) {
            return ires()->forbidden(__('mgs.no.permission'));
        }

        $permission = (string) config('core.workflow_nodes.human.reviewer_permission', 'agentic.approve');
        $uuids = array_values(array_unique(array_filter((array) $request->input('uuids', []), static fn ($uuid): bool => is_string($uuid) && $uuid !== '')));

        if ($uuids !== []) {
            $rows = User::query()->whereIn('uuid', array_slice($uuids, 0, self::MAX_RESULTS))->orderBy('name')->get()
                ->map(static fn (User $user): array => self::row($user, Gate::forUser($user)->allows($permission)));

            return ires()->data($rows->values()->all());
        }

        if (!str_contains($permission, '.')) {
            return ires()->data([]);
        }

        [$group, $name] = explode('.', $permission, 2);
        $search = trim($request->string('q')->toString());
        $candidateIds = DB::table('role_user')
            ->join('permission_role', 'permission_role.role_id', '=', 'role_user.role_id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permissions.group', $group)
            ->where('permissions.name', $name)
            ->distinct()
            ->pluck('role_user.user_id')
            ->all();

        $rows = [];
        User::query()
            ->whereIn('id', $candidateIds)
            ->when($search !== '', static fn ($query) => $query->where('name', 'like', '%' . addcslashes($search, '%_\\') . '%'))
            ->orderBy('name')
            ->limit(self::MAX_RESULTS * 5)
            ->get()
            ->each(static function (User $user) use ($permission, &$rows): bool {
                // The role table is the candidate list; the gate (module permission resolver) has the last word.
                if (Gate::forUser($user)->allows($permission)) {
                    $rows[] = self::row($user, true);
                }

                return count($rows) < self::MAX_RESULTS;
            });

        return ires()->data($rows);
    }

    /**
     * @return array{uuid: string|null, name: string|null, can_review: bool}
     */
    protected static function row(User $user, bool $canReview): array
    {
        return ['uuid' => $user->uuid, 'name' => $user->name, 'can_review' => $canReview];
    }
}
