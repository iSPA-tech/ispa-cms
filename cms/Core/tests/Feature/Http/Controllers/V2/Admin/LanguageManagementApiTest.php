<?php

namespace Cms\Core\Tests\Feature\Http\Controllers\V2\Admin;

use App\Models\User;
use Cms\Core\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class LanguageManagementApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        Event::fake();
        $this->migrateTables();

        $user = User::query()->create([
            'id' => 1,
            'name' => 'Webmaster',
            'email' => 'webmaster@example.test',
            'password' => 'secret',
        ]);

        $this->actingAs($user, 'sanctum');
        Gate::before(static fn (): bool => true);
    }

    public function test_switching_web_default_moves_default_to_selected_language(): void
    {
        $vietnameseId = $this->createLanguage([
            'name' => 'Vietnamese',
            'path' => 'vi',
            'for_web' => true,
            'default_web' => true,
        ]);
        $englishId = $this->createLanguage([
            'name' => 'English',
            'path' => 'en',
            'for_web' => true,
        ]);

        $response = $this->putJson(
            "/api/v2/admin/language/{$englishId}/switch/default_web",
            ['col' => 'default_web', 'value' => true],
        );

        $response->assertOk()->assertJsonPath('status', 'success');
        $this->assertDatabaseHas('languages', ['id' => $englishId, 'default_web' => true]);
        $this->assertDatabaseHas('languages', ['id' => $vietnameseId, 'default_web' => false]);
        $this->assertSame(1, DB::table('languages')->where('default_web', true)->count());
    }

    public function test_web_default_cannot_be_enabled_when_language_is_not_used_for_web(): void
    {
        $englishId = $this->createLanguage([
            'name' => 'English',
            'path' => 'en',
            'for_web' => false,
        ]);

        $response = $this->putJson(
            "/api/v2/admin/language/{$englishId}/switch/default_web",
            ['col' => 'default_web', 'value' => true],
        );

        $response->assertStatus(400)->assertJsonPath('status', 'error');
        $this->assertDatabaseHas('languages', [
            'id' => $englishId,
            'for_web' => false,
            'default_web' => false,
        ]);
    }

    public function test_disabling_web_usage_also_disables_web_default(): void
    {
        $vietnameseId = $this->createLanguage([
            'name' => 'Vietnamese',
            'path' => 'vi',
            'for_web' => true,
            'default_web' => true,
        ]);

        $response = $this->putJson(
            "/api/v2/admin/language/{$vietnameseId}/switch/for_web",
            ['col' => 'for_web', 'value' => false],
        );

        $response->assertOk()->assertJsonPath('status', 'success');
        $this->assertDatabaseHas('languages', [
            'id' => $vietnameseId,
            'for_web' => false,
            'default_web' => false,
        ]);
    }

    public function test_full_update_keeps_only_one_web_default(): void
    {
        $vietnameseId = $this->createLanguage([
            'name' => 'Vietnamese',
            'path' => 'vi',
            'for_web' => true,
            'default_web' => true,
        ]);
        $englishId = $this->createLanguage([
            'name' => 'English',
            'path' => 'en',
            'for_web' => true,
        ]);

        $response = $this->putJson("/api/v2/admin/language/{$englishId}", [
            'name' => 'English',
            'path' => 'en',
            'for_web' => true,
            'default_web' => true,
        ]);

        $response->assertOk()->assertJsonPath('status', 'success');
        $this->assertDatabaseHas('languages', ['id' => $englishId, 'default_web' => true]);
        $this->assertDatabaseHas('languages', ['id' => $vietnameseId, 'default_web' => false]);
        $this->assertSame(1, DB::table('languages')->where('default_web', true)->count());
    }

    public function test_full_update_clears_default_when_platform_usage_is_disabled(): void
    {
        $englishId = $this->createLanguage([
            'name' => 'English',
            'path' => 'en',
            'for_web' => true,
            'default_web' => true,
        ]);

        $response = $this->putJson("/api/v2/admin/language/{$englishId}", [
            'name' => 'English',
            'path' => 'en',
            'for_web' => false,
            'default_web' => true,
        ]);

        $response->assertOk()->assertJsonPath('status', 'success');
        $this->assertDatabaseHas('languages', [
            'id' => $englishId,
            'for_web' => false,
            'default_web' => false,
        ]);
    }

    protected function migrateTables(): void
    {
        Schema::dropIfExists('languages');
        Schema::dropIfExists('users');

        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('languages', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('path')->unique();
            $table->string('path_alias')->nullable();
            $table->string('currency')->nullable();
            $table->string('code')->nullable();
            $table->string('flag')->nullable();
            $table->string('time_zone')->nullable();
            $table->boolean('for_web')->default(false);
            $table->boolean('for_adm')->default(false);
            $table->boolean('for_app')->default(false);
            $table->boolean('default_web')->default(false);
            $table->boolean('default_adm')->default(false);
            $table->boolean('default_app')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->string('status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function createLanguage(array $overrides = []): int
    {
        return (int) DB::table('languages')->insertGetId(array_merge([
            'name' => 'Language',
            'path' => 'xx',
            'for_web' => false,
            'for_adm' => false,
            'for_app' => false,
            'default_web' => false,
            'default_adm' => false,
            'default_app' => false,
            'is_locked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
