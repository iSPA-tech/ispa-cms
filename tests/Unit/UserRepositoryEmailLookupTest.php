<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Cms\Core\Repositories\UserRepository;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    prepareUserRepositorySchema();
});

afterEach(function () {
    Schema::dropAllTables();
});

test('user repository resolves email input against the email column', function () {
    $userId = DB::table('users')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Client Auth User',
        'username' => 'showroom-owner',
        'email' => 'owner@example.com',
        'password' => 'hashed-secret',
        'type' => 3,
        'level' => 1,
        'status' => 'user_active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user = app(UserRepository::class)->getByEmailOrUsername('owner@example.com');

    expect($user)->not->toBeNull();
    expect($user?->id)->toBe($userId);
    expect($user?->email)->toBe('owner@example.com');
    expect($user?->username)->toBe('showroom-owner');
});

test('user repository finds an email whose domain has no DNS records, without asking DNS', function () {
    // Sign-in used to call checkdnsrr() on the email domain: a domain without MX records (an intranet, `.test`,
    // `.invalid`) was then looked up as a username and never found, and a slow resolver hung the request.
    $userId = DB::table('users')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Intranet Webmaster',
        'username' => 'webmaster',
        'email' => 'owner@intranet.invalid',
        'password' => 'hashed-secret',
        'type' => 0,
        'level' => 1,
        'status' => 'user_active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user = app(UserRepository::class)->getByEmailOrUsername('owner@intranet.invalid');

    expect($user?->id)->toBe($userId);
});

test('user repository falls back to the username column for an @ value that is no email on file', function () {
    $userId = DB::table('users')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Legacy Handle',
        'username' => 'shop@hcm',
        'email' => 'legacy@example.com',
        'password' => 'hashed-secret',
        'type' => 3,
        'level' => 1,
        'status' => 'user_active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user = app(UserRepository::class)->getByEmailOrUsername('shop@hcm');

    expect($user?->id)->toBe($userId);
});

test('user repository still resolves plain usernames against the username column', function () {
    $userId = DB::table('users')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Client Auth User',
        'username' => 'showroom-owner',
        'email' => 'owner@example.com',
        'password' => 'hashed-secret',
        'type' => 3,
        'level' => 1,
        'status' => 'user_active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user = app(UserRepository::class)->getByEmailOrUsername('showroom-owner');

    expect($user)->not->toBeNull();
    expect($user?->id)->toBe($userId);
});

function prepareUserRepositorySchema(): void
{
    Schema::dropAllTables();

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->uuid('uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('username')->nullable()->index();
        $table->string('email')->nullable()->index();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password')->nullable();
        $table->string('mobile')->nullable();
        $table->unsignedTinyInteger('type')->default(3);
        $table->unsignedInteger('level')->default(0);
        $table->unsignedBigInteger('parent_id')->nullable();
        $table->string('status')->nullable();
        $table->rememberToken();
        $table->timestamps();
        $table->softDeletes();
    });
}
