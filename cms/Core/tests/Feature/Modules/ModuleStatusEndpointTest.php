<?php

declare(strict_types=1);

namespace Cms\Core\Tests\Feature\Modules;

use App\Constants\UserType;
use App\Jobs\Modules\RefreshRuntimeCachesJob;
use App\Models\User;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\AuditLog;
use Cms\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Nwidart\Modules\Facades\Module;

/**
 * PUT /api/v2/admin/utilities/modules/{module}/status 
 * only a Webmaster may change a module status, and
 * an enable goes through ModuleStatusService (audit row + queued cache
 * refresh) while the response keeps the same JSON keys.
 *
 * The Module facade and Artisan are mocked: the real modules_statuses.json
 * is never written and no activation task runs.
 */
class ModuleStatusEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const MODULE = 'UnguardedFixture';

    /** Keys ModuleController::transformModule returned. */
    private const MODULE_KEYS = [
        'name', 'alias', 'description', 'priority', 'path', 'enabled', 'version', 'requires', 'providers',
        'can_disable', 'switchable', 'missing_dependencies', 'blocking_dependents',
        'has_missing_dependencies', 'has_blocking_dependents', 'revoked', 'revoked_reason', 'handover', 'hub_configured',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceScheme('https');
    }

    private function adminUser(string $email): User
    {
        $user = User::query()->create([
            'name' => 'Module Admin',
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
        $user->forceFill(['type' => UserType::ADMIN])->save();

        return $user;
    }

    private function webmaster(): User
    {
        $user = $this->adminUser('module-webmaster@test.local');
        $roleId = (int) DB::table(CoreTableConstant::ROLES)->insertGetId([
            'name' => 'Webmaster '.uniqid(),
            'custom' => json_encode(['level' => 0]),
        ]);
        DB::table(CoreTableConstant::ROLE_USER)->insert(['role_id' => $roleId, 'user_id' => $user->id]);

        return $user->fresh();
    }

    private function nonWebmaster(): User
    {
        // User id 1 is always a Webmaster; make sure this user is not id 1.
        $this->adminUser('module-filler@test.local');
        $user = $this->adminUser('module-editor@test.local');
        $this->assertFalse($user->isWebmaster());

        return $user;
    }

    public function test_an_admin_who_is_not_a_webmaster_gets_403(): void
    {
        Queue::fake();
        Module::shouldReceive('enable')->never();
        Module::shouldReceive('disable')->never();

        Sanctum::actingAs($this->nonWebmaster());

        $this->putJson('/api/v2/admin/utilities/modules/'.self::MODULE.'/status', ['enabled' => true])
            ->assertStatus(403);
        $this->putJson('/api/v2/admin/utilities/modules/'.self::MODULE.'/status', ['enabled' => false])
            ->assertStatus(403);

        Queue::assertNothingPushed();
    }

    public function test_the_module_list_stays_readable_for_a_non_webmaster(): void
    {
        Sanctum::actingAs($this->nonWebmaster());

        $this->getJson('/api/v2/admin/utilities/modules')->assertOk();
    }

    public function test_the_module_list_shows_the_module_json_version_not_the_stale_composer_version(): void
    {
        // composer.json versions stayed at 2026.03.0 while module.json (the version the installer, the hub and
        // the runtime precedence rule compare) moved on, so the Modules page showed the wrong version.
        Sanctum::actingAs($this->nonWebmaster());
        $expected = json_decode((string) file_get_contents(base_path('cms/Core/module.json')), true)['version'];

        $rows = collect($this->getJson('/api/v2/admin/utilities/modules')->assertOk()->json('data'));
        $core = $rows->firstWhere('name', 'Core');

        $this->assertNotNull($core, 'Core is listed');
        $this->assertSame($expected, $core['version']);
    }

    public function test_each_module_row_says_whether_it_is_listed_in_runtime_modules_json(): void
    {
        // the Modules page words the ignored online copy of a listed module differently.
        Sanctum::actingAs($this->nonWebmaster());

        $rows = collect($this->getJson('/api/v2/admin/utilities/modules')->assertOk()->json('data'));

        $this->assertNotEmpty($rows);
        $this->assertTrue($rows->every(static fn (array $row): bool => array_key_exists('handover', $row) && is_bool($row['handover'])));
        $this->assertFalse($rows->firstWhere('name', 'Core')['handover']);
    }

    public function test_a_webmaster_enable_goes_through_the_service_and_keeps_the_response_keys(): void
    {
        Queue::fake();

        // The core module's own composer.json, read only.
        $composer = \Nwidart\Modules\Json::make(base_path('cms/Core/composer.json'));

        $target = Mockery::mock(\Nwidart\Modules\Module::class);
        $target->shouldReceive('getName')->andReturn(self::MODULE);
        $target->shouldReceive('getLowerName')->andReturn(strtolower(self::MODULE));
        $target->shouldReceive('get')->andReturnUsing(fn (string $key, $default = null) => $default);
        $target->shouldReceive('getPath')->andReturn('/tmp/'.self::MODULE);
        $target->shouldReceive('isEnabled')->andReturn(true);
        $target->shouldReceive('json')->with('composer.json')->andReturn($composer);

        Module::shouldReceive('allEnabled')->andReturn([]);
        Module::shouldReceive('all')->andReturn([]);
        Module::shouldReceive('find')->andReturn($target);
        Module::shouldReceive('enable')->once()->with(self::MODULE);

        // runActivationTasks keeps its in-process Artisan calls; none runs for real.
        Artisan::shouldReceive('call')->times(3)->andReturn(0);

        Sanctum::actingAs($this->webmaster());

        $response = $this->putJson('/api/v2/admin/utilities/modules/'.self::MODULE.'/status', ['enabled' => true]);

        $response->assertOk()->assertJsonStructure(['data' => self::MODULE_KEYS]);
        $this->assertSame(self::MODULE_KEYS, array_keys($response->json('data')));

        Queue::assertPushed(RefreshRuntimeCachesJob::class, 1);

        $row = AuditLog::query()->where('target_type', 'module_status')->sole();
        $this->assertSame('action.module.enabled', $row->action);
        $this->assertSame(self::MODULE, $row->changed['module']);
    }
}
