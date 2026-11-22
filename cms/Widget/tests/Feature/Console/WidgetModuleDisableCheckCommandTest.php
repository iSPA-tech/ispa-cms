<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Console;

use App\Constants\UserType;
use App\Contracts\Modules\ModuleDisableGuard;
use App\Models\User;
use App\Support\Modules\ManagedModuleDisabler;
use App\Support\Modules\ModuleDisableGuardRegistry;
use Cms\Widget\Models\WidgetArtifactBlob;
use Cms\Widget\Models\WidgetArtifactManifest as WidgetArtifactManifestModel;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetInstanceVersion;
use Cms\Widget\Operations\WidgetModuleDisableGuard;
use Cms\Widget\Providers\WidgetServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Chunk 2 Task 22: WidgetModuleDisableGuard + WidgetModuleDisableCheckCommand
 * (lines
 * 1181-1216, DoD lines 1201-1204).
 *
 * Boots the real WidgetServiceProvider (mirrors every other Widget Feature
 * test's established pattern), which is the ONLY thing that registers the
 * REAL WidgetModuleDisableGuard into the root ModuleDisableGuardRegistry --
 * see that provider's own boot() docblock addition. This is deliberate and
 * safety-critical: cms/Widget is DISABLED BY DEFAULT
 * (WidgetModuleBoundaryTest::test_widget_module_is_present_and_disabled_by_default),
 * so nwidart never auto-registers WidgetServiceProvider in production or in
 * cms/Core's OWN test suite -- meaning
 * cms/Core/tests/Feature/Modules/ModuleDisableGuardTest.php's "no guard
 * registered for Widget" assertions stay true and that file needs zero
 * changes. Only a test file that explicitly boots the provider (this one)
 * ever observes the real guard.
 */
final class WidgetModuleDisableCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'ExampleProject';

    protected function beforeRefreshingDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;

        $this->app->afterResolving('migrator', static function ($migrator): void {
            $migrator->path(base_path('cms/Widget/database/migrations'));
        });
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['ispa-cms.folder_name' => self::PROJECT]);

        // Matches cms/Core/tests/Feature/Modules/ModuleDisableGuardTest's
        // own established precedent for hitting the admin ModuleController
        // endpoint in a test: without this, HttpsProtocol middleware
        // redirects (302) an http://localhost test request instead of
        // reaching the controller.
        URL::forceScheme('https');

        // Widget is an enabled bundled module (since 2026-07-20): the application has already booted the provider and
        // registered its guard. Boot it by hand only when it has not, so the guard is never registered twice.
        if (! app(ModuleDisableGuardRegistry::class)->hasGuardsFor('Widget')) {
            $provider = new WidgetServiceProvider($this->app);
            $provider->register();
            $provider->boot();
        }
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function createManifestWithBlob(): WidgetArtifactManifestModel
    {
        $blob = WidgetArtifactBlob::query()->create([
            'project_name' => self::PROJECT,
            'artifact_hash' => str_repeat('a', 64),
            'storage_disk' => 'local',
            'storage_key' => 'fixture/blob.php',
            'byte_size' => 1,
            'media_type' => 'application/x-php',
            'checksum' => str_repeat('b', 64),
        ]);

        return WidgetArtifactManifestModel::query()->create([
            'project_name' => self::PROJECT,
            'manifest_hash' => str_repeat('c', 64),
            'artifact_blob_id' => $blob->id,
            'generator_version' => '1',
            'renderer_key' => 'legacy',
            'renderer_version' => '1',
            'applicability' => [self::PROJECT],
            'executable_surface_fingerprint_hash' => str_repeat('d', 64),
            'canonical_schema_hash' => str_repeat('e', 64),
            'provider_contracts' => [],
            'trust_policy_version' => '1',
            'trust_lane' => 'legacy',
        ]);
    }

    /**
     * A retained Instance Version that is BOTH unplaced (no WidgetPlacement
     * anywhere references it) AND superseded (the owning Instance's own
     * current_version_id points at a LATER version, not this one) -- the
     * exact combination DoD 1201 names ("including unplaced and superseded
     * history") to prove the guard applies NO scope-narrowing WHERE clause.
     */
    private function createUnplacedSupersededInstanceVersion(): WidgetInstanceVersion
    {
        $manifestV1 = $this->createManifestWithBlob();

        $instance = WidgetInstance::query()->create([
            'project_name' => self::PROJECT,
            'name' => 'Fixture Instance',
            'status' => 'active',
        ]);

        $v1 = WidgetInstanceVersion::query()->create([
            'instance_id' => $instance->id,
            'project_name' => self::PROJECT,
            'version' => 1,
            'widget_id' => 999001,
            'widget_theme_id' => 999002,
            'renderer_key' => 'legacy',
            'renderer_version' => '1',
            'trust_classification' => 'legacy',
            'schema' => [],
            'schema_hash' => str_repeat('f', 64),
            'values' => [],
            'provider_refs' => [],
            'artifact_manifest_id' => $manifestV1->id,
        ]);

        // A second, later Manifest+Version so the Instance's current pointer
        // moves PAST v1 -- v1 becomes genuinely superseded, not merely "not
        // yet pointed at".
        $manifestV2 = WidgetArtifactManifestModel::query()->create([
            'project_name' => self::PROJECT,
            'manifest_hash' => str_repeat('1', 64),
            'artifact_blob_id' => $manifestV1->artifact_blob_id,
            'generator_version' => '1',
            'renderer_key' => 'legacy',
            'renderer_version' => '1',
            'applicability' => [self::PROJECT],
            'executable_surface_fingerprint_hash' => str_repeat('2', 64),
            'canonical_schema_hash' => str_repeat('3', 64),
            'provider_contracts' => [],
            'trust_policy_version' => '1',
            'trust_lane' => 'legacy',
        ]);

        $v2 = WidgetInstanceVersion::query()->create([
            'instance_id' => $instance->id,
            'project_name' => self::PROJECT,
            'version' => 2,
            'widget_id' => 999001,
            'widget_theme_id' => 999002,
            'renderer_key' => 'legacy',
            'renderer_version' => '1',
            'trust_classification' => 'legacy',
            'schema' => [],
            'schema_hash' => str_repeat('f', 64),
            'values' => [],
            'provider_refs' => [],
            'artifact_manifest_id' => $manifestV2->id,
        ]);

        $instance->forceFill(['current_version_id' => $v2->id])->save();

        // Neither v1 nor v2 is referenced by any WidgetPlacement row -- both
        // stay "unplaced" for the whole life of this fixture.
        return $v1;
    }

    private function adminUser(): User
    {
        $user = User::query()->create([
            'name' => 'Widget Module Admin',
            'email' => 'widget-module-admin-' . uniqid('', true) . '@test.local',
            'password' => bcrypt('password'),
        ]);
        $user->forceFill(['type' => UserType::ADMIN])->save();

        return $user;
    }

    // ===================================================================
    // WidgetModuleDisableGuard predicate
    // ===================================================================

    public function test_guard_reports_the_widget_module_name(): void
    {
        $this->assertSame('Widget', (new WidgetModuleDisableGuard())->moduleName());
    }

    public function test_guard_permits_disable_when_no_instance_version_rows_exist(): void
    {
        $this->assertNull((new WidgetModuleDisableGuard())->evaluate());
    }

    public function test_guard_blocks_disable_when_a_retained_instance_version_exists(): void
    {
        $this->createUnplacedSupersededInstanceVersion();

        $this->assertSame('WIDGET_MODULE_IN_USE', (new WidgetModuleDisableGuard())->evaluate());
    }

    public function test_guard_blocks_even_when_the_only_retained_version_is_unplaced_and_superseded(): void
    {
        $v1 = $this->createUnplacedSupersededInstanceVersion();

        $instance = WidgetInstance::query()->findOrFail($v1->instance_id);
        $this->assertNotSame($v1->id, $instance->current_version_id, 'Fixture sanity: v1 must be superseded.');
        $this->assertSame(0, \Cms\Widget\Models\WidgetPlacement::query()->count(), 'Fixture sanity: nothing is placed.');

        $this->assertSame('WIDGET_MODULE_IN_USE', (new WidgetModuleDisableGuard())->evaluate());
    }

    // ===================================================================
    // Registration with the root registry (DoD 1201)
    // ===================================================================

    public function test_the_real_guard_is_registered_into_the_root_registry_on_boot(): void
    {
        $registry = app(ModuleDisableGuardRegistry::class);

        $this->assertTrue($registry->hasGuardsFor('Widget'));
        $this->assertSame([], $registry->evaluate('Widget'), 'Clean baseline: registered but currently permitting.');

        $this->createUnplacedSupersededInstanceVersion();

        $this->assertSame(['WIDGET_MODULE_IN_USE'], $registry->evaluate('Widget'));
    }

    public function test_the_registered_guard_is_the_real_widget_module_disable_guard_not_a_generic_placeholder(): void
    {
        $registry = app(ModuleDisableGuardRegistry::class);
        $found = false;

        $reflection = new \ReflectionClass($registry);
        $property = $reflection->getProperty('guards');
        $property->setAccessible(true);

        foreach ($property->getValue($registry) as $guard) {
            if ($guard instanceof WidgetModuleDisableGuard) {
                $found = true;
            }
        }

        $this->assertTrue($found, 'The concrete Cms\\Widget\\Operations\\WidgetModuleDisableGuard must be registered, not a stand-in.');
    }

    // ===================================================================
    // cms:widget-module-disable-check (diagnostic report; never mutates)
    // ===================================================================

    public function test_check_command_passes_and_reports_a_clean_baseline(): void
    {
        $exitCode = Artisan::call('cms:widget-module-disable-check');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('PASSED', $output);
    }

    public function test_check_command_reports_unreferenced_blob_and_manifest_counts_without_blocking(): void
    {
        $this->createManifestWithBlob();
        WidgetArtifactBlob::query()->create([
            'project_name' => self::PROJECT,
            'artifact_hash' => str_repeat('9', 64),
            'storage_disk' => 'local',
            'storage_key' => 'fixture/unreferenced.php',
            'byte_size' => 1,
            'media_type' => 'application/x-php',
            'checksum' => str_repeat('9', 64),
        ]);

        $exitCode = Artisan::call('cms:widget-module-disable-check');
        $output = Artisan::output();

        // createManifestWithBlob()'s own Blob IS referenced (by that same
        // Manifest) -- only the second, standalone Blob is unreferenced.
        // The Manifest itself is unreferenced by any Instance Version --
        // informational only, must never block (no retained Instance
        // Version exists in this test).
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Unreferenced Blob', $output);
        $this->assertStringContainsString('Unreferenced Manifest', $output);
        $this->assertMatchesRegularExpression('/Unreferenced Blob row\(s\):\s*1/', $output);
        $this->assertMatchesRegularExpression('/Unreferenced Manifest row\(s\):\s*1/', $output);
    }

    public function test_check_command_reports_empty_layout_history_without_blocking(): void
    {
        $exitCode = Artisan::call('cms:widget-module-disable-check');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Layout history is empty', $output);
    }

    public function test_check_command_fails_and_reports_the_blocking_reason_when_a_retained_version_exists(): void
    {
        $this->createUnplacedSupersededInstanceVersion();

        $exitCode = Artisan::call('cms:widget-module-disable-check');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('WIDGET_MODULE_IN_USE', $output);
        $this->assertStringContainsString('BLOCKED', $output);
    }

    // ===================================================================
    // Cumulative command discovery (DoD 1202)
    // ===================================================================

    public function test_every_widget_command_remains_discoverable_through_artisan(): void
    {
        $names = array_keys(Artisan::all());

        $this->assertContains('cms:widget-registry-preflight', $names);
        $this->assertContains('cms:widget-manifests-reconcile', $names);
        $this->assertContains('cms:widget-module-disable-check', $names);
    }

    // ===================================================================
    // Managed admin + sanctioned CLI disable integration (DoD 1201, 1203)
    // ===================================================================

    public function test_sanctioned_cli_blocks_disable_while_a_retained_instance_version_exists_even_with_maintenance_confirmed(): void
    {
        $this->createUnplacedSupersededInstanceVersion();

        $exitCode = Artisan::call('cms:module-disable', [
            'module' => 'Widget',
            '--force-maintenance-confirmed' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('WIDGET_MODULE_IN_USE', Artisan::output());
    }

    public function test_sanctioned_cli_requires_maintenance_confirmation_even_when_the_guard_would_permit(): void
    {
        // G-013 posture (no proven cross-process barrier adapter): once ANY
        // guard is registered for a module, every disable attempt -- even
        // one the guard itself would permit -- must still pass through the
        // barrier's console-only drained-maintenance gate. This is the
        // "live admin disable fails closed" contract the coordinator brief
        // names explicitly; asserting it here catches any accidental
        // future loosening of that default.
        $exitCode = Artisan::call('cms:module-disable', ['module' => 'Widget']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('MODULE_MAINTENANCE_REQUIRED', Artisan::output());
    }

    public function test_admin_http_disable_returns_a_stable_409_while_a_retained_instance_version_exists(): void
    {
        $this->createUnplacedSupersededInstanceVersion();

        Sanctum::actingAs($this->adminUser());

        // Real Widget module, unmocked: the barrier gates BEFORE the guard
        // ever runs (DoD 1203), so this never actually reaches
        // ModuleFacade::disable() regardless of the guard's own verdict.
        $response = $this->putJson('/api/v2/admin/utilities/modules/Widget/status', ['enabled' => false]);

        $response->assertStatus(409)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('error_code', 'MODULE_MAINTENANCE_REQUIRED');
    }

    // ===================================================================
    // DoD 1204: disable AND Core Definition/Theme delete both return
    // stable 409 recovery while references exist -- never an unnormalized
    // 500. The Core-delete half is ALREADY exhaustively proven by
    // cms/Widget/tests/Feature/Http/WidgetInstanceApiTest.php's
    // "Module-boundary integration" section
    // (test_core_widget_destroy_returns_409_definition_in_use_when_referenced_by_a_retained_instance_version
    // et al, via the real HTTP widget_instance_versions.widget_id/
    // widget_theme_id snapshot columns -- confirmed by that table's own
    // migration docblock to carry NO literal DB foreign key into Core's
    // widgets/widget_themes tables at all, so there is structurally no FK
    // violation path to turn into an unnormalized 500 in the first place).
    // This test adds the DISABLE half using the identical retained-version
    // shape, proving both protections agree while references exist.
    // ===================================================================

    public function test_disable_stays_blocked_with_a_stable_conflict_code_never_a_raw_500_while_referenced(): void
    {
        $this->createUnplacedSupersededInstanceVersion();

        $cliExit = Artisan::call('cms:module-disable', [
            'module' => 'Widget',
            '--force-maintenance-confirmed' => true,
        ]);
        $this->assertSame(1, $cliExit);
        $this->assertStringContainsString('WIDGET_MODULE_IN_USE', Artisan::output());

        Sanctum::actingAs($this->adminUser());
        $adminResponse = $this->putJson('/api/v2/admin/utilities/modules/Widget/status', ['enabled' => false]);
        $adminResponse->assertStatus(409);
        $this->assertNotSame(500, $adminResponse->getStatusCode());
    }
}
