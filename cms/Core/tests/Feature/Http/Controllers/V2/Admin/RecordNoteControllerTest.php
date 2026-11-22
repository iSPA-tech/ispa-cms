<?php

namespace Cms\Core\Tests\Feature\Http\Controllers\V2\Admin;

use App\Constants\UserType;
use App\Enums\UserStatusEnum;
use App\Models\User;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\Permission;
use Cms\Core\Models\RecordNote;
use Cms\Core\Models\Role;
use Cms\Core\Notifications\RecordMentionedNotification;
use Cms\Core\Records\RecordRegistry;
use Cms\Core\Records\RecordType;
use Cms\Core\Services\CMSSettingUnified;
use Cms\Core\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Feature tests for record notes and @mentions, privacy cases first.
 */
class RecordNoteControllerTest extends TestCase
{
    /** The record the notes are attached to. */
    private User $record;

    /** Create the tables and register the fixture record types. */
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('status')->nullable();
            $table->integer('type')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('setting_key')->nullable();
            $table->text('setting_value')->nullable();
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('custom')->nullable();
            $table->timestamps();
        $table->softDeletes();
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('group')->nullable();
            $table->string('title')->nullable();
            $table->timestamps();
        $table->softDeletes();
        });
        Schema::create('role_user', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('user_id');
        });
        Schema::create('permission_role', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
        });
        (require base_path('cms/Core/database/migrations/2026_11_05_110000_create_core_record_notes_table.php'))->up();

        Event::fake([AuditLogEvent::class]);
        // Settings table is absent: the mail channel setting falls back to its default (off).
        app()->instance(CMSSettingUnified::class, new class extends CMSSettingUnified {
            public function __construct()
            {
            }

            public function get(string $settingKey, mixed $default = null, array $options = []): mixed
            {
                return $default;
            }
        });
        Notification::fake();

        $this->record = $this->user('Record', []);

        $registry = app(RecordRegistry::class);
        $registry->register(new RecordType('fixture.users', User::class, 'fixture.view', 'fixture.manage'));
        $registry->register(new RecordType('fixture.hidden', User::class, 'fixture.view', 'fixture.manage', static fn (string $uuid, $user) => null));
        $registry->register(new RecordType('fixture.other', User::class, 'fixture.view', 'fixture.manage'));
    }

    /**
     * Create a user holding the given permission keys through a role.
     *
     * @param  array<string>  $permissions
     */
    private function user(string $name, array $permissions, ?string $status = null): User
    {
        $user = User::query()->forceCreate([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'email' => Str::slug($name) . '-' . Str::lower(Str::random(5)) . '@example.test',
            'password' => 'secret',
            'status' => $status,
            'type' => UserType::ADMIN,
        ]);

        if ($permissions !== []) {
            $role = Role::query()->create(['name' => 'role-' . $name]);
            foreach ($permissions as $key) {
                [$group, $ability] = explode('.', $key, 2);
                $permission = Permission::query()->firstOrCreate(['group' => $group, 'name' => $ability]);
                $role->permissions()->attach($permission->id);
            }
            $user->roles()->attach($role->id);
        }

        return $user->fresh();
    }

    /** Sign the user in through Sanctum. */
    private function as(User $user): User
    {
        Sanctum::actingAs($user);

        return $user;
    }

    /** Notes URL of the fixture record. */
    private function url(string $suffix = '', string $type = 'fixture.users', ?string $uuid = null): string
    {
        return 'https://localhost/api/v2/admin/records/' . $type . '/' . ($uuid ?? $this->record->uuid) . '/notes' . $suffix;
    }

    /** Mentionable URL of the fixture record. */
    private function mentionableUrl(string $q, string $type = 'fixture.users'): string
    {
        return 'https://localhost/api/v2/admin/records/' . $type . '/' . $this->record->uuid . '/mentionable?q=' . urlencode($q);
    }

    /** Store a note directly. */
    private function note(User $author, string $type = 'fixture.users', ?int $targetId = null, string $body = 'A note'): RecordNote
    {
        return RecordNote::query()->create([
            'target_type' => $type,
            'target_id' => $targetId ?? $this->record->id,
            'body' => $body,
            'mentions' => [],
            'user_id' => $author->id,
        ]);
    }

    // --- Must-not-see cases ---

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson($this->url())->assertStatus(401);
        $this->postJson($this->url(), ['body' => 'x'])->assertStatus(401);
        $this->getJson($this->mentionableUrl('ab'))->assertStatus(401);
    }

    public function test_user_without_view_ability_cannot_list_add_edit_or_delete(): void
    {
        $owner = $this->user('Owner', ['fixture.view']);
        $note = $this->note($owner);
        $this->as($this->user('Outsider', []));

        $this->getJson($this->url())->assertStatus(403);
        $this->postJson($this->url(), ['body' => 'x'])->assertStatus(403);
        $this->putJson($this->url('/' . $note->uuid), ['body' => 'x'])->assertStatus(403);
        $this->deleteJson($this->url('/' . $note->uuid))->assertStatus(403);
        $this->getJson($this->mentionableUrl('ab'))->assertStatus(403);
        $this->assertSame('A note', $note->fresh()->body);
    }

    public function test_record_hidden_by_resolver_returns_404_everywhere(): void
    {
        $this->as($this->user('Viewer', ['fixture.view', 'fixture.manage']));
        $note = $this->note($this->record, 'fixture.hidden');

        $this->getJson($this->url('', 'fixture.hidden'))->assertStatus(404);
        $this->postJson($this->url('', 'fixture.hidden'), ['body' => 'x'])->assertStatus(404);
        $this->putJson($this->url('/' . $note->uuid, 'fixture.hidden'), ['body' => 'x'])->assertStatus(404);
        $this->deleteJson($this->url('/' . $note->uuid, 'fixture.hidden'))->assertStatus(404);
        $this->getJson($this->mentionableUrl('ab', 'fixture.hidden'))->assertStatus(404);
    }

    public function test_unknown_type_and_unknown_record_return_404(): void
    {
        $this->as($this->user('Viewer', ['fixture.view']));

        $this->getJson($this->url('', 'fixture.nope'))->assertStatus(404);
        $this->getJson($this->url('', 'fixture.users', (string) Str::uuid()))->assertStatus(404);
    }

    public function test_note_of_another_record_returns_404_on_update_and_delete(): void
    {
        $actor = $this->as($this->user('Actor', ['fixture.view', 'fixture.manage']));
        $otherRecord = $this->user('Other record', []);
        $foreign = $this->note($actor, 'fixture.users', $otherRecord->id);
        $otherType = $this->note($actor, 'fixture.other');

        foreach ([$foreign, $otherType] as $note) {
            $this->putJson($this->url('/' . $note->uuid), ['body' => 'changed'])->assertStatus(404);
            $this->deleteJson($this->url('/' . $note->uuid))->assertStatus(404);
            $this->assertSame('A note', $note->fresh()->body);
            $this->assertNull($note->fresh()->deleted_at);
        }
    }

    public function test_mention_of_user_without_view_ability_or_inactive_is_dropped_and_counted(): void
    {
        $this->as($this->user('Author', ['fixture.view']));
        $allowed = $this->user('Allowed', ['fixture.view']);
        $noAbility = $this->user('No ability', []);
        $banned = $this->user('Banned', ['fixture.view'], UserStatusEnum::USER_BANNED->value);

        $response = $this->postJson($this->url(), [
            'body' => 'Hello',
            'mentions' => [$allowed->uuid, $noAbility->uuid, $banned->uuid, (string) Str::uuid()],
        ])->assertStatus(201);

        $this->assertSame(3, $response->json('meta.ignored'));
        $this->assertSame([['uuid' => $allowed->uuid, 'name' => 'Allowed']], $response->json('data.mentions'));
        Notification::assertSentTo($allowed, RecordMentionedNotification::class);
        Notification::assertNotSentTo([$noAbility, $banned], RecordMentionedNotification::class);
        $raw = (string) $response->getContent();
        $this->assertStringNotContainsString('No ability', $raw);
        $this->assertStringNotContainsString('Banned', $raw);
    }

    public function test_mentionable_never_returns_users_without_view_ability_and_caps_at_ten(): void
    {
        $actor = $this->as($this->user('Searcher', ['fixture.view']));
        for ($i = 0; $i < 12; $i++) {
            $this->user("Team mate {$i}", ['fixture.view']);
        }
        $hidden = $this->user('Team outsider', []);
        $inactive = $this->user('Team sleeper', ['fixture.view'], UserStatusEnum::USER_INACTIVE->value);

        $response = $this->getJson($this->mentionableUrl('team'))->assertOk();
        $data = $response->json('data');

        $this->assertCount(10, $data);
        $uuids = array_column($data, 'uuid');
        $this->assertNotContains($hidden->uuid, $uuids);
        $this->assertNotContains($inactive->uuid, $uuids);
        $this->assertNotContains($actor->uuid, $uuids);
        $this->assertSame(['uuid', 'name'], array_keys($data[0]));
        $this->assertStringNotContainsString('@example.test', (string) $response->getContent());
    }

    public function test_mentionable_rejects_short_query(): void
    {
        $this->as($this->user('Searcher', ['fixture.view']));

        $this->getJson($this->mentionableUrl('a'))->assertStatus(422);
        $this->getJson('https://localhost/api/v2/admin/records/fixture.users/' . $this->record->uuid . '/mentionable')->assertStatus(422);
    }

    // --- Ownership ---

    public function test_only_author_edits(): void
    {
        $author = $this->user('Author', ['fixture.view']);
        $note = $this->note($author);
        $this->as($this->user('Manager', ['fixture.view', 'fixture.manage']));

        $this->putJson($this->url('/' . $note->uuid), ['body' => 'hijack'])->assertStatus(403);
        $this->assertSame('A note', $note->fresh()->body);

        $this->as($author);
        $this->putJson($this->url('/' . $note->uuid), ['body' => '  Fixed  '])->assertOk()
            ->assertJsonPath('data.body', 'Fixed')
            ->assertJsonPath('data.can_edit', true);
        $this->assertNotNull($note->fresh()->edited_at);
    }

    public function test_author_or_manager_deletes_but_other_viewer_cannot(): void
    {
        $author = $this->user('Author', ['fixture.view']);
        $first = $this->note($author);
        $second = $this->note($author);

        $this->as($this->user('Viewer', ['fixture.view']));
        $this->deleteJson($this->url('/' . $first->uuid))->assertStatus(403);
        $this->assertNull($first->fresh()->deleted_at);

        $this->as($author);
        $this->deleteJson($this->url('/' . $first->uuid))->assertNoContent();
        $this->assertNotNull(RecordNote::withTrashed()->find($first->id)->deleted_at);

        $this->as($this->user('Manager', ['fixture.view', 'fixture.manage']));
        $this->deleteJson($this->url('/' . $second->uuid))->assertNoContent();
    }

    public function test_cross_user_listing_flags_permissions_per_note(): void
    {
        $alice = $this->user('Alice', ['fixture.view']);
        $bob = $this->user('Bob', ['fixture.view']);
        $this->note($alice, body: 'from alice');
        $this->note($bob, body: 'from bob');

        $this->as($bob);
        $data = collect($this->getJson($this->url())->assertOk()->json('data'))->keyBy('body');

        $this->assertTrue($data['from bob']['can_edit']);
        $this->assertTrue($data['from bob']['can_delete']);
        $this->assertFalse($data['from alice']['can_edit']);
        $this->assertFalse($data['from alice']['can_delete']);
        $this->assertSame(['uuid' => $alice->uuid, 'name' => 'Alice'], $data['from alice']['author']);
    }

    // --- Shape and limits ---

    public function test_list_shape_never_exposes_ids_and_pages_by_uuid_cursor(): void
    {
        $actor = $this->as($this->user('Actor', ['fixture.view']));
        $uuids = [];
        for ($i = 0; $i < 22; $i++) {
            $uuids[] = $this->note($actor, body: "n{$i}")->uuid;
        }
        $this->note($actor, 'fixture.other');
        $uuids = array_reverse($uuids);

        $first = $this->getJson($this->url())->assertOk();
        $this->assertSame(array_slice($uuids, 0, 20), array_column($first->json('data'), 'uuid'));
        $this->assertSame($uuids[19], $first->json('meta.next_before'));
        $this->assertSame(['uuid', 'body', 'author', 'mentions', 'created_at', 'edited_at', 'can_edit', 'can_delete'], array_keys($first->json('data.0')));
        $this->assertStringNotContainsString('"id"', (string) $first->getContent());
        $this->assertStringNotContainsString('@example.test', (string) $first->getContent());

        $second = $this->getJson($this->url('?before=' . $uuids[19]))->assertOk();
        $this->assertSame(array_slice($uuids, 20), array_column($second->json('data'), 'uuid'));
        $this->assertNull($second->json('meta.next_before'));

        $this->getJson($this->url('?before=' . Str::uuid()))->assertStatus(422);
    }

    public function test_store_rejects_long_body_and_eleven_mentions(): void
    {
        $this->as($this->user('Author', ['fixture.view']));

        $this->postJson($this->url(), ['body' => str_repeat('a', 4001)])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->postJson($this->url(), ['body' => "  \x01\x02 "])->assertStatus(422)->assertJsonValidationErrors('body');
        $mentions = array_map(static fn () => (string) Str::uuid(), range(1, 11));
        $this->postJson($this->url(), ['body' => 'ok', 'mentions' => $mentions])->assertStatus(422)->assertJsonValidationErrors('mentions');
        $this->assertSame(0, RecordNote::query()->count());
    }

    public function test_store_fires_audit_without_note_text(): void
    {
        $this->as($this->user('Author', ['fixture.view']));

        $this->postJson($this->url(), ['body' => 'secret note text'])->assertStatus(201)->assertJsonPath('meta.ignored', 0);

        Event::assertDispatched(AuditLogEvent::class, function (AuditLogEvent $event): bool {
            return $event->action === 'note_added'
                && $event->type === User::class
                && $event->id === $this->record->id
                && !str_contains((string) json_encode($event->changed), 'secret note text');
        });
    }

    public function test_write_routes_are_throttled(): void
    {
        $expected = [
            'api.v2.records.notes.index' => 'throttle.route:120,1',
            'api.v2.records.notes.store' => 'throttle.route:30,1',
            'api.v2.records.notes.update' => 'throttle.route:30,1',
            'api.v2.records.notes.destroy' => 'throttle.route:30,1',
            'api.v2.records.mentionable' => 'throttle.route:60,1',
        ];
        foreach ($expected as $name => $middleware) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains($middleware, $route->gatherMiddleware(), $name);
            $this->assertContains('auth:sanctum', $route->gatherMiddleware(), $name);
        }
    }
}
