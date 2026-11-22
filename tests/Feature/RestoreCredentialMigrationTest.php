<?php

use App\Constants\UserType;
use App\Enums\UserStatusEnum;
use App\Models\User;
use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeRestoreCredentialMigrationUser(): User
{
    DB::table(CoreTableConstant::ENUMERATES)->updateOrInsert(
        ['enum_key' => UserStatusEnum::USER_ACTIVE->value],
        ['name' => 'Active', 'status' => true],
    );

    return User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'Existing Passkey User',
        'username' => 'existing-passkey-' . Str::lower(Str::random(8)),
        'email' => Str::lower(Str::random(8)) . '@example.test',
        'password' => bcrypt(Str::random(32)), // any value: users.password is NOT NULL without the Account module
        'type' => UserType::CLIENT,
        'level' => 0,
        'status' => UserStatusEnum::USER_ACTIVE->value,
        'email_verified_at' => now(),
    ]);
}

test('existing passkey rows default to passkey kind', function (): void {
    $user = makeRestoreCredentialMigrationUser();

    Schema::dropIfExists('passkeys');
    Schema::create('passkeys', static function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('name');
        $table->string('credential_id')->unique();
        $table->json('credential');
        $table->timestamp('last_used_at')->nullable();
        $table->timestamps();
        $table->index('user_id');
    });

    DB::table('passkeys')->insert([
        'user_id' => $user->getKey(),
        'name' => 'Existing passkey',
        'credential_id' => Str::random(64),
        'credential' => json_encode(['counter' => 7], JSON_THROW_ON_ERROR),
        'last_used_at' => now()->subDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $legacy = (array) DB::table('passkeys')->first();
    $migration = require database_path('migrations/2026_08_27_000100_add_kind_to_passkeys_table.php');
    $migration->up();

    expect(Schema::hasColumn('passkeys', 'kind'))->toBeTrue()
        ->and(Schema::hasIndex('passkeys', ['user_id', 'kind']))->toBeTrue()
        ->and(DB::table('passkeys')->where('id', $legacy['id'])->value('kind'))->toBe('passkey');

    foreach (['user_id', 'name', 'credential_id', 'credential', 'last_used_at', 'created_at', 'updated_at'] as $field) {
        expect((string) DB::table('passkeys')->where('id', $legacy['id'])->value($field))
            ->toBe((string) $legacy[$field]);
    }

    DB::table('passkeys')->insert([
        'user_id' => $user->getKey(),
        'name' => 'Post-upgrade passkey',
        'credential_id' => Str::random(64),
        'credential' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('passkeys')->where('name', 'Post-upgrade passkey')->value('kind'))->toBe('passkey');

    $migration->down();

    expect(Schema::hasColumn('passkeys', 'kind'))->toBeFalse()
        ->and(Schema::hasIndex('passkeys', ['user_id', 'kind']))->toBeFalse()
        ->and(Schema::hasIndex('passkeys', ['user_id']))->toBeTrue()
        ->and(DB::table('passkeys')->count())->toBe(2);

    foreach (['user_id', 'name', 'credential_id', 'credential', 'last_used_at', 'created_at', 'updated_at'] as $field) {
        expect((string) DB::table('passkeys')->where('id', $legacy['id'])->value($field))
            ->toBe((string) $legacy[$field]);
    }

    // Restore the current schema so the test database teardown sees the migrated shape.
    $migration->up();
});
