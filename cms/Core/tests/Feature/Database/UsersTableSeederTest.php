<?php

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\User;
use Cms\Core\Database\Seeders\UsersTableSeeder;
use Cms\Core\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * the Core user seeder creates an account only from MASTER_* values. A fresh install without them gets no
 * account with a known password; `cms:install` passes them for the first webmaster.
 */
function usersSeederEnv(array $values): void
{
    foreach (['MASTER_EMAIL', 'MASTER_NAME', 'MASTER_USERNAME', 'MASTER_PASSWORD'] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
    foreach ($values as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
}

beforeEach(function () {
    // users.status references enumerates.enum_key; the module seed creates this row in a real install.
    DB::table('enumerates')->insert(['name' => 'Active', 'enum_key' => 'user_active', 'type' => 'user_status']);
});

afterEach(function () {
    usersSeederEnv([]);
});

it('creates no account when MASTER_EMAIL is not set', function () {
    usersSeederEnv([]);

    (new UsersTableSeeder())->run();

    expect(User::query()->count())->toBe(0);
});

it('creates no account when MASTER_PASSWORD is not set', function () {
    usersSeederEnv(['MASTER_EMAIL' => 'owner@seed.example.test']);

    (new UsersTableSeeder())->run();

    expect(User::query()->count())->toBe(0);
});

it('creates the webmaster from MASTER_EMAIL and MASTER_PASSWORD and derives the missing names', function () {
    usersSeederEnv(['MASTER_EMAIL' => 'owner@seed.example.test', 'MASTER_PASSWORD' => 'Pw-'.Str::random(16)]);

    (new UsersTableSeeder())->run();

    $user = User::query()->where('email', 'owner@seed.example.test')->first();
    expect($user)->not->toBeNull()
        ->and($user->username)->toBe('owner')
        ->and($user->name)->toBe('owner')
        ->and(User::query()->count())->toBe(1);
});

it('links an existing MASTER_EMAIL account to the Webmaster role without a password', function () {
    $role = Role::query()->create(['name' => 'Webmaster']);
    $user = User::factory()->create(['email' => 'owner@seed.example.test']);
    usersSeederEnv(['MASTER_EMAIL' => 'owner@seed.example.test']);

    (new UsersTableSeeder())->run();

    expect($user->roles()->whereKey($role->id)->exists())->toBeTrue()
        ->and(User::query()->count())->toBe(1);
});

it('carries no default account values in its source', function () {
    $source = (string) file_get_contents((new ReflectionClass(UsersTableSeeder::class))->getFileName());

    expect(preg_match("/env\\(\\s*'MASTER_[A-Z]+'\\s*,/", $source))->toBe(0)
        // The copyright header names the product's own address; no other address may appear.
        ->and(preg_match('/@(?!ispa\\.io\\b)[a-z0-9.-]+\\.[a-z]{2,}/i', $source))->toBe(0);
});
