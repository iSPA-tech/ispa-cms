<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Responses\AppResponse;

use Exception;
use JsonException;
use App\Constants\Tables;
use App\Constants\UserType;
use App\Enums\UserStatusEnum;
use App\Models\User;
use Cms\Core\Models\Role;
use Cms\Core\Models\Upload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Cms\Core\Traits\Controllers\SearchTrait;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Interfaces\UserInterface;
use App\Services\AdminUpdateContentService;
use Cms\Core\Http\Resources\User\UserCollection;
use Cms\Core\Http\Resources\User\UserDetailResource;
use Cms\Core\Http\Resources\User\UserEditResource;
use Cms\Core\Notifications\QueuedVerifyEmail;
use Cms\Core\Services\UserAccountSettingsService;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Events\PasswordChangeNotification;
use Cms\Core\Events\SendEmailPasswordAutoResetEvent;
use Cms\Core\Http\Requests\User\StoreUserRequest;
use Cms\Core\Http\Requests\User\UpdateUserRequest;
use Cms\Core\Http\Requests\User\SetPasswordRequest;
use Cms\Core\Http\Requests\User\PutSaveSettingRequest;
use Cms\Core\Http\Requests\User\MultiDeleteUserRequest;
use Cms\Core\Http\Requests\User\UsersTagAttachRequest;
use Cms\Core\Services\UserTagService;
use Cms\Core\Services\Security\TokenRevoker;

/**
 * Manage users for Admin v2: search, list, create/update with role
 * assignment and meta, set/reset passwords, persist user-specific
 * settings, and delete single or multiple users with policy checks.
 *
 * @see UserAccountSettingsService
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  malayvuong
 *
 * @since   1.0.0  (2022-01-17)
 * @last_updated 8.0.0 - 2025-09-09, 00:00 (+07:00)
 */
class UserController extends AdminController
{
    use SearchTrait;

    /**
     * Controller constructor
     *
     * @param UserInterface $model
     * @param UserAccountSettingsService $accountSettings
     *
     * @author malayvuong
     * @since 1.0.0
     */
    public function __construct(
        protected UserInterface $model,
        protected UserAccountSettingsService $accountSettings,
    )
    {
        parent::__construct();
    }

    /**
     * Provide aggregate stats for the admin user dashboard.
     * @return mixed
     */
    public function dashboard(): mixed
    {
        if (!auth()->user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $shouldReload = filter_var(request()->query('reload'), FILTER_VALIDATE_BOOLEAN);

        if ($shouldReload) {
            cache()->forget('core.user.dashboard');
        }

        $dashboard = cache()->remember('core.user.dashboard', 300, function (): array {
            $now = now();
            $startOfMonth = $now->copy()->startOfMonth();

            $totalUsers = User::query()->count();
            $activeUsers = User::query()
                ->where('status', UserStatusEnum::USER_ACTIVE->value)
                ->count();
            $newUsers = User::query()
                ->where('created_at', '>=', $startOfMonth)
                ->count();

            $students = User::query()
                ->whereIn('type', [UserType::CLIENT, UserType::USER])
                ->count();

            $staffs = User::query()
                ->whereIn('type', [UserType::WEBMASTER, UserType::ADMIN])
                ->count();

            $inactiveUsers = max($totalUsers - $activeUsers, 0);

            $roleDistribution = Role::query()
                ->select('id', 'name')
                ->withCount('users')
                ->orderByDesc('users_count')
                ->get()
                ->map(fn(Role $role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'total' => (int)($role->users_count ?? 0),
                ])
                ->values();

            $latestUsers = User::query()
                ->select('id', 'uuid', 'name', 'email', 'status', 'type', 'created_at', 'updated_at')
                ->with(['roles:id,name'])
                ->latest('created_at')
                ->limit(5)
                ->get()
                ->map(fn(User $user) => [
                    'id' => $user->id,
                    'uuid' => $user->uuid,
                    'name' => $user->name,
                    'email' => $user->email,
                    'status' => $user->status,
                    'type' => $user->type,
                    'roles' => $user->roles->map(fn(Role $role) => [
                        'id' => $role->id,
                        'name' => $role->name,
                    ])->values(),
                    'created_at' => optional($user->created_at)->toDateTimeString(),
                    'updated_at' => optional($user->updated_at)->toDateTimeString(),
                ])
                ->values();

            return [
                'last_refreshed_at' => $now->toDateTimeString(),
                'stats' => [
                    'total_users' => $totalUsers,
                    'active_users' => $activeUsers,
                    'inactive_users' => $inactiveUsers,
                    'new_users' => $newUsers,
                    'students' => $students,
                    'staffs' => $staffs,
                ],
                'roles' => $roleDistribution,
                'role_distribution' => $roleDistribution,
                'latest_users' => $latestUsers,
            ];
        });

        return ires()->data($dashboard);
    }

    /**
     * Display the specified user detail.
     * @param  string $identity
     * @param  Request $request
     * @return mixed
     */
    public function show(string $identity, Request $request): mixed
    {
        if (empty($identity)) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $conditions = is_numeric($identity)
            ? ['id' => (int)$identity]
            : ['uuid' => $identity];

        $model = $this->model->findBy($conditions, ['*'], [
            'roles' => static function ($query) {
                $query->select('roles.id', 'roles.name', 'roles.custom')
                    ->with('permissions:id,name,group');
            },
            'settings:id,user_id,setting_key,setting_value',
            'meta',
            'avatar',
            'auditLogs' => static function ($query): void {
                $query->select(
                    'id',
                    'user_id',
                    'target_id',
                    'target_type',
                    'action',
                    'changed',
                    'ip_address',
                    'agent',
                    'url',
                    'options',
                    'created_at'
                )
                    ->with('creator:id,name,email')
                    ->orderByDesc('created_at')
                    ->limit(25);
            },
            'status_enum:id,name,enum_key',
        ]);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can(str(Tables::USERS)->singular() . '.view')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->data(new UserDetailResource($model));
    }

    /**
     * Provide aggregate student counts for the admin dashboard.
     */
    public function dashboardStudent(): mixed
    {
        if (! user()?->can('user.student')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $cacheKey = 'core.user.dashboard.student';

        if (filter_var(request()->query('reload'), FILTER_VALIDATE_BOOLEAN)) {
            cache()->forget($cacheKey);
        }

        $payload = cache()->remember($cacheKey, 300, static function (): array {
            $students = User::query()->where('type', UserType::CLIENT);

            return [
                'totalStudent' => (clone $students)->count(),
                'totalStudentActive' => (clone $students)
                    ->whereNotNull('email_verified_at')
                    ->where('status', UserStatusEnum::USER_ACTIVE->value)
                    ->count(),
                'totalStudentInactive' => (clone $students)
                    ->whereNull('email_verified_at')
                    ->where('status', UserStatusEnum::USER_ACTIVE->value)
                    ->count(),
            ];
        });

        return ires()->data($payload);
    }

    /**
     * Persist user-specific settings.
     *
     * @param string $uuid User UUID.
     * @param PutSaveSettingRequest $request Validated settings payload.
     *
     * @return mixed
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-01-14, 12:24 ICT
     */
    public function putSettings(string $uuid, PutSaveSettingRequest $request): mixed
    {
        $user = $this->model->findByUuid($uuid);

        if (!$user) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('user.update')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $this->accountSettings->update($user, $request->validated());

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.users')]));
    }

    /**
     * Retrieve stored settings for the given user.
     *
     * @param string $uuid User UUID.
     *
     * @return mixed
     *
     * @author vuongnm
     * @since 6.62.6 - 2023-01-09, 10:48 ICT
     */
    public function getSettings(string $uuid): mixed
    {
        $user = $this->model->findByUuid($uuid);

        if (!$user) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('user.update')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->data($this->accountSettings->forUser($user));
    }

    /**
     * Queue a fresh email verification notification for an unverified user.
     */
    public function resendEmailVerification(string $uuid): mixed
    {
        if (empty($uuid)) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $model = $this->model->findBy(['uuid' => $uuid]);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (user('level', 99) > $model->level || !user()?->can('user.update')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if ($model->hasVerifiedEmail()) {
            return ires()->error()->message(__('mgs.auth.email-already-verified'));
        }

        $model->notify(new QueuedVerifyEmail());

        return ires()->message(__('mgs.ok.email-verification-queued'));
    }

    /**
     * Set or reset a user's password.
     *
     * @param string $uuid User UUID.
     * @param SetPasswordRequest $request Validated password payload.
     *
     * @return mixed
     *
     * @author malayvuong
     * @since 1.0.0 - 2022-01-17, 13:52
     */
    public function setPassword(string $uuid, SetPasswordRequest $request): mixed
    {
        if (empty($uuid)) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $model = $this->model->findBy(['uuid' => $uuid]);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }
        //  Check permission & user level
        if (user('id', 0) === $model->id || user('level', 99) > $model->level || !user()?->can('user.password')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $isAutoPassword = (bool) $request->input('auto_password', false);
        $autoPasswordData = null;

        DB::beginTransaction();

        try {
            if ($isAutoPassword) {
                //  Prepare data for send mail
                $autoPasswordData = [
                    'password' => str()->random(8),
                    'email' => $model->email,
                    'name' => $model->name,
                    'subject' => __('text.auto-pws'),
                    'user_id' => $model->id,
                ];
                //  Process to change password
                $model->password = Hash::make($autoPasswordData['password']);
            } else {
                $model->password = Hash::make(request('password'));
            }

            $model->save();

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            Log::debug($e->getMessage());

            return ires()->error()->message('error');
        }

        // A password set by an operator ends every API token of that account
        app(TokenRevoker::class)->revokeForUser($model, null);

        //  Dispatch events after successful commit to avoid race conditions
        if ($isAutoPassword && $autoPasswordData) {
            event(new SendEmailPasswordAutoResetEvent($autoPasswordData));
        } else {
            event(new PasswordChangeNotification($request->userAgent(), $request->ip(), $model));
        }

        event(new AuditLogEvent('setPassword', [], User::class, auth()->id(), 'fa fa-lock', 'text-yellow-600'));

        return ires()->message('true');
    }

    /**
     * Display a paginated list of users.
     *
     * @return mixed
     * @author malayvuong
     */
    public function index(): mixed
    {
        if (!auth()->user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->model->get([
            'where' => [
                ['level', '>=', user()->level],
            ],
            'latest' => true,
            'page' => request('pageSize', 10),
            'filter' => request()->all(),
            'with' => [
                'roles:id,name,custom',
            ],
        ]);

        return ires()->data(new UserCollection($model));
    }

    /** `GET users/filter`: paged users for the tag/role filtered Users list (`{items, total, page, per_page}`); needs `user.viewAny`, keeps the level rule of index(). */
    public function filter(Request $request, UserTagService $tags): mixed
    {
        abort_unless($request->user()?->can('user.viewAny'), 403);
        $filter = $request->validate(UserTagService::filterRules()) + ['min_level' => (int) user('level', 99)];
        $filter['types'] ??= UserTagService::ALL_TYPES;
        $filter['status'] ??= 'all';
        $page = $tags->paginateFiltered($filter);

        return ires()->data([
            'items' => collect($page->items())->map(fn (User $u): array => $tags->presentRow($u) + [
                'id' => $u->id, 'username' => $u->username, 'mobile' => $u->mobile, 'status' => $u->status, 'level' => $u->level,
                'emailVerifiedAt' => $u->email_verified_at?->toIso8601String(), 'isDeleted' => false,
            ])->values(),
            'total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage(),
        ]);
    }

    /** `POST users/tags/attach`: attach tags (source `manual`) to the given users or to every user a filter matches; answers `{affected}`. */
    public function attachTags(UsersTagAttachRequest $request, UserTagService $tags): mixed
    {
        return $this->applyTags($request, $tags, true);
    }

    /** `POST users/tags/detach`: detach tags from the given users or from every user a filter matches; answers `{affected}`. */
    public function detachTags(UsersTagAttachRequest $request, UserTagService $tags): mixed
    {
        return $this->applyTags($request, $tags, false);
    }

    /** Shared body of attachTags/detachTags: explicit uuids (any status or type, never above the admin's level) or a server-side filter run with the 5,000 cap. */
    private function applyTags(UsersTagAttachRequest $request, UserTagService $tags, bool $attach): mixed
    {
        $data = $request->validated();
        $tagIds = $tags->tagIdsFor($data['tag_uuids']);
        $scope = ['status' => 'all', 'types' => UserTagService::ALL_TYPES, 'min_level' => (int) user('level', 99)];
        if (!empty($data['user_uuids'])) {
            $ids = $tags->filteredUsersQuery(['uuids' => $data['user_uuids']] + $scope)->pluck('users.id')->all();
            $affected = $attach ? $tags->attach($ids, $tagIds, UserTagService::SOURCE_MANUAL) : $tags->detach($ids, $tagIds);
        } else {
            $filter = $data['filter'] + $scope; // a status or types sent by the client wins over the defaults
            $affected = $attach ? $tags->attachToFilter($filter, $tagIds, UserTagService::SOURCE_MANUAL) : $tags->detachFromFilter($filter, $tagIds);
        }

        return ires()->data(['affected' => $affected]);
    }

    /**
     * Store a newly created user.
     *
     * @param StoreUserRequest $request Validated request payload.
     *
     * @return mixed
     * @author malayvuong
     */
    public function store(StoreUserRequest $request): mixed
    {
        $model = $this->fill();

        if (!auth()->user()?->can('create', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        DB::beginTransaction();

        try {
            $model->save();

            //  Call to Update info
            $this->updateInfo($model);

            //  Update level
            $model->level = $model->roleLevel();
            $model->save();
        } catch (Exception $e) {
            Db::rollBack();
            Log::debug($e);

            return ires()->error()->message(__('mgs.er.create', ['name' => __('nav.a.users')]));
        }

        DB::commit();

        return ires()->message(__('mgs.ok.create', ['name' => __('nav.a.users')]));
    }

    /**
     * Hydrate the User model with request data.
     *
     * @param mixed|null $model Existing model or null to instantiate a new one.
     *
     * @return mixed Populated model instance.
     *
     * @author malayvuong
     * @since  1.0.0
     * @last_updated 8.0.0 - 2025-09-06, 11:00 (+07:00)
     */
    protected function fill(mixed $model = null): mixed
    {
        if (!$model) {
            $model = $this->model->model();
        }

        $model->fill(request()->only($model->getFillable()));

        if ($model->id > 0) {
            unset($model->password);
        } else {
            /** Set uuid string */
            $model->uuid = $this->model->checkUnique('uuid', str()->uuid());
            //  Set password
            $model->password = Hash::make(request('password'));
        }

        return $model;
    }

    /**
     * Update user information and related metadata.
     *
     * @param mixed $model User model being updated.
     *
     * @return void
     *
     * @throws JsonException
     *
     * @since 1.0.0
     * @author malayvuong
     */
    protected function updateInfo(mixed $model): void
    {
        $service = new AdminUpdateContentService();
        //  Sync meta data
        $service->syncMetaData(request(), $model);
        $this->syncAvatar($model);

        if (request('roles')) {
            $model->roles()->sync(request('roles'));
        }
    }

    /**
     * Show the form for editing the specified user.
     *
     * @param string $uuid User UUID.
     *
     * @return mixed
     *
     * @author malayvuong
     */
    public function edit(string $uuid): mixed
    {
        if (empty($uuid)) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $model = $this->model->findBy(['uuid' => $uuid], ['*'], [
            'roles:id,name,custom',
            'meta',
            'avatar',
        ]);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can(str(Tables::USERS)->singular() . '.update')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->data(new UserEditResource($model));
    }

    /**
     * Update the specified user in storage.
     *
     * @param string $uuid User UUID.
     * @param UpdateUserRequest $request Validated request payload.
     *
     * @return mixed
     *
     * @author malayvuong
     */
    public function update(string $uuid, UpdateUserRequest $request): mixed
    {
        if (empty($uuid)) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $model = $this->model->findBy(['uuid' => $uuid]);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can(str(Tables::USERS)->singular() . '.update')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->fill($model);

        DB::beginTransaction();
        try {
            //  Call to Update info
            $this->updateInfo($model);

            //  Update level
            $model->level = $model->roleLevel();

            $model->save();
        } catch (Exception $e) {
            Db::rollBack();
            Log::debug($e->getMessage(), [$e->getTraceAsString()]);

            return ires()->error()->message(__('mgs.er.update', ['name' => __('nav.a.users')]));
        }

        DB::commit();

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.users')]));
    }

    /**
     * Remove the specified user from storage.
     *
     * @param string $uuid User UUID.
     *
     * @return mixed
     *
     * @author malayvuong
     */
    public function destroy(string $uuid): mixed
    {
        if (empty($uuid)) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $model = $this->model->findBy(['uuid' => $uuid]);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if ($model->id === 1 || user('id', 0) === $model->id || $model->level < user('level', 99) || !user()?->can('user.delete')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if (!$model->delete()) {
            return ires()->error()->message(__('mgs.er.delete', ['name' => __('nav.a.users')]));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.users')]));
    }

    protected function syncAvatar(User $model): void
    {
        if (!request()->exists('avatar')) {
            return;
        }

        Upload::query()
            ->where('target_type', User::class)
            ->where('target_id', $model->id)
            ->where('category', 'avatar')
            ->update([
                'target_type' => null,
                'target_id' => null,
                'category' => null,
                'main' => false,
            ]);

        $avatarId = collect(request('avatar', []))
            ->filter(static fn($item) => is_array($item) && !empty($item['id']))
            ->pluck('id')
            ->first();

        if (!$avatarId) {
            return;
        }

        Upload::query()
            ->whereKey($avatarId)
            ->update([
                'target_type' => User::class,
                'target_id' => $model->id,
                'category' => 'avatar',
                'type' => 'avatar',
                'main' => true,
            ]);
    }

    /**
     * Remove multiple users from storage.
     *
     * @param MultiDeleteUserRequest $request Validated deletion request.
     *
     * @return mixed
     *
     * @author malayvuong
     */
    public function massDestroy(MultiDeleteUserRequest $request): mixed
    {
        if (!user()?->can('delete', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.users')]));
    }
}
