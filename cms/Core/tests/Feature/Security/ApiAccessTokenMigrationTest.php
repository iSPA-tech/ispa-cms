<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

/**
 * the audience and device columns of personal_access_tokens, and the custom token model registered with
 * Sanctum. Existing tokens (every new column null) must keep authenticating, the migration must be idempotent, and
 * down() must remove only what up() added.
 */

use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use Cms\Core\Models\ApiAccessToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

const P10_NEW_TOKEN_COLUMNS = ['uuid', 'audience', 'ip_address', 'user_agent', 'issued_via'];

/** Load the migration under test. */
function p10TokenColumnsMigration(): object
{
    return require base_path('cms/Core/database/migrations/2026_11_13_000001_add_audience_and_device_columns_to_personal_access_tokens.php');
}

/** Create a plain account for the token tests. */
function p10MigrationUser(): User
{
    return User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'Token Owner',
        'username' => 'token-owner-' . Str::lower(Str::random(6)),
        'email' => 'token-owner-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => 0,
        'level' => 0,
        'status' => null,
    ]);
}

/** Register a tiny Sanctum-protected route that reports which token class carried the request. */
function p10RegisterWhoAmIRoute(): void
{
    Route::middleware('auth:sanctum')->get('/__p10/whoami', static function (Request $request) {
        $token = $request->user()->currentAccessToken();

        return response()->json(['id' => $request->user()->id, 'token_class' => $token::class]);
    });
    app('router')->getRoutes()->refreshNameLookups();
}

beforeEach(function () {
    $this->withoutMiddleware([HttpsProtocol::class, Localization::class, ApiLocalization::class, ThrottleRequests::class]);
});

test('the migration adds the five nullable columns with a unique uuid and an indexed audience', function () {
    expect(Schema::hasColumns('personal_access_tokens', P10_NEW_TOKEN_COLUMNS))->toBeTrue();

    $columns = collect(Schema::getColumns('personal_access_tokens'))->keyBy('name');
    foreach (P10_NEW_TOKEN_COLUMNS as $column) {
        expect($columns[$column]['nullable'])->toBeTrue("{$column} must be nullable")
            ->and($columns[$column]['default'])->toBeNull("{$column} must have no default");
    }

    $indexes = collect(Schema::getIndexes('personal_access_tokens'));
    expect($indexes->first(fn (array $index) => $index['columns'] === ['uuid'] && $index['unique']))->not->toBeNull()
        ->and($indexes->first(fn (array $index) => $index['columns'] === ['audience']))->not->toBeNull();
});

test('the migration is idempotent and down removes only the new columns', function () {
    $migration = p10TokenColumnsMigration();
    $migration->up();
    $migration->up();
    expect(Schema::hasColumns('personal_access_tokens', P10_NEW_TOKEN_COLUMNS))->toBeTrue();

    $migration->down();
    foreach (P10_NEW_TOKEN_COLUMNS as $column) {
        expect(Schema::hasColumn('personal_access_tokens', $column))->toBeFalse("{$column} must be dropped");
    }
    expect(Schema::hasColumns('personal_access_tokens', [
        'id', 'tokenable_type', 'tokenable_id', 'name', 'token', 'abilities', 'last_used_at', 'expires_at', 'created_at', 'updated_at',
    ]))->toBeTrue();

    $migration->down(); // a second down is harmless
    $migration->up();
    expect(Schema::hasColumns('personal_access_tokens', P10_NEW_TOKEN_COLUMNS))->toBeTrue();
});

test('the migration does nothing when the table does not exist', function () {
    Schema::drop('personal_access_tokens');
    $migration = p10TokenColumnsMigration();
    $migration->up();
    $migration->down();
    expect(Schema::hasTable('personal_access_tokens'))->toBeFalse();
});

test('Sanctum uses the custom token model', function () {
    expect(Sanctum::personalAccessTokenModel())->toBe(ApiAccessToken::class)
        ->and(is_subclass_of(ApiAccessToken::class, \Laravel\Sanctum\PersonalAccessToken::class))->toBeTrue();
});

test('an existing token with every new column null still authenticates and is not backfilled', function () {
    $user = p10MigrationUser();
    $plain = Str::random(40);
    $id = DB::table('personal_access_tokens')->insertGetId([
        'tokenable_type' => $user->getMorphClass(),
        'tokenable_id' => $user->id,
        'name' => 'old-device',
        'token' => hash('sha256', $plain),
        'abilities' => json_encode(['*']),
        'created_at' => now()->subYear(),
        'updated_at' => now()->subYear(),
    ]);
    p10RegisterWhoAmIRoute();

    $this->withHeader('Authorization', 'Bearer ' . $id . '|' . $plain)
        ->getJson('/__p10/whoami')
        ->assertOk()
        ->assertJson(['id' => $user->id, 'token_class' => ApiAccessToken::class]);

    $row = DB::table('personal_access_tokens')->where('id', $id)->first();
    expect($row->last_used_at)->not->toBeNull();
    foreach (P10_NEW_TOKEN_COLUMNS as $column) {
        expect($row->{$column})->toBeNull("{$column} must stay null on an old token");
    }
});

test('the unchanged createToken path gets a uuid and no audience', function () {
    $user = p10MigrationUser();
    $new = $user->createToken('legacy-call-site');

    expect($new->accessToken)->toBeInstanceOf(ApiAccessToken::class);
    $row = DB::table('personal_access_tokens')->where('id', $new->accessToken->getKey())->first();
    expect(Str::isUuid((string) $row->uuid))->toBeTrue()
        ->and($row->audience)->toBeNull()
        ->and($row->ip_address)->toBeNull()
        ->and($row->user_agent)->toBeNull()
        ->and($row->issued_via)->toBeNull();

    p10RegisterWhoAmIRoute();
    $this->withHeader('Authorization', 'Bearer ' . $new->plainTextToken)->getJson('/__p10/whoami')->assertOk();
});

test('createToken keeps working on a table that does not have the new columns yet', function () {
    p10TokenColumnsMigration()->down();
    $user = p10MigrationUser();

    $new = $user->createToken('before-migration');

    expect(DB::table('personal_access_tokens')->where('id', $new->accessToken->getKey())->exists())->toBeTrue();
    p10RegisterWhoAmIRoute();
    $this->withHeader('Authorization', 'Bearer ' . $new->plainTextToken)->getJson('/__p10/whoami')->assertOk();
});
