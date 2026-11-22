<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services;

use App\Constants\UserType;
use App\Enums\UserStatusEnum;
use App\Models\User;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\Role;
use Cms\Core\Models\Tag;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** User tags: the only code that reads or writes `tags` (type user) / `tags_data` (target User), and the single user filter behind the Users list, bulk tagging and the Webmail picker. */
class UserTagService
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_SYNC = 'sync';
    public const NAME_MAX = 120;

    /**
     * Find the tag with the same slug as `$name` or create it (`Triết` and `Triet` are one tag). Name is trimmed, whitespace collapsed, capped at NAME_MAX.
     *
     * @throws ValidationException when the name is empty or has no letter or digit
     */
    public function findOrCreateByName(string $name): Tag
    {
        $name = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $name)), 0, self::NAME_MAX);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Tên tag không được để trống.']);
        }
        $slug = Str::slug($name);
        if ($slug === '') {
            throw ValidationException::withMessages(['name' => 'Tên tag phải có chữ hoặc số.']);
        }
        if ($existing = $this->tagQuery()->where('slug', $slug)->first()) {
            return $existing;
        }
        try {
            // A savepoint inside an outer transaction, so losing a race on the unique slug cannot poison it.
            return DB::transaction(static fn (): Tag => Tag::query()->create(['name' => $name, 'slug' => $slug, 'type' => Tag::TYPE_USER]));
        } catch (UniqueConstraintViolationException) {
            return $this->tagQuery()->where('slug', $slug)->firstOrFail();
        }
    }

    /** Attach a tag (created on demand) to one user; idempotent, an existing pair keeps its original source (`manual` or `sync`). */
    public function attachByName(User $user, string $name, string $source): void
    {
        $this->assertSource($source);
        DB::transaction(function () use ($user, $name, $source): void {
            $tag = $this->findOrCreateByName($name);
            if (!$this->pairs()->where('target_id', $user->id)->where('tag_id', $tag->id)->exists()) {
                $this->pairs()->insertOrIgnore($this->pairRow($user->id, $tag->id, $source));
            }
        });
    }

    /** Hard-delete a tag and every pair that points at it. */
    public function deleteTag(Tag $tag): void
    {
        DB::transaction(static function () use ($tag): void {
            DB::table(CoreTableConstant::TAGS_DATA)->where('tag_id', $tag->id)->where('target_type', User::class)->delete();
            $tag->forceDelete();
        });
    }

    /** User tags only, so post tags never leak in. */
    private function tagQuery(): Builder
    {
        return Tag::query()->ofType(Tag::TYPE_USER);
    }

    /** The `tags_data` rows of users. */
    private function pairs(): \Illuminate\Database\Query\Builder
    {
        return DB::table(CoreTableConstant::TAGS_DATA)->where('target_type', User::class);
    }

    /** One `tags_data` row for a user and a tag. */
    private function pairRow(int $userId, int $tagId, string $source): array
    {
        return ['target_type' => User::class, 'target_id' => $userId, 'tag_id' => $tagId, 'source' => $source, 'created_at' => now(), 'updated_at' => now()];
    }

    /** Reject a source other than manual or sync. */
    private function assertSource(string $source): void
    {
        if (!in_array($source, [self::SOURCE_MANUAL, self::SOURCE_SYNC], true)) {
            throw new \InvalidArgumentException('Unknown user tag source: ' . $source);
        }
    }

    private const CHUNK = 100;
    public const FILTER_RUN_CAP = 5000;
    public const DEFAULT_PER_PAGE = 25;
    public const MAX_PER_PAGE = 100;
    /** Every account type, for callers that must reach staff accounts too (the Users list). */
    public const ALL_TYPES = [UserType::WEBMASTER, UserType::ADMIN, UserType::CLIENT, UserType::USER];

    /** Validation rules of the shared filter keys, for a query string (`''`) or a nested object (`'filter.'`). */
    public static function filterRules(string $prefix = ''): array
    {
        return [
            $prefix . 'q' => 'nullable|string|max:191', $prefix . 'roles' => 'nullable|array|max:50', $prefix . 'roles.*' => 'integer',
            $prefix . 'tags' => 'nullable|array|max:50', $prefix . 'tags.*' => 'uuid', $prefix . 'match' => 'nullable|in:all,any',
            $prefix . 'types' => 'nullable|array', $prefix . 'types.*' => 'integer|in:0,1,2,3', $prefix . 'status' => 'nullable|string|max:50',
            $prefix . 'page' => 'nullable|integer|min:1', $prefix . 'per_page' => 'nullable|integer|min:1|max:' . self::MAX_PER_PAGE,
        ];
    }

    /**
     * The single user filter. Keys: `q` (name, email, username), `roles` (role ids), `tags` (tag uuids), `match` (`all` default | `any`, tags only),
     * `types` (default client + user), `status` (a `user_status` key, default `user_active`, `all` skips it), internal `uuids` and `min_level`.
     * Soft-deleted users never match; no ordering, no paging; unknown role, tag or status is a 422.
     */
    public function filteredUsersQuery(array $filter): Builder
    {
        $types = array_values(array_unique(array_map('intval', (array) ($filter['types'] ?? [UserType::CLIENT, UserType::USER]))));
        $query = User::query()->whereIn('users.type', $types);
        if (($status = $this->statusFor($filter)) !== null) {
            $query->where('users.status', $status);
        }
        if (isset($filter['min_level'])) {
            $query->where('users.level', '>=', (int) $filter['min_level']);
        }
        if (($uuids = array_values(array_filter(array_map('strval', (array) ($filter['uuids'] ?? []))))) !== []) {
            $query->whereIn('users.uuid', $uuids);
        }
        if (($q = trim((string) ($filter['q'] ?? ''))) !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $query->where(fn ($w) => $w->where('users.name', 'like', $like)->orWhere('users.email', 'like', $like)->orWhere('users.username', 'like', $like));
        }
        if (($roleIds = $this->roleIdsFor($filter)) !== []) {
            $query->whereHas('roles', fn ($r) => $r->whereIn('roles.id', $roleIds));
        }
        if (($tagIds = $this->tagIdsFor((array) ($filter['tags'] ?? []))) !== []) {
            if (($filter['match'] ?? 'all') === 'any') {
                $query->whereHas('tags', fn ($t) => $t->whereIn('tags.id', $tagIds));
            } else {
                foreach ($tagIds as $tagId) {
                    $query->whereHas('tags', fn ($t) => $t->where('tags.id', $tagId));
                }
            }
        }

        return $query;
    }

    /** One page (newest first) of the filtered users with roles and tags loaded; `per_page` defaults to 25, capped at 100. */
    public function paginateFiltered(array $filter): LengthAwarePaginator
    {
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($filter['per_page'] ?? self::DEFAULT_PER_PAGE)));

        return $this->filteredUsersQuery($filter)->with(['roles:id,name,custom', 'tags:id,uuid,name'])->orderByDesc('users.id')
            ->paginate($perPage, ['users.*'], 'page', max(1, (int) ($filter['page'] ?? 1)));
    }

    /** Picker row: uuid, name, email, roles and tags (relations must be loaded, see paginateFiltered). */
    public function presentRow(User $user): array
    {
        return [
            'uuid' => $user->uuid, 'name' => $user->name, 'email' => $user->email,
            'roles' => $user->roles->map(static fn (Role $r): array => ['id' => $r->id, 'name' => $r->name, 'custom' => $r->custom])->values()->all(),
            'tags' => $user->tags->map(static fn (Tag $t): array => ['uuid' => $t->uuid, 'name' => $t->name])->values()->all(),
        ];
    }

    /** Resolve tag uuids to ids; an uuid that no longer exists is a 422 "Tag không còn tồn tại". */
    public function tagIdsFor(array $uuids): array
    {
        $uuids = array_values(array_unique(array_map('strval', $uuids)));
        if ($uuids === []) {
            return [];
        }
        $ids = $this->tagQuery()->whereIn('uuid', $uuids)->pluck('id')->all();
        if (count($ids) !== count($uuids)) {
            throw ValidationException::withMessages(['tags' => 'Tag không còn tồn tại.']);
        }

        return array_map('intval', $ids);
    }

    /** Role ids of the filter, validated against `roles` (soft-deleted roles do not count). */
    private function roleIdsFor(array $filter): array
    {
        $ids = array_values(array_unique(array_map('intval', (array) ($filter['roles'] ?? []))));
        if ($ids !== [] && Role::query()->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['roles' => 'Nhóm không còn tồn tại.']);
        }

        return $ids;
    }

    /** Status clause: default `user_active`, `all` means none, anything else must be a `user_status` enumerate key. */
    private function statusFor(array $filter): ?string
    {
        $status = (string) ($filter['status'] ?? UserStatusEnum::USER_ACTIVE->value);
        if ($status === 'all') {
            return null;
        }
        if ($status !== UserStatusEnum::USER_ACTIVE->value && !DB::table(CoreTableConstant::ENUMERATES)->where('type', 'user_status')->where('enum_key', $status)->exists()) {
            throw ValidationException::withMessages(['status' => 'Trạng thái không hợp lệ.']);
        }

        return $status;
    }

    /** Attach tags to users, chunked in one transaction; an existing pair keeps its source. Returns the users that got at least one new pair. */
    public function attach(array $userIds, array $tagIds, string $source): int
    {
        $this->assertSource($source);
        [$userIds, $tagIds] = [$this->ids($userIds), $this->ids($tagIds)];
        $affected = 0;
        if ($userIds === [] || $tagIds === []) {
            return 0;
        }
        DB::transaction(function () use ($userIds, $tagIds, $source, &$affected): void {
            foreach (array_chunk($userIds, self::CHUNK) as $chunk) {
                $have = [];
                foreach ($this->pairs()->whereIn('target_id', $chunk)->whereIn('tag_id', $tagIds)->get(['target_id', 'tag_id']) as $row) {
                    $have[(int) $row->target_id . ':' . (int) $row->tag_id] = true;
                }
                $rows = [];
                foreach ($chunk as $userId) {
                    $missing = array_filter($tagIds, static fn (int $tagId): bool => !isset($have[$userId . ':' . $tagId]));
                    if ($missing === []) {
                        continue;
                    }
                    $affected++;
                    foreach ($missing as $tagId) {
                        $rows[] = $this->pairRow($userId, $tagId, $source);
                    }
                }
                foreach (array_chunk($rows, 200) as $part) {
                    $this->pairs()->insertOrIgnore($part);
                }
            }
        });

        return $affected;
    }

    /** Detach tags from users whatever the source of the pair; returns the users that lost at least one tag. */
    public function detach(array $userIds, array $tagIds): int
    {
        [$userIds, $tagIds] = [$this->ids($userIds), $this->ids($tagIds)];
        $affected = 0;
        if ($userIds === [] || $tagIds === []) {
            return 0;
        }
        DB::transaction(function () use ($userIds, $tagIds, &$affected): void {
            foreach (array_chunk($userIds, self::CHUNK) as $chunk) {
                $pairs = $this->pairs()->whereIn('target_id', $chunk)->whereIn('tag_id', $tagIds);
                $affected += (clone $pairs)->distinct()->count('target_id');
                $pairs->delete();
            }
        });

        return $affected;
    }

    /** Attach tags to every user a filter matches: the filter is re-run here (the client never sends ids for "select all"), capped at FILTER_RUN_CAP (422), one transaction. */
    public function attachToFilter(array $filter, array $tagIds, string $source): int
    {
        return DB::transaction(fn (): int => $this->attach($this->filterRunIds($filter), $tagIds, $source));
    }

    /** Detach tags from every user a filter matches (same cap and transaction as attachToFilter). */
    public function detachFromFilter(array $filter, array $tagIds): int
    {
        return DB::transaction(fn (): int => $this->detach($this->filterRunIds($filter), $tagIds));
    }

    /** Count the filter's users, refuse more than FILTER_RUN_CAP, and collect their ids with chunkById. */
    private function filterRunIds(array $filter): array
    {
        $query = $this->filteredUsersQuery($filter);
        if (($count = (clone $query)->count()) > self::FILTER_RUN_CAP) {
            throw ValidationException::withMessages(['filter' => 'Bộ lọc khớp ' . number_format($count, 0, ',', '.') . ' người, tối đa 5.000; thu hẹp bộ lọc.']);
        }
        $ids = [];
        (clone $query)->chunkById(500, static function ($users) use (&$ids): void {
            foreach ($users as $user) {
                $ids[] = (int) $user->id;
            }
        }, 'users.id', 'id');

        return $ids;
    }

    /** Distinct positive integer ids. */
    private function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    }
}
