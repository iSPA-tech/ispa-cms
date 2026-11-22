<?php

use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Laravel\Sanctum\Sanctum;

uses(\Cms\Core\Tests\TestCase::class);

class LanguageValidationTestUser extends User
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
    Cache::forget('language_flag_files');

    Schema::dropIfExists('languages');
    Schema::create('languages', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('path', 10);
        $table->string('path_alias', 10)->nullable();
        $table->string('code', 10)->nullable();
        $table->string('currency', 10)->nullable();
        $table->string('flag', 50)->nullable();
        $table->string('time_zone', 50)->nullable();
        $table->string('status')->nullable();
        $table->boolean('is_locked')->default(false);
        foreach (['for_web', 'for_adm', 'for_app', 'default_web', 'default_adm', 'default_app'] as $col) {
            $table->boolean($col)->default(false);
        }
        $table->timestamps();
        $table->softDeletes();
    });
    DB::table('languages')->insert(['name' => 'Tiếng Việt', 'path' => 'vi', 'deleted_at' => now()]);
});

afterEach(function (): void {
    Schema::dropIfExists('languages');
});

function languageUser(array $abilities): void
{
    $user = new LanguageValidationTestUser();
    $user->allowedAbilities = array_map(static fn (string $ability): string => 'language.' . $ability, $abilities);
    $user->forceFill(['id' => 1, 'name' => 'Admin', 'email' => 'admin@example.test', 'type' => 0, 'level' => 0]);
    $user->exists = true;
    Sanctum::actingAs($user, ['*']);
}

it('rejects a duplicate path, even when the existing language is soft-deleted', function (): void {
    languageUser(['create']);

    $this->postJson('/api/v2/admin/language', ['name' => 'Another', 'path' => 'vi'])->assertStatus(406);
    expect(DB::table('languages')->count())->toBe(1);
});

it('rejects an unknown time zone and a malformed path', function (): void {
    languageUser(['create']);

    $this->postJson('/api/v2/admin/language', ['name' => 'English', 'path' => 'en', 'time_zone' => 'Mars/Base'])->assertStatus(406);
    $this->postJson('/api/v2/admin/language', ['name' => 'English', 'path' => 'EN us'])->assertStatus(406);
    expect(DB::table('languages')->count())->toBe(1);
});

it('lists the flag files with their public url', function (): void {
    languageUser([]);

    $response = $this->getJson('/api/v2/admin/language/flags')->assertOk();
    $files = collect($response->json('data'));

    expect($files)->not->toBeEmpty()
        ->and($files->firstWhere('file', 'vn.svg'))->toBe(['file' => 'vn.svg', 'url' => '/images/flags/4x3/vn.svg']);
});

it('passes validation for a well-formed language payload', function (): void {
    languageUser(['create']);

    // The in-memory schema has no audit table, so only the validation outcome (not 406) is asserted.
    $response = $this->postJson('/api/v2/admin/language', [
        'name' => 'English', 'path' => 'en', 'path_alias' => 'en-US', 'code' => 'EN',
        'time_zone' => 'UTC', 'flag' => 'gb.svg', 'is_locked' => false,
    ]);

    expect($response->getStatusCode())->not->toBe(406);
});

it('normalises a status_ prefixed status to the bare key and stores it', function (): void {
    languageUser(['create']);
    Schema::dropIfExists('audit_logs');
    Schema::create('audit_logs', static function (Blueprint $table): void {
        $table->id();
        foreach (['action', 'changed', 'target_type', 'target_id', 'ip_address', 'agent', 'url', 'options', 'user_id'] as $col) {
            $table->text($col)->nullable();
        }
        $table->timestamps();
    });

    $response = $this->postJson('/api/v2/admin/language', ['name' => 'English', 'path' => 'en', 'status' => 'status_published']);

    expect($response->getStatusCode())->toBe(201);
    expect(DB::table('languages')->where('path', 'en')->value('status'))->toBe('published');
});

it('rejects an unknown status', function (): void {
    languageUser(['create']);

    $this->postJson('/api/v2/admin/language', ['name' => 'English', 'path' => 'en', 'status' => 'bogus'])->assertStatus(406);
    expect(DB::table('languages')->count())->toBe(1);
});

it('returns the stored status string on edit instead of the unresolved relation', function (): void {
    languageUser(['update']);
    DB::table('languages')->insert(['name' => 'English', 'path' => 'en', 'status' => 'published']);
    $id = DB::table('languages')->where('path', 'en')->value('id');

    $response = $this->getJson('/api/v2/admin/language/' . $id . '/edit');

    expect($response->getStatusCode())->toBe(200);
    expect($response->json('data.status'))->toBe('published');
});
