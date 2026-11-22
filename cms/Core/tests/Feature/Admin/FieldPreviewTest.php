<?php

use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use Cms\Core\Models\Field;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Laravel\Sanctum\Sanctum;

uses(\Cms\Core\Tests\TestCase::class);

class FieldPreviewTestUser extends User
{
    public array $allowedAbilities = [];

    public function hasRole(string $key = null): bool
    {
        return in_array($key, $this->allowedAbilities, true);
    }
}

beforeEach(function (): void {
    $this->withoutVite();
    $this->withoutMiddleware([ApiLocalization::class, HttpsProtocol::class, Localization::class, ThrottleRequests::class]);
    Session::put('language-locale-' . config('app.locale'), 'en');
    Session::put('language-locales', []);

    Schema::dropIfExists('field_items');
    Schema::dropIfExists('fields');
    Schema::dropIfExists('enumerates');
    Schema::create('enumerates', static function (Blueprint $table): void {
        $table->string('enum_key')->primary();
    });
    Schema::create('fields', static function (Blueprint $table): void {
        $table->id();
        $table->string('form_id');
        $table->string('name');
        $table->json('custom')->nullable();
        $table->text('styles')->nullable();
        $table->string('status');
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('field_items', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->foreignId('field_id');
        $table->string('field_name');
        $table->string('type');
        $table->text('description')->nullable();
        $table->json('options')->nullable();
        $table->json('custom')->nullable();
        $table->integer('priority')->default(0);
        $table->boolean('status')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
    DB::table('enumerates')->insert(['enum_key' => 'status_published']);
});

afterEach(function (): void {
    Schema::dropIfExists('field_items');
    Schema::dropIfExists('fields');
    Schema::dropIfExists('enumerates');
});

function fieldPreviewUser(array $abilities): void
{
    $user = new FieldPreviewTestUser();
    $user->allowedAbilities = array_map(static fn (string $ability): string => 'field.' . $ability, $abilities);
    $user->forceFill(['id' => 1, 'name' => 'Form editor', 'email' => 'editor@example.test', 'type' => 0, 'level' => 0]);
    $user->exists = true;
    Sanctum::actingAs($user, ['*']);
}

function fieldPreviewPayload(array $changes = []): array
{
    return array_replace_recursive([
        'id' => 0,
        'name' => 'Biểu mẫu thử',
        'status' => 'status_published',
        'items' => [[
            'id' => 0,
            'name' => 'Họ tên',
            'field_name' => 'full_name',
            'type' => 'text',
            'status' => true,
            'custom' => ['required' => true, 'visibleWhen' => ['field' => 'applicantType', 'value' => 'lay'], 'placeholder' => 'Nhập họ tên', 'section' => 'Thông tin', 'choiceLayout' => 'auto', 'maxFileSizeMb' => 10],
        ]],
    ], $changes);
}

it('renders an unpublished draft without writing fields or items', function (): void {
    fieldPreviewUser(['create']);

    $response = $this->post('/api/v2/admin/field/preview', fieldPreviewPayload());

    $response->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->getContent())->toContain('Nhập họ tên', 'Thông tin', 'Xem trước biểu mẫu');
    expect($response->getContent())->toContain('required')->not->toContain('Họ tên Không bắt buộc');
    expect($response->getContent())->toContain('applicantType')->toContain('lay');
    expect(DB::table('fields')->count())->toBe(0)
        ->and(DB::table('field_items')->count())->toBe(0);
});

it('requires authentication and create permission for a new draft', function (): void {
    $this->postJson('/api/v2/admin/field/preview', fieldPreviewPayload())->assertUnauthorized();
    fieldPreviewUser([]);
    $this->postJson('/api/v2/admin/field/preview', fieldPreviewPayload())->assertForbidden();
});

it('validates responsive counts and previews unsaved layout without updating saved metadata', function (): void {
    fieldPreviewUser(['create', 'update']);
    $invalid = fieldPreviewPayload(['custom' => ['layout' => ['columns' => ['desktop' => 9]]]]);
    $this->post('/api/v2/admin/field/preview', $invalid)->assertStatus(406);
    $invalid = fieldPreviewPayload();
    $invalid['items'][0]['custom']['layout'] = ['span' => ['desktop' => 'calc(99px)']];
    $this->post('/api/v2/admin/field/preview', $invalid)->assertStatus(406);
    $saved = Field::query()->create(['name' => 'Saved layout', 'form_id' => 'saved-layout', 'status' => 'status_published', 'custom' => ['project' => 'ExampleSchool', 'layout' => ['columns' => ['desktop' => 1]]]]);
    $payload = fieldPreviewPayload(['id' => $saved->id, 'custom' => ['project' => 'Other', 'layout' => ['columns' => ['mobile' => 1, 'tablet' => 2, 'desktop' => 3]]]]);
    $this->post('/api/v2/admin/field/preview', $payload)->assertOk()->assertSee('--form-cols-desktop:3', false);
    expect($saved->fresh()->custom)->toBe(['project' => 'ExampleSchool', 'layout' => ['columns' => ['desktop' => 1]]]);
});

it('keeps source configuration endpoints behind form editor permissions', function (): void {
    $sources = app(\Cms\Core\Services\Forms\FormOptionSourceRegistry::class);
    $sources->register('test.forms', ['module' => 'Core', 'public' => true, 'model' => Field::class,
        'value' => 'form_id', 'label' => 'name', 'title' => 'Biểu mẫu thử', 'where' => ['status' => 'status_published']]);
    $this->getJson('/api/v2/admin/field/option-sources')->assertUnauthorized();
    fieldPreviewUser([]);
    $this->getJson('/api/v2/admin/field/option-sources')->assertForbidden();
    $this->getJson('/api/v2/admin/field/option-sources/test.forms/options')->assertForbidden();
    fieldPreviewUser(['create']);
    $this->getJson('/api/v2/admin/field/option-sources')->assertOk()->assertDontSee(Field::class);
    $this->getJson('/api/v2/admin/field/option-sources/test.forms/options')->assertOk()->assertJsonCount(0, 'data.options');
});

it('requires update permission on the real saved form', function (): void {
    $saved = Field::query()->create(['form_id' => 'saved', 'name' => 'Saved', 'status' => 'status_published']);
    fieldPreviewUser(['create']);

    $this->postJson('/api/v2/admin/field/preview', fieldPreviewPayload(['id' => $saved->id]))->assertForbidden();
    $this->postJson('/api/v2/admin/field/preview', fieldPreviewPayload(['id' => 999]))->assertNotFound();
});

it('previews unsaved edits to an existing form without persisting them', function (): void {
    $saved = Field::query()->create(['form_id' => 'stable_form', 'name' => 'Saved', 'status' => 'status_published']);
    fieldPreviewUser(['update']);

    $response = $this->post('/api/v2/admin/field/preview', fieldPreviewPayload([
        'id' => $saved->id,
        'name' => 'Bản nháp mới',
        'items' => [['name' => 'Tên mới', 'custom' => ['placeholder' => 'Nhập tên mới']]],
    ]));

    $response->assertOk();
    expect($response->getContent())->toContain('Tên mới', 'Nhập tên mới', 'stable_form')
        ->and($saved->fresh()->name)->toBe('Saved')
        ->and(DB::table('field_items')->count())->toBe(0);
});

it('validates presentation controls and generates a stable server form id', function (): void {
    fieldPreviewUser(['create', 'update']);
    $invalid = fieldPreviewPayload();
    $invalid['items'][0]['custom']['choiceLayout'] = 'script';
    $invalid['items'][0]['custom']['maxFileSizeMb'] = 20;
    $this->post('/api/v2/admin/field/preview', $invalid)->assertStatus(406);
    $this->post('/api/v2/admin/field', $invalid)->assertStatus(406);

    $payload = fieldPreviewPayload();
    unset($payload['id'], $payload['form_id']);
    $this->post('/api/v2/admin/field', $payload)->assertOk();
    $saved = Field::query()->firstOrFail();
    expect($saved->form_id)->toMatch('/^form_[0-9A-HJKMNP-TV-Z]{26}$/');
});

it('keeps saved keys and rejects item ids from another form', function (): void {
    fieldPreviewUser(['create', 'update']);
    $saved = Field::query()->create(['form_id' => 'stable_form', 'name' => 'Saved', 'status' => 'status_published']);
    $other = Field::query()->create(['form_id' => 'other_form', 'name' => 'Other', 'status' => 'status_published']);
    $itemId = DB::table('field_items')->insertGetId([
        'field_id' => $other->id, 'name' => 'Other', 'field_name' => 'other_field', 'type' => 'text',
    ]);

    $payload = fieldPreviewPayload(['form_id' => 'attacker_form']);
    $payload['items'][0]['id'] = $itemId;
    $this->putJson('/api/v2/admin/field/' . $saved->id, $payload)->assertStatus(422);

    $payload['items'][0]['id'] = 0;
    $this->putJson('/api/v2/admin/field/' . $saved->id, $payload)->assertOk();
    expect($saved->fresh()->form_id)->toBe('stable_form');
});
