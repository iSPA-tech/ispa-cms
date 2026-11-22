<?php

use App\Constants\UserType;
use App\Enums\UserStatusEnum;
use App\Models\User;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\Permission;
use Cms\Core\Models\RecordNote;
use Cms\Core\Models\Role;
use Cms\Core\Notifications\RecordMentionedNotification;
use Cms\Core\Records\RecordNoteService;
use Cms\Core\Records\RecordType;
use Cms\Core\Services\CMSSettingUnified;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(Cms\Core\Tests\TestCase::class);

/** Create a user holding the given permission keys through a role. */
function recordNoteUser(string $name, array $permissions = ['fixture.view'], ?string $status = null): User
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
            $role->permissions()->attach(Permission::query()->firstOrCreate(['group' => $group, 'name' => $ability])->id);
        }
        $user->roles()->attach($role->id);
    }

    return $user->fresh();
}

beforeEach(function () {
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
    Schema::create('notifications', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('type');
        $table->morphs('notifiable');
        $table->text('data');
        $table->timestamp('read_at')->nullable();
        $table->timestamps();
    });
    // Banning or deactivating an account revokes its API tokens (P10), so the token table must exist.
    Schema::create('personal_access_tokens', function (Blueprint $table) {
        $table->id();
        $table->morphs('tokenable');
        $table->string('name')->nullable();
        $table->string('token', 64)->nullable();
        $table->text('abilities')->nullable();
        $table->string('audience')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
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

    $this->record = recordNoteUser('Record', []);
    $this->type = new RecordType('fixture.users', User::class, 'fixture.view', 'fixture.manage');
    $this->service = new RecordNoteService();
});

it('never notifies a mentioned user without the view ability and counts them as ignored', function () {
    Notification::fake();
    $author = recordNoteUser('Author');
    $outsider = recordNoteUser('Outsider', []);

    $result = $this->service->add($this->type, $this->record, $author, 'Hi', [$outsider->uuid]);

    expect($result['ignored'])->toBe(1)->and($result['note']->mentions)->toBe([]);
    Notification::assertNothingSent();
});

it('never notifies a user the resolver hides the record from', function () {
    Notification::fake();
    $author = recordNoteUser('Author');
    $blocked = recordNoteUser('Blocked');
    $type = new RecordType('fixture.scoped', User::class, 'fixture.view', 'fixture.manage',
        static fn (string $uuid, $user) => $user->name === 'Blocked' ? null : User::query()->where('uuid', $uuid)->first());

    $result = $this->service->add($type, $this->record, $author, 'Hi', [$blocked->uuid]);

    expect($result['ignored'])->toBe(1);
    Notification::assertNothingSent();
});

it('drops inactive, banned, deleted and unknown users', function () {
    Notification::fake();
    $author = recordNoteUser('Author');
    $inactive = recordNoteUser('Inactive', ['fixture.view'], UserStatusEnum::USER_INACTIVE->value);
    $banned = recordNoteUser('Banned', ['fixture.view'], UserStatusEnum::USER_BANNED->value);
    $deleted = recordNoteUser('Deleted');
    $deleted->delete();
    $active = recordNoteUser('Active', ['fixture.view'], UserStatusEnum::USER_ACTIVE->value);

    $result = $this->service->add($this->type, $this->record, $author, 'Hi',
        [$inactive->uuid, $banned->uuid, $deleted->uuid, (string) Str::uuid(), $active->uuid, $active->uuid, 42]);

    expect($result['ignored'])->toBe(5)->and($result['note']->mentions)->toBe([$active->uuid]);
    Notification::assertSentTo($active, RecordMentionedNotification::class);
    Notification::assertSentTimes(RecordMentionedNotification::class, 1);
});

it('never notifies the author of their own mention and does not count it', function () {
    Notification::fake();
    $author = recordNoteUser('Author');

    $result = $this->service->add($this->type, $this->record, $author, 'Note to self', [$author->uuid]);

    expect($result['ignored'])->toBe(0)->and($result['note']->mentions)->toBe([]);
    Notification::assertNothingSent();
});

it('stores a notification payload without the note text', function () {
    $author = recordNoteUser('Author');
    $mate = recordNoteUser('Mate');

    $note = $this->service->add($this->type, $this->record, $author, 'very private words', [$mate->uuid])['note'];

    $row = DB::table('notifications')->where('notifiable_id', $mate->id)->first();
    expect($row)->not->toBeNull()
        ->and($row->data)->not->toContain('very private words')
        ->and(json_decode($row->data, true))->toBe([
            'type' => 'fixture.users.mentioned',
            'payload' => ['record_type' => 'fixture.users', 'record_uuid' => $this->record->uuid, 'note_uuid' => $note->uuid],
        ])
        ->and(DB::table('notifications')->where('notifiable_id', $author->id)->count())->toBe(0);
});

it('sends notifications only after the surrounding transaction commits', function () {
    Notification::fake();
    $author = recordNoteUser('Author');
    $mate = recordNoteUser('Mate');

    DB::transaction(function () use ($author, $mate) {
        $this->service->add($this->type, $this->record, $author, 'Hi', [$mate->uuid]);
        Notification::assertNothingSent();
    });

    Notification::assertSentTo($mate, RecordMentionedNotification::class);
});

it('rejects more than ten distinct mentions', function () {
    $mentions = array_map(static fn () => (string) Str::uuid(), range(1, 11));
    $this->service->add($this->type, $this->record, recordNoteUser('Author'), 'Hi', $mentions);
})->throws(ValidationException::class);

it('accepts eleven entries that dedupe to ten', function () {
    Notification::fake();
    $mentions = array_map(static fn () => (string) Str::uuid(), range(1, 10));
    $mentions[] = $mentions[0];

    $result = $this->service->add($this->type, $this->record, recordNoteUser('Author'), 'Hi', $mentions);

    expect($result['ignored'])->toBe(10);
});

it('cleans the body and enforces 1-4000 characters', function () {
    expect(RecordNoteService::cleanBody("  line one\r\nline\ttwo\x00\x07\x1B  "))->toBe("line one\nline\ttwo")
        ->and(mb_strlen(RecordNoteService::cleanBody(str_repeat('é', 4000))))->toBe(4000);

    foreach (['', "   \n\t ", "\x01\x02", str_repeat('a', 4001), "bad \xC3\x28 utf8"] as $body) {
        expect(fn () => RecordNoteService::cleanBody($body))->toThrow(ValidationException::class);
    }
});

it('fires the note_added audit event against the record without note text', function () {
    $author = recordNoteUser('Author');
    Notification::fake();

    $this->service->add($this->type, $this->record, $author, 'hidden body', []);

    Event::assertDispatched(AuditLogEvent::class, fn (AuditLogEvent $e) => $e->action === 'note_added'
        && $e->type === User::class
        && $e->id === $this->record->id
        && $e->user_id === $author->id
        && !str_contains((string) json_encode($e->changed), 'hidden body'));
});

it('lets only the author update and notifies only newly added mentions', function () {
    Notification::fake();
    $author = recordNoteUser('Author');
    $first = recordNoteUser('First');
    $second = recordNoteUser('Second');
    $note = $this->service->add($this->type, $this->record, $author, 'Hi', [$first->uuid])['note'];
    Notification::assertSentToTimes($first, RecordMentionedNotification::class, 1);

    expect(fn () => $this->service->update($this->type, $this->record, $note, $second, 'x', []))
        ->toThrow(AuthorizationException::class);

    $result = $this->service->update($this->type, $this->record, $note, $author, 'Edited', [$first->uuid, $second->uuid]);

    expect($result['note']->body)->toBe('Edited')
        ->and($result['note']->edited_at)->not->toBeNull()
        ->and($result['note']->mentions)->toBe([$first->uuid, $second->uuid]);
    Notification::assertSentToTimes($first, RecordMentionedNotification::class, 1);
    Notification::assertSentToTimes($second, RecordMentionedNotification::class, 1);
});

it('soft-deletes a note', function () {
    Notification::fake();
    $note = $this->service->add($this->type, $this->record, recordNoteUser('Author'), 'Hi', [])['note'];

    $this->service->delete($note);

    expect(RecordNote::query()->count())->toBe(0)->and(RecordNote::withTrashed()->count())->toBe(1);
});
