<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Models\Workflows\WorkflowCredential;
use Cms\Core\Services\Workflow\Credentials\WorkflowCredentialStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

// The step-up grant on create/update/delete is covered by Guard/WorkflowAlertsAndAuditTest; here the CRUD contract only.
beforeEach(function (): void {
    $this->withoutMiddleware(\Cms\Core\Http\Middleware\RequireStepUpGrant::class);
});

function wcApiSignIn(array $permissions): User
{
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'name' => 'Cred Admin', 'username' => 'ca' . Str::random(6),
        'email' => 'ca' . Str::random(8) . '@example.test', 'email_verified_at' => now(),
        'password' => Hash::make('secret123'), 'type' => 0, 'level' => 0, 'status' => null,
    ]);
    $actor = new class($permissions) extends User
    {
        /** @param array<int, string> $keys */
        public function __construct(private array $keys = [])
        {
            parent::__construct();
        }

        public function permissionKeys(): array
        {
            return $this->keys;
        }

        public function loadMissing($relations)
        {
            return $this;
        }

        public function isWebmaster(): bool
        {
            return false;
        }
    };
    $actor->setRawAttributes($user->getAttributes(), true);
    $actor->exists = true;
    Sanctum::actingAs($actor);

    return $actor;
}

const WC_URL = 'https://localhost/api/v2/admin/workflow/credentials';

it('refuses every route without workflow_credential.manage', function (): void {
    wcApiSignIn([]);
    $credential = app(WorkflowCredentialStore::class)->create(['name' => 'bot', 'type' => 'telegram_bot', 'payload' => ['token' => 'T0KEN-VALUE']], null);

    $this->getJson(WC_URL)->assertForbidden();
    $this->postJson(WC_URL, ['name' => 'x', 'type' => 'telegram_bot', 'payload' => ['token' => 'abcd']])->assertForbidden();
    $this->putJson(WC_URL . '/' . $credential->uuid, ['name' => 'y'])->assertForbidden();
    $this->deleteJson(WC_URL . '/' . $credential->uuid)->assertForbidden();
    $this->postJson(WC_URL . '/' . $credential->uuid . '/test')->assertForbidden();
});

it('creates, lists, updates and deletes without ever returning a secret', function (): void {
    wcApiSignIn(['workflow_credential.manage']);

    $created = $this->postJson(WC_URL, [
        'name' => 'church_bot', 'type' => 'telegram_bot', 'description' => 'Parish bot', 'payload' => ['token' => '999:SUPER-SECRET'],
    ])->assertCreated();
    expect($created->getContent())->not->toContain('SUPER-SECRET')
        ->and($created->json('data.binding'))->toBe('$bind:church_bot')
        ->and($created->json('data.fields_set'))->toBe(['token' => true]);

    $list = $this->getJson(WC_URL)->assertOk();
    expect($list->getContent())->not->toContain('SUPER-SECRET')
        ->and($list->json('data.0.name'))->toBe('church_bot')
        ->and($list->json('meta.types.smtp'))->toContain('host');

    $uuid = $created->json('data.uuid');
    $this->putJson(WC_URL . '/' . $uuid, ['name' => 'parish_bot', 'payload' => ['token' => '']])->assertOk()
        ->assertJsonPath('data.name', 'parish_bot');
    expect(app(WorkflowCredentialStore::class)->resolve('parish_bot', 'telegram_bot'))->toBe(['token' => '999:SUPER-SECRET']);

    $this->postJson(WC_URL, ['name' => 'parish_bot', 'type' => 'telegram_bot', 'payload' => ['token' => 'abcd']])->assertStatus(422);
    $this->postJson(WC_URL, ['name' => 'other', 'type' => 'unknown', 'payload' => ['token' => 'abcd']])->assertStatus(422);

    $this->deleteJson(WC_URL . '/' . $uuid)->assertOk();
    expect(WorkflowCredential::query()->count())->toBe(0);
});

it('tests a telegram credential against the fixed host and redacts the answer', function (): void {
    wcApiSignIn(['workflow_credential.manage']);
    Http::preventStrayRequests();
    Http::fake(['https://api.telegram.org/bot999:SUPER-SECRET/getMe' => Http::response(['ok' => false, 'description' => 'Unauthorized 999:SUPER-SECRET'], 401)]);
    $credential = app(WorkflowCredentialStore::class)->create(['name' => 'bot', 'type' => 'telegram_bot', 'payload' => ['token' => '999:SUPER-SECRET']], null);

    $response = $this->postJson(WC_URL . '/' . $credential->uuid . '/test')->assertOk()
        ->assertJsonPath('data.status', 'failed');

    expect($response->getContent())->not->toContain('SUPER-SECRET')
        ->and($credential->fresh()->last_test_ok)->toBeFalse();
});

it('reports HTTP auth credentials as not testable', function (): void {
    wcApiSignIn(['workflow_credential.manage']);
    Http::preventStrayRequests();
    $credential = app(WorkflowCredentialStore::class)->create(['name' => 'api', 'type' => 'http_basic', 'payload' => ['username' => 'u', 'password' => 'pw-123']], null);

    $this->postJson(WC_URL . '/' . $credential->uuid . '/test')->assertOk()->assertJsonPath('data.status', 'not_testable');
});
