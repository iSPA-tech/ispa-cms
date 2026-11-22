<?php

declare(strict_types=1);

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Tag;
use Cms\Core\Services\UserTagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

// Core user tags: data, UserTagService, admin API (user-tags, users/filter, users/tags/attach|detach).
uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

const UT_BASE = '/api/v2/admin';

beforeEach(function () {
    // users.status has a FK to enumerates.enum_key: seed the user_status rows the fixtures use.
    foreach (['user_active', 'user_banned', 'user_inactive'] as $key) {
        DB::table('enumerates')->insert(['name' => $key, 'enum_key' => $key, 'type' => 'user_status', 'status' => true, 'created_at' => now(), 'updated_at' => now()]);
    }
});

/** Create a site user (default: an active student, type USER). */
function utUser(string $name, array $attrs = []): User
{
    return User::query()->forceCreate($attrs + [
        'uuid' => (string) Str::uuid(), 'name' => $name, 'username' => Str::slug($name) . '-' . Str::lower(Str::random(5)),
        'email' => Str::slug($name) . '-' . Str::lower(Str::random(5)) . '@example.test', 'password' => Hash::make('secret123'),
        'type' => UserType::USER, 'level' => 5, 'status' => 'user_active',
    ]);
}

/** Insert many cheap users in chunks (cap tests). */
function utBulkUsers(int $count): void
{
    foreach (array_chunk(range(1, $count), 100) as $chunk) {
        DB::table('users')->insert(array_map(static fn (int $i): array => [
            'uuid' => (string) Str::uuid(), 'name' => 'Bulk ' . $i, 'username' => 'bulk-' . $i, 'email' => 'bulk-' . $i . '@bulk.test', 'password' => 'x',
            'type' => UserType::USER, 'level' => 5, 'status' => 'user_active', 'created_at' => now(), 'updated_at' => now(),
        ], $chunk));
    }
}

/** Sign in an admin (type ADMIN, level 0) holding the given permission keys, e.g. `user.viewAny`. */
function utActor(array $keys): User
{
    URL::forceScheme('https');
    $actor = utUser('Actor', ['type' => UserType::ADMIN, 'level' => 0]);
    $role = Role::query()->create(['name' => 'tag-actor-' . Str::random(4), 'user_id' => $actor->id]);
    foreach ($keys as $key) {
        [$group, $name] = explode('.', $key);
        $role->permissions()->attach(Permission::query()->firstOrCreate(['group' => $group, 'name' => $name], ['title' => $key])->id);
    }
    $actor->roles()->attach($role->id);
    Sanctum::actingAs($actor);

    return $actor;
}

test('user tags live in the shared tags table (type user, uuid) paired through tags_data with a source', function () {
    expect(Schema::hasColumns('tags', ['id', 'uuid', 'type', 'name', 'slug']))->toBeTrue();
    expect(Schema::hasColumns('tags_data', ['target_type', 'target_id', 'tag_id', 'source']))->toBeTrue();
    expect(Schema::hasTable('user_tags'))->toBeFalse()->and(Schema::hasTable('user_tag_user'))->toBeFalse();
    $tag = Tag::query()->create(['name' => 'Triết', 'slug' => 'triet', 'type' => 'user']);
    expect($tag->uuid)->toBeString()->not->toBeEmpty();
    $user = utUser('Học viên');
    $user->tags()->attach($tag->id, ['target_type' => User::class, 'source' => 'manual']);
    expect($user->tags()->first()->pivot->source)->toBe('manual');
    $postTag = Tag::query()->create(['name' => 'Triết', 'slug' => 'triet']); // same slug, type post: allowed
    expect($postTag->type)->toBe('post')->and($user->tags()->count())->toBe(1);
    expect(app()->bound(UserTagService::class))->toBeTrue(); // Education guards its calls on this
});

test('findOrCreateByName trims, slugs and returns the existing tag for Triết and Triet', function () {
    $svc = app(UserTagService::class);
    $a = $svc->findOrCreateByName('  Triết ');
    $b = $svc->findOrCreateByName('Triet');
    expect($a->name)->toBe('Triết')->and($a->slug)->toBe('triet')->and($b->id)->toBe($a->id)->and(Tag::query()->ofType('user')->count())->toBe(1);
    expect(fn () => $svc->findOrCreateByName('   '))->toThrow(ValidationException::class);
    expect(fn () => $svc->findOrCreateByName('!!!'))->toThrow(ValidationException::class); // no letter or digit, no slug
    expect(mb_strlen($svc->findOrCreateByName(str_repeat('á', 200))->name))->toBe(120);
});

test('attachByName is idempotent and keeps the first source', function () {
    $svc = app(UserTagService::class);
    $user = utUser('Lan');
    $svc->attachByName($user, 'Triết', 'sync');
    $svc->attachByName($user, 'Triet', 'manual');
    $rows = DB::table('tags_data')->where('target_id', $user->id)->get();
    expect($rows)->toHaveCount(1)->and($rows->first()->source)->toBe('sync');
    expect(fn () => $svc->attachByName($user, 'X', 'bogus'))->toThrow(InvalidArgumentException::class);
});

test('filteredUsersQuery returns only active client or user accounts and never deleted ones', function () {
    $svc = app(UserTagService::class);
    $ok = utUser('Hoa');
    $client = utUser('Khách', ['type' => UserType::CLIENT]);
    utUser('Quản trị', ['type' => UserType::ADMIN]);
    utUser('Webmaster', ['type' => UserType::WEBMASTER]);
    utUser('Bị khóa', ['status' => 'user_banned']);
    utUser('Không trạng thái', ['status' => null]);
    utUser('Đã xóa')->delete();

    expect($svc->filteredUsersQuery([])->pluck('users.uuid')->sort()->values()->all())->toBe(collect([$ok->uuid, $client->uuid])->sort()->values()->all());
    expect($svc->filteredUsersQuery(['status' => 'all'])->count())->toBe(4); // + banned + null status
    expect($svc->filteredUsersQuery(['status' => 'user_banned'])->count())->toBe(1);
    expect($svc->filteredUsersQuery(['types' => [UserType::ADMIN], 'status' => 'all'])->count())->toBe(1);
    expect(fn () => $svc->filteredUsersQuery(['status' => 'nope']))->toThrow(ValidationException::class);
});

test('filteredUsersQuery filters by keyword, role ids and tags (all or any) and rejects unknown roles or tags', function () {
    $svc = app(UserTagService::class);
    $lan = utUser('Nguyễn Lan');
    $minh = utUser('Trần Minh');
    utUser('Lê Bảo');
    $role = Role::query()->create(['name' => 'Học viên']);
    $lan->roles()->attach($role->id);
    $triet = $svc->findOrCreateByName('Triết');
    $k24 = $svc->findOrCreateByName('Niên khóa 2024');
    $lan->tags()->attach([$triet->id => ['source' => 'manual'], $k24->id => ['source' => 'manual']]);
    $minh->tags()->attach([$triet->id => ['source' => 'manual']]);
    $names = static fn (array $filter): array => $svc->filteredUsersQuery($filter)->orderBy('users.id')->pluck('users.name')->all();

    expect($names(['q' => 'lan']))->toBe(['Nguyễn Lan']);
    expect($names(['roles' => [$role->id]]))->toBe(['Nguyễn Lan']);
    expect($names(['tags' => [$triet->uuid, $k24->uuid]]))->toBe(['Nguyễn Lan']); // match defaults to all
    expect($names(['tags' => [$triet->uuid, $k24->uuid], 'match' => 'any']))->toBe(['Nguyễn Lan', 'Trần Minh']);
    expect($names(['uuids' => [$minh->uuid]]))->toBe(['Trần Minh']);
    expect(fn () => $svc->filteredUsersQuery(['tags' => [(string) Str::uuid()]]))->toThrow(ValidationException::class, 'Tag không còn tồn tại');
    expect(fn () => $svc->filteredUsersQuery(['roles' => [999999]]))->toThrow(ValidationException::class, 'Nhóm không còn tồn tại');
});

test('attach and detach by ids keep the original source and count the affected users', function () {
    $svc = app(UserTagService::class);
    [$a, $b] = [utUser('A'), utUser('B')];
    [$t1, $t2] = [$svc->findOrCreateByName('Triết'), $svc->findOrCreateByName('Thần')];

    expect($svc->attach([$a->id, $b->id], [$t1->id], 'sync'))->toBe(2);
    expect($svc->attach([$a->id, $b->id], [$t1->id, $t2->id], 'manual'))->toBe(2); // the second tag is new for both
    expect($svc->attach([$a->id], [$t1->id, $t2->id], 'manual'))->toBe(0);
    expect(DB::table('tags_data')->where('tag_id', $t1->id)->pluck('source')->unique()->all())->toBe(['sync']);
    expect($svc->detach([$a->id, $b->id, 999999], [$t1->id, $t2->id]))->toBe(2); // detach ignores the source
    expect(DB::table('tags_data')->count())->toBe(0);
    expect($svc->detach([$a->id], [$t1->id]))->toBe(0);
});

test('attachToFilter re-runs the filter on the server and refuses more than 5,000 users', function () {
    $svc = app(UserTagService::class);
    $lan = utUser('Nguyễn Lan');
    utUser('Trần Minh');
    $tag = $svc->findOrCreateByName('Triết');

    expect($svc->attachToFilter(['q' => 'Lan'], [$tag->id], 'manual'))->toBe(1);
    expect(DB::table('tags_data')->pluck('target_id')->all())->toBe([$lan->id]);
    expect($svc->detachFromFilter(['q' => 'Lan'], [$tag->id]))->toBe(1);

    utBulkUsers(5001);
    expect(fn () => $svc->attachToFilter([], [$tag->id], 'manual'))->toThrow(ValidationException::class, 'tối đa 5.000');
    expect(DB::table('tags_data')->count())->toBe(0); // nothing was written
});

test('user-tags lists, creates (returning the existing tag on a slug clash), deletes by uuid and gates read and write', function () {
    utActor(['user.viewAny', 'user.update']);
    $created = $this->postJson(UT_BASE . '/user-tags', ['name' => 'Triết'])->assertOk()->json('data');
    expect(array_keys($created))->toBe(['uuid', 'name', 'users_count'])->and($created['users_count'])->toBe(0);
    $again = $this->postJson(UT_BASE . '/user-tags', ['name' => 'Triet'])->assertOk()->json('data');
    expect($again['uuid'])->toBe($created['uuid'])->and(Tag::query()->ofType('user')->count())->toBe(1);

    $this->getJson(UT_BASE . '/user-tags?q=triet')->assertOk()->assertJsonPath('data.items.0.name', 'Triết');
    $this->postJson(UT_BASE . '/user-tags', ['name' => ''])->assertStatus(422);
    $this->postJson(UT_BASE . '/user-tags', ['name' => str_repeat('a', 121)])->assertStatus(422);
    app(UserTagService::class)->attach([utUser('Lan')->id], [Tag::query()->ofType('user')->firstOrFail()->id], 'manual');
    $this->deleteJson(UT_BASE . '/user-tags/' . $created['uuid'])->assertOk(); // deleteTag also removes the pivot rows
    expect(Tag::query()->ofType('user')->count())->toBe(0)->and(DB::table('tags_data')->count())->toBe(0);
    $this->deleteJson(UT_BASE . '/user-tags/' . Str::uuid())->assertStatus(404);

    $tag = app(UserTagService::class)->findOrCreateByName('Thần');
    utActor(['user.viewAny']); // read only
    $this->getJson(UT_BASE . '/user-tags')->assertOk();
    $this->postJson(UT_BASE . '/user-tags', ['name' => 'X'])->assertForbidden();
    $this->deleteJson(UT_BASE . '/user-tags/' . $tag->uuid)->assertForbidden();
    utActor(['user.update']); // write only
    $this->getJson(UT_BASE . '/user-tags')->assertForbidden();
});

test('users/filter pages the filtered users with roles and tags and needs user.viewAny', function () {
    $svc = app(UserTagService::class);
    utActor(['user.viewAny']);
    $lan = utUser('Nguyễn Lan');
    utUser('Trần Minh');
    $triet = $svc->findOrCreateByName('Triết');
    $svc->attach([$lan->id], [$triet->id], 'manual');

    $r = $this->getJson(UT_BASE . '/users/filter?tags[]=' . $triet->uuid)->assertOk()->json('data');
    expect($r['total'])->toBe(1)->and($r['items'][0]['uuid'])->toBe($lan->uuid)->and($r['items'][0]['tags'][0]['name'])->toBe('Triết');
    expect($r['items'][0])->toHaveKeys(['id', 'uuid', 'username', 'email', 'status', 'level', 'roles', 'tags']);
    $this->getJson(UT_BASE . '/users/filter?tags[]=' . Str::uuid())->assertStatus(422);

    utActor(['user.update']);
    $this->getJson(UT_BASE . '/users/filter')->assertForbidden();
});

test('attach and detach by user uuids write manual tags, a banned user included', function () {
    utActor(['user.viewAny', 'user.update']);
    $a = utUser('A');
    $b = utUser('B', ['status' => 'user_banned']); // a banned user can still be tagged from the Users list
    $tag = app(UserTagService::class)->findOrCreateByName('Triết');

    $this->postJson(UT_BASE . '/users/tags/attach', ['tag_uuids' => [$tag->uuid], 'user_uuids' => [$a->uuid, $b->uuid]])->assertOk()->assertJsonPath('data.affected', 2);
    expect(DB::table('tags_data')->pluck('source')->unique()->all())->toBe(['manual']);
    $this->postJson(UT_BASE . '/users/tags/detach', ['tag_uuids' => [$tag->uuid], 'user_uuids' => [$a->uuid]])->assertOk()->assertJsonPath('data.affected', 1);
    expect(DB::table('tags_data')->count())->toBe(1);
});

test('attach by filter runs on the server, needs exactly one selection mode and user.update, and honours the cap', function () {
    utActor(['user.viewAny', 'user.update']);
    $lan = utUser('Nguyễn Lan');
    utUser('Trần Minh');
    $tag = app(UserTagService::class)->findOrCreateByName('Triết');
    $url = UT_BASE . '/users/tags/attach';

    $this->postJson($url, ['tag_uuids' => [$tag->uuid], 'filter' => ['q' => 'Lan', 'status' => 'all']])->assertOk()->assertJsonPath('data.affected', 1);
    expect(DB::table('tags_data')->pluck('target_id')->all())->toBe([$lan->id]);
    $this->postJson($url, ['tag_uuids' => [$tag->uuid], 'user_uuids' => [$lan->uuid], 'filter' => ['q' => 'A']])->assertStatus(422); // both
    $this->postJson($url, ['tag_uuids' => [$tag->uuid]])->assertStatus(422); // neither
    $this->postJson($url, ['tag_uuids' => [(string) Str::uuid()], 'user_uuids' => [$lan->uuid]])->assertStatus(422); // unknown tag

    utBulkUsers(5001);
    expect($this->postJson($url, ['tag_uuids' => [$tag->uuid], 'filter' => ['status' => 'all']])->assertStatus(422)->getContent())->toContain('5.000');

    utActor(['user.viewAny']);
    $this->postJson($url, ['tag_uuids' => [$tag->uuid], 'user_uuids' => [$lan->uuid]])->assertForbidden();
});
