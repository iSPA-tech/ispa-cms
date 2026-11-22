<?php

declare(strict_types=1);

use App\Constants\UserType;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use Cms\Content\Models\Content;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\AuditLog;
use Cms\Core\Records\RecordRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(\Tests\TestCase::class, RefreshDatabase::class);

/**
 * Create a user, optionally holding the given permission names (group "content") through a role, and sign it in.
 *
 * @param array<int, string> $names
 */
function contentHistoryActor(array $names): User
{
    DB::table(CoreTableConstant::ENUMERATES)->insertOrIgnore(['name' => 'active', 'enum_key' => 'active', 'type' => 'status', 'created_at' => now(), 'updated_at' => now()]);
    $id = (int) DB::table('users')->insertGetId([
        'uuid' => (string) Str::uuid(), 'name' => 'Content Editor', 'email' => Str::random(6) . '@example.test',
        'password' => 'x', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    if ($names !== []) {
        $roleId = DB::table(CoreTableConstant::ROLES)->insertGetId(['name' => 'Role ' . Str::random(4), 'created_at' => now(), 'updated_at' => now()]);
        foreach ($names as $name) {
            $permissionId = DB::table(CoreTableConstant::PERMISSIONS)->insertGetId([
                'group' => 'content', 'name' => $name, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table(CoreTableConstant::PERMISSION_ROLE)->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
        DB::table(CoreTableConstant::ROLE_USER)->insert(['role_id' => $roleId, 'user_id' => $id]);
    }

    // The api.admin middleware only admits Admin/Webmaster accounts; the Gate then decides on the permission key.
    DB::table('users')->where('id', $id)->update(['type' => UserType::ADMIN]);
    test()->withoutMiddleware([HttpsProtocol::class, Localization::class, ApiLocalization::class, ThrottleRequests::class]);
    config()->set('ispa-cms.admin_auth_key', 'admin-test-key');
    test()->withHeader('ISPA-ADMIN-AUTH-KEY', 'admin-test-key');
    $user = User::query()->findOrFail($id);
    Sanctum::actingAs($user);

    return $user;
}

/** Insert a content row directly (no observer) and return the model. */
function contentHistoryRecord(): Content
{
    $id = (int) DB::table('contents')->insertGetId([
        'uuid' => (string) Str::uuid(), 'name' => 'SECRET-TITLE-XYZ', 'description' => 'SECRET-DESC-XYZ',
        'content' => json_encode('SECRET-BODY-XYZ'), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return Content::query()->findOrFail($id);
}

/**
 * @param array<string, mixed> $changed
 */
function contentHistoryAudit(Content $content, string $action, array $changed, int $userId): void
{
    AuditLog::query()->create([
        'action' => 'action.' . $action,
        'changed' => $changed,
        'target_type' => $content::class,
        'target_id' => $content->getKey(),
        'user_id' => $userId,
    ]);
}

function contentHistoryUrl(Content $content): string
{
    return 'https://localhost/api/v2/admin/records/content.content/' . $content->uuid . '/history';
}

it('registers content.content with the content abilities and a field whitelist', function (): void {
    $type = app(RecordRegistry::class)->get('content.content');

    expect($type)->not->toBeNull()
        ->and($type->model)->toBe(Content::class)
        ->and($type->viewAbility)->toBe('content.view')
        ->and($type->manageAbility)->toBe('content.update')
        ->and($type->historyFields)->toBe(['status', 'is_confirmed', 'language_id'])
        ->and($type->actionLabels['action.forceDelete'])->toBe('text.content.history.forceDelete');
});

it('shows an update event with the status change, the actor and the label', function (): void {
    $user = contentHistoryActor(['view']);
    $content = contentHistoryRecord();
    contentHistoryAudit($content, 'update', ['status' => ['from' => 'status_pending', 'to' => 'status_published']], (int) $user->id);

    $response = test()->getJson(contentHistoryUrl($content))->assertOk();

    expect($response->json('data.0.action'))->toBe('action.update')
        ->and($response->json('data.0.label'))->toBe('text.content.history.update')
        ->and($response->json('data.0.actor.name'))->toBe('Content Editor')
        ->and(json_encode($response->json('data.0.changes')))->toContain('status')->toContain('status_published');
});

it('never exposes title, description or body values', function (): void {
    $user = contentHistoryActor(['view']);
    $content = contentHistoryRecord();
    contentHistoryAudit($content, 'update', [
        'status' => ['from' => 'status_pending', 'to' => 'status_published'],
        'name' => ['from' => 'SECRET-TITLE-XYZ', 'to' => 'SECRET-NEW-TITLE'],
        'description' => ['from' => 'SECRET-DESC-XYZ', 'to' => 'SECRET-NEW-DESC'],
        'content' => ['from' => 'SECRET-BODY-XYZ', 'to' => 'SECRET-NEW-BODY'],
    ], (int) $user->id);
    contentHistoryAudit($content, 'create', $content->toArray(), (int) $user->id);

    $body = test()->getJson(contentHistoryUrl($content))->assertOk()->getContent();

    expect($body)->not->toContain('SECRET-');
});

it('answers 403 to a user without content.view', function (): void {
    contentHistoryActor([]);
    $content = contentHistoryRecord();

    test()->getJson(contentHistoryUrl($content))->assertForbidden();
});
