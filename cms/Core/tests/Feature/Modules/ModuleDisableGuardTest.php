<?php

declare(strict_types=1);

namespace Cms\Core\Tests\Feature\Modules;

use App\Constants\UserType;
use App\Contracts\Modules\ModuleDisableGuard;
use App\Models\User;
use App\Support\Modules\ModuleDisableGuardRegistry;
use Cms\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Nwidart\Modules\Facades\Module;

/**
 * Proves the Task 7 generic module-disable-guard infrastructure fixed by
 * docs/architecture/adr-widget-module-boundary.md (decisions 5-8):
 *
 * - ModuleDisableGuardRegistry distinguishes "no guard registered" (current
 *   behavior preserved) from "a guard is registered but currently permits"
 *   from "a guard blocks";
 * - both the admin ModuleController::updateStatus endpoint and the
 *   sanctioned `cms:module-disable` CLI share the same
 *   ManagedModuleDisabler (guard registry + dependent-module predicate +
 *   lifecycle barrier as one atomic transition);
 * - the pre-existing dependent-module predicate stays ADDITIVE on both
 *   paths (ADR decision 5), for guarded and guard-less modules alike;
 * - a blocked/guarded disable never reaches ModuleFacade::disable() —
 *   every scenario below that could mutate the real module status uses
 *   either the (always-blocked-by-maintenance) real "Widget" module, which
 *   never reaches disable(), or a fully mocked Module facade.
 */
class ModuleDisableGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Name of a module no provider registers a disable guard for. The
     * real Widget module registers its own guard whenever it is enabled,
     * so every "no guard registered" case uses this name instead (the
     * Module facade is mocked wherever the module must be resolved).
     */
    private const UNGUARDED = 'UnguardedFixture';

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceScheme('https');
    }

    // -----------------------------------------------------------------
    // ModuleDisableGuardRegistry unit behavior
    // -----------------------------------------------------------------

    public function test_registry_reports_no_guards_for_an_unregistered_module(): void
    {
        $registry = app(ModuleDisableGuardRegistry::class);

        $this->assertFalse($registry->hasGuardsFor(self::UNGUARDED));
        $this->assertSame([], $registry->evaluate(self::UNGUARDED));
    }

    /**
     * The real Widget module registers its own disable guard while it is
     * enabled; this keeps that production registration covered.
     */
    public function test_registry_has_the_real_widget_guard_registered_by_the_widget_provider(): void
    {
        if (! class_exists(\Cms\Widget\Operations\WidgetModuleDisableGuard::class)
            || ! \Nwidart\Modules\Facades\Module::find('Widget')?->isEnabled()) {
            $this->markTestSkipped('Widget module is not enabled in this checkout.');
        }

        $registry = app(ModuleDisableGuardRegistry::class);

        $this->assertTrue($registry->hasGuardsFor('Widget'));
    }

    public function test_registry_distinguishes_a_permitting_guard_from_no_guard(): void
    {
        $registry = app(ModuleDisableGuardRegistry::class);
        $registry->register($this->fakeGuard('Widget', null));

        $this->assertTrue($registry->hasGuardsFor('Widget'));
        $this->assertSame([], $registry->evaluate('Widget'));
    }

    public function test_registry_collects_blocking_reasons_from_registered_guards(): void
    {
        $registry = app(ModuleDisableGuardRegistry::class);
        $registry->register($this->fakeGuard('Widget', 'WIDGET_MODULE_IN_USE'));
        $registry->register($this->fakeGuard('Widget', null));

        $this->assertSame(['WIDGET_MODULE_IN_USE'], $registry->evaluate('Widget'));
    }

    public function test_registry_is_case_insensitive_and_scoped_to_the_named_module(): void
    {
        $registry = app(ModuleDisableGuardRegistry::class);
        $registry->register($this->fakeGuard('widget', 'WIDGET_MODULE_IN_USE'));

        $this->assertTrue($registry->hasGuardsFor('Widget'));
        $this->assertSame(['WIDGET_MODULE_IN_USE'], $registry->evaluate('Widget'));
        $this->assertFalse($registry->hasGuardsFor('Education'));
    }

    /**
     * Nwidart's LaravelModulesServiceProvider registers an
     * `$app->resolving(Migrator::class, ...)` callback that calls
     * `Module::allEnabled()` the first time the migrator is resolved in a
     * given application instance (e.g. during Artisan bootstrap or an HTTP
     * kernel request). Fully mocking the Module facade must account for
     * this framework-internal call too, or it fails with an unexpected
     * "no expectations were specified" Mockery error unrelated to the
     * behavior under test.
     */
    private function stubModuleFacadeFrameworkNoise(): void
    {
        Module::shouldReceive('allEnabled')->andReturn([]);
    }

    private function fakeGuard(string $moduleName, ?string $reason): ModuleDisableGuard
    {
        return new class ($moduleName, $reason) implements ModuleDisableGuard {
            public function __construct(private readonly string $moduleName, private readonly ?string $reason)
            {
            }

            public function moduleName(): string
            {
                return $this->moduleName;
            }

            public function evaluate(): ?string
            {
                return $this->reason;
            }
        };
    }

    // -----------------------------------------------------------------
    // cms:module-disable CLI
    // -----------------------------------------------------------------

    public function test_cli_fails_for_an_unknown_module(): void
    {
        $exitCode = Artisan::call('cms:module-disable', ['module' => 'NonExistentModuleXyz']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Module not found', Artisan::output());
    }

    public function test_cli_refuses_to_disable_core(): void
    {
        $exitCode = Artisan::call('cms:module-disable', ['module' => 'Core']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Core module can never be disabled', Artisan::output());
    }

    public function test_cli_preserves_current_behavior_when_no_guard_is_registered(): void
    {
        $moduleDouble = Mockery::mock(\Nwidart\Modules\Module::class);
        $this->stubModuleFacadeFrameworkNoise();
        Module::shouldReceive('find')->with(self::UNGUARDED)->andReturn($moduleDouble);
        Module::shouldReceive('all')->andReturn([]);
        Module::shouldReceive('disable')->once()->with(self::UNGUARDED);

        $exitCode = Artisan::call('cms:module-disable', ['module' => self::UNGUARDED]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Module disabled', Artisan::output());
    }

    public function test_cli_blocks_a_module_with_active_dependents_when_no_guard_is_registered(): void
    {
        $target = Mockery::mock(\Nwidart\Modules\Module::class);

        $dependent = Mockery::mock(\Nwidart\Modules\Module::class);
        $dependent->shouldReceive('isEnabled')->andReturn(true);
        $dependent->shouldReceive('get')->with('requires', [])->andReturn([self::UNGUARDED]);
        $dependent->shouldReceive('getName')->andReturn('DependentModule');

        $this->stubModuleFacadeFrameworkNoise();
        Module::shouldReceive('find')->with(self::UNGUARDED)->andReturn($target);
        Module::shouldReceive('all')->andReturn([$dependent]);
        Module::shouldReceive('disable')->never();

        $exitCode = Artisan::call('cms:module-disable', ['module' => self::UNGUARDED]);

        // Artisan::output() clears its buffer on fetch — read it once.
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('MODULE_HAS_ACTIVE_DEPENDENTS', $output);
        $this->assertStringContainsString('DependentModule', $output);
    }

    public function test_cli_blocks_a_guarded_module_without_maintenance_confirmation(): void
    {
        // Real, unmocked "Widget" module: since the barrier gates before the
        // guard callback ever runs, ModuleFacade::disable() is never
        // reached, so this is safe against the real modules_statuses.json.
        app(ModuleDisableGuardRegistry::class)->register($this->fakeGuard('Widget', 'WIDGET_MODULE_IN_USE'));

        $exitCode = Artisan::call('cms:module-disable', ['module' => 'Widget']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('MODULE_MAINTENANCE_REQUIRED', Artisan::output());
    }

    public function test_cli_blocks_a_guarded_module_that_denies_even_with_maintenance_confirmed(): void
    {
        app(ModuleDisableGuardRegistry::class)->register($this->fakeGuard('Widget', 'WIDGET_MODULE_IN_USE'));

        $exitCode = Artisan::call('cms:module-disable', [
            'module' => 'Widget',
            '--force-maintenance-confirmed' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('WIDGET_MODULE_IN_USE', Artisan::output());
    }

    public function test_cli_disables_a_guarded_module_that_permits_with_maintenance_confirmed(): void
    {
        app(ModuleDisableGuardRegistry::class)->register($this->fakeGuard('Widget', null));

        $moduleDouble = Mockery::mock(\Nwidart\Modules\Module::class);
        $this->stubModuleFacadeFrameworkNoise();
        Module::shouldReceive('find')->with('Widget')->andReturn($moduleDouble);
        Module::shouldReceive('all')->andReturn([]);
        Module::shouldReceive('disable')->once()->with('Widget');

        $exitCode = Artisan::call('cms:module-disable', [
            'module' => 'Widget',
            '--force-maintenance-confirmed' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Module disabled', Artisan::output());
    }

    public function test_cli_blocks_a_guarded_module_with_active_dependents_even_when_guards_permit(): void
    {
        // ADR decision 5: the retained-reference guard registry is ADDITIVE
        // to the pre-existing dependent-module predicate — a permitting
        // guard must not skip the dependents check inside the barrier.
        app(ModuleDisableGuardRegistry::class)->register($this->fakeGuard('Widget', null));

        $target = Mockery::mock(\Nwidart\Modules\Module::class);

        $dependent = Mockery::mock(\Nwidart\Modules\Module::class);
        $dependent->shouldReceive('isEnabled')->andReturn(true);
        $dependent->shouldReceive('get')->with('requires', [])->andReturn(['widget']);
        $dependent->shouldReceive('getName')->andReturn('DependentModule');

        $this->stubModuleFacadeFrameworkNoise();
        Module::shouldReceive('find')->with('Widget')->andReturn($target);
        Module::shouldReceive('all')->andReturn([$dependent]);
        Module::shouldReceive('disable')->never();

        $exitCode = Artisan::call('cms:module-disable', [
            'module' => 'Widget',
            '--force-maintenance-confirmed' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('MODULE_HAS_ACTIVE_DEPENDENTS', Artisan::output());
    }

    // -----------------------------------------------------------------
    // Admin ModuleController::updateStatus integration
    // -----------------------------------------------------------------

    private function adminUser(): User
    {
        $user = User::query()->create([
            'name' => 'Module Admin',
            'email' => 'module-admin@test.local',
            'password' => Hash::make('password'),
        ]);
        $user->forceFill(['type' => UserType::ADMIN])->save();

        return $user;
    }

    public function test_admin_disable_preserves_existing_core_protection_when_no_guard_is_registered(): void
    {
        Sanctum::actingAs($this->adminUser());

        $response = $this->putJson('/api/v2/admin/utilities/modules/Core/status', ['enabled' => false]);

        $response->assertStatus(400)->assertJsonPath('status', 'error');
    }

    public function test_admin_disable_returns_a_typed_409_conflict_for_a_guarded_module(): void
    {
        app(ModuleDisableGuardRegistry::class)->register($this->fakeGuard('Widget', 'WIDGET_MODULE_IN_USE'));

        Sanctum::actingAs($this->adminUser());

        // Real, unmocked "Widget" module: the barrier gates before the
        // guard/disable callback runs, so this never mutates the real
        // modules_statuses.json.
        $response = $this->putJson('/api/v2/admin/utilities/modules/Widget/status', ['enabled' => false]);

        $response->assertStatus(409)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('error_code', 'MODULE_MAINTENANCE_REQUIRED');
    }

    public function test_admin_disable_keeps_blocking_a_module_with_active_dependents_when_no_guard_is_registered(): void
    {
        // Characterizes the preserved legacy no-guard path: active
        // dependents still answer the legacy HTTP 400 {status: error}
        // envelope, and the module is never disabled.
        $target = Mockery::mock(\Nwidart\Modules\Module::class);
        $target->shouldReceive('getName')->andReturn(self::UNGUARDED);

        $dependent = Mockery::mock(\Nwidart\Modules\Module::class);
        $dependent->shouldReceive('isEnabled')->andReturn(true);
        $dependent->shouldReceive('get')->with('requires', [])->andReturn([self::UNGUARDED]);
        $dependent->shouldReceive('getName')->andReturn('DependentModule');

        $this->stubModuleFacadeFrameworkNoise();
        Module::shouldReceive('find')->andReturn($target);
        Module::shouldReceive('all')->andReturn([$dependent]);
        Module::shouldReceive('disable')->never();

        Sanctum::actingAs($this->adminUser());

        $response = $this->putJson('/api/v2/admin/utilities/modules/'.self::UNGUARDED.'/status', ['enabled' => false]);

        $response->assertStatus(400)->assertJsonPath('status', 'error');
    }

    public function test_admin_disable_stays_refused_even_when_down_for_maintenance_with_a_bypass_over_http(): void
    {
        // Regression for the barrier's console gate: `artisan down
        // --secret=...` issues a bypass cookie that lets a live HTTP
        // request through the maintenance middleware while the application
        // is down. Even then, a guarded disable over HTTP must answer the
        // typed maintenance-required conflict and never reach disable().
        $isolatedStoragePath = sys_get_temp_dir() . '/ispa-disable-guard-' . uniqid();
        File::ensureDirectoryExists($isolatedStoragePath . '/framework/views');
        File::ensureDirectoryExists($isolatedStoragePath . '/logs');
        $this->app->useStoragePath($isolatedStoragePath);

        $consoleFlag = new \ReflectionProperty(\Illuminate\Foundation\Application::class, 'isRunningInConsole');

        try {
            File::put(
                storage_path('framework/down'),
                json_encode(['time' => time()], JSON_THROW_ON_ERROR)
            );
            $this->assertTrue($this->app->isDownForMaintenance());

            // Simulate the bypass cookie's effect: the request passes the
            // maintenance middleware and reaches the controller. The HTTP
            // kernel registers the app-local subclass, not the framework
            // base class, so disable that exact class.
            $this->withoutMiddleware(\App\Http\Middleware\PreventRequestsDuringMaintenance::class);

            // The guard permits — only the barrier's console gate may block.
            app(ModuleDisableGuardRegistry::class)->register($this->fakeGuard('Widget', null));

            $target = Mockery::mock(\Nwidart\Modules\Module::class);
            $target->shouldReceive('getName')->andReturn('Widget');

            $this->stubModuleFacadeFrameworkNoise();
            Module::shouldReceive('find')->andReturn($target);
            Module::shouldReceive('all')->andReturn([]);
            Module::shouldReceive('disable')->never();

            Sanctum::actingAs($this->adminUser());

            // Feature tests run under the cli SAPI, so runningInConsole()
            // is true by default; flip the cached flag so the real barrier
            // sees this request exactly the way a php-fpm worker would.
            $consoleFlag->setValue($this->app, false);

            $response = $this->putJson('/api/v2/admin/utilities/modules/Widget/status', ['enabled' => false]);

            $response->assertStatus(409)
                ->assertJsonPath('status', 'error')
                ->assertJsonPath('error_code', 'MODULE_MAINTENANCE_REQUIRED');
        } finally {
            $consoleFlag->setValue($this->app, true);
            File::deleteDirectory($isolatedStoragePath);
        }
    }

    public function test_admin_disable_normalizes_an_unexpected_throwable_to_an_opaque_correlation_id(): void
    {
        $moduleDouble = Mockery::mock(\Nwidart\Modules\Module::class);
        $moduleDouble->shouldReceive('getName')->andReturn(self::UNGUARDED);

        $this->stubModuleFacadeFrameworkNoise();
        Module::shouldReceive('find')->andReturn($moduleDouble);
        Module::shouldReceive('all')->andReturn([]);
        Module::shouldReceive('disable')
            ->once()
            ->with(self::UNGUARDED)
            ->andThrow(new \RuntimeException('SQLSTATE[42S02]: sensitive internal detail'));

        Sanctum::actingAs($this->adminUser());

        $response = $this->putJson('/api/v2/admin/utilities/modules/'.self::UNGUARDED.'/status', ['enabled' => false]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('error_code', 'MODULE_OPERATION_FAILED');

        $message = (string) $response->json('message');
        $this->assertStringNotContainsString('SQLSTATE', $message);
        $this->assertStringNotContainsString('sensitive internal detail', $message);
        $this->assertMatchesRegularExpression(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            $message,
            'The opaque response must still carry a correlation ID.'
        );
    }
}
