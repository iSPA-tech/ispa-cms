<?php

declare(strict_types=1);

namespace Cms\Core\Tests\Feature\Modules;

use App\Contracts\Modules\ModuleLifecycleBarrier;
use App\Support\Modules\MaintenanceOnlyModuleLifecycleBarrier;
use Cms\Core\Tests\TestCase;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\File;
use Mockery;

/**
 * Proves the Task 7 Core/root ModuleLifecycleBarrier default fixed by
 * docs/architecture/adr-widget-module-boundary.md (decision 7): with no
 * cross-process fencing/lease adapter proven in this repository (Task 5a
 * evidence, gap G-013), the bound MaintenanceOnlyModuleLifecycleBarrier
 * NEVER runs a guarded transition unless the caller is a console process
 * AND the application is genuinely down for maintenance (or the operator
 * explicitly confirms a drained maintenance window — the sanctioned CLI's
 * --force-maintenance-confirmed flag). An HTTP-context caller is refused
 * unconditionally, BEFORE the maintenance check, because the
 * `artisan down --secret` bypass cookie can let a live HTTP request
 * through the maintenance middleware while the application is down. The
 * barrier never substitutes an in-process mutex for real fencing proof.
 */
class ModuleLifecycleBarrierTest extends TestCase
{
    private string $isolatedStoragePath;

    protected function setUp(): void
    {
        parent::setUp();

        // isDownForMaintenance() reads storage_path('framework/down');
        // isolate storage so this test never touches the real deployment's
        // maintenance-mode marker file.
        $this->isolatedStoragePath = sys_get_temp_dir() . '/ispa-lifecycle-barrier-' . uniqid();
        File::ensureDirectoryExists($this->isolatedStoragePath . '/framework');
        $this->app->useStoragePath($this->isolatedStoragePath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->isolatedStoragePath);

        parent::tearDown();
    }

    public function test_default_binding_is_maintenance_only_never_an_in_process_mutex(): void
    {
        $this->assertInstanceOf(MaintenanceOnlyModuleLifecycleBarrier::class, app(ModuleLifecycleBarrier::class));
    }

    public function test_transition_refuses_to_run_the_callback_outside_maintenance_without_confirmation(): void
    {
        $ran = false;

        $conflict = app(ModuleLifecycleBarrier::class)->transition('Widget', function () use (&$ran) {
            $ran = true;

            return null;
        });

        $this->assertFalse($ran, 'The guarded callback must never run for a live, non-maintenance request.');
        $this->assertSame(MaintenanceOnlyModuleLifecycleBarrier::CODE_MAINTENANCE_REQUIRED, $conflict);
    }

    public function test_transition_runs_the_callback_when_the_operator_confirms_a_drained_maintenance_window(): void
    {
        $ran = false;

        $conflict = app(ModuleLifecycleBarrier::class)->transition('Widget', function () use (&$ran) {
            $ran = true;

            return null;
        }, true);

        $this->assertTrue($ran);
        $this->assertNull($conflict);
    }

    public function test_transition_runs_the_callback_when_the_application_is_genuinely_down_for_maintenance(): void
    {
        File::put(storage_path('framework/down'), json_encode(['time' => time()], JSON_THROW_ON_ERROR));

        $this->assertTrue($this->app->isDownForMaintenance());

        $ran = false;

        $conflict = app(ModuleLifecycleBarrier::class)->transition('Widget', function () use (&$ran) {
            $ran = true;

            return null;
        });

        $this->assertTrue($ran);
        $this->assertNull($conflict);
    }

    public function test_transition_bubbles_up_the_callbacks_own_conflict_code_without_altering_it(): void
    {
        $conflict = app(ModuleLifecycleBarrier::class)->transition('Widget', function () {
            return 'WIDGET_MODULE_IN_USE';
        }, true);

        $this->assertSame('WIDGET_MODULE_IN_USE', $conflict);
    }

    public function test_transition_refuses_an_http_context_caller_before_even_checking_maintenance(): void
    {
        // Simulate a php-fpm/HTTP worker: runningInConsole() is false. The
        // barrier must refuse BEFORE consulting maintenance state — the
        // maintenance bypass cookie means "down for maintenance" alone does
        // not prove the caller is the sanctioned drained-maintenance CLI —
        // and even an explicit confirmation flag must not override it.
        $app = Mockery::mock(Application::class);
        $app->shouldReceive('runningInConsole')->andReturn(false);
        $app->shouldReceive('isDownForMaintenance')->never();

        $barrier = new MaintenanceOnlyModuleLifecycleBarrier($app);

        $ran = false;

        $conflict = $barrier->transition('Widget', function () use (&$ran) {
            $ran = true;

            return null;
        }, true);

        $this->assertFalse($ran, 'An HTTP-context caller must never run the guarded callback, even during maintenance with confirmation.');
        $this->assertSame(MaintenanceOnlyModuleLifecycleBarrier::CODE_MAINTENANCE_REQUIRED, $conflict);
    }

    public function test_transition_allows_a_console_caller_only_under_maintenance_or_confirmation(): void
    {
        // Console caller, not down, no confirmation => refused at the
        // maintenance check (the second gate, after the console gate).
        $app = Mockery::mock(Application::class);
        $app->shouldReceive('runningInConsole')->andReturn(true);
        $app->shouldReceive('isDownForMaintenance')->andReturn(false);

        $barrier = new MaintenanceOnlyModuleLifecycleBarrier($app);

        $ran = false;
        $conflict = $barrier->transition('Widget', function () use (&$ran) {
            $ran = true;

            return null;
        });

        $this->assertFalse($ran);
        $this->assertSame(MaintenanceOnlyModuleLifecycleBarrier::CODE_MAINTENANCE_REQUIRED, $conflict);
    }

    public function test_maintenance_required_conflict_code_is_the_stable_module_neutral_value(): void
    {
        // Pins the machine-readable code as a stable, module-neutral
        // contract value: the generic barrier never emits module-prefixed
        // codes (guards supply their own module-specific codes).
        $this->assertSame('MODULE_MAINTENANCE_REQUIRED', MaintenanceOnlyModuleLifecycleBarrier::CODE_MAINTENANCE_REQUIRED);
    }
}
