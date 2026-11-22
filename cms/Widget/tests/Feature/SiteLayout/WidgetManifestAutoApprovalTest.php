<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\SiteLayout;

use App\Constants\UserType;
use App\Contracts\Modules\ModuleLifecycleBarrier;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use App\Contracts\Widget\DTO\WidgetSlotDefinition;
use Cms\Core\Models\AuditLog;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Widget\Catalog\WidgetAuditRecorder;
use Cms\Widget\Database\Seeders\WidgetPermissionTableSeeder;
use Cms\Widget\Instances\WidgetInstanceService;
use Cms\Widget\Models\WidgetArtifactManifest;
use Cms\Widget\Models\WidgetIdempotencyKey;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Models\WidgetPlacement;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\Rendering\Artifacts\FilesystemWidgetArtifactBlobStore;
use Cms\Widget\Rendering\Eligibility\WidgetBlockedException;
use Cms\Widget\Rendering\Eligibility\WidgetLegacyManifestMaterializer;
use Cms\Widget\Rendering\Eligibility\WidgetManifestApprovalService;
use Cms\Widget\Rendering\Eligibility\WidgetManifestAutoApprover;
use Cms\Widget\SiteLayout\Publishing\WidgetLayoutPublishResult;
use Cms\Widget\SiteLayout\Publishing\WidgetLayoutPublishService;
use Cms\Widget\SiteLayout\Slots\WidgetSlotDeclaration;
use Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Tests\TestCase;

/**
 * Unified Widget Catalog Part 2, Task 1: the per-site approval screen is gone, so placing,
 * previewing or publishing a widget approves its manifest automatically (audited), and never
 * for a blocked widget.
 */
final class WidgetManifestAutoApprovalTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'ExampleProject';

    private const SLOT = 'test.auto-approval.slot';

    private string $storageRoot;

    /** @var array<string, string|null> */
    private static array $modulesStatusesSnapshot = [];

    protected function beforeRefreshingDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;

        $this->app->afterResolving('migrator', static function ($migrator): void {
            $migrator->path(base_path('cms/Widget/database/migrations'));
        });
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $path = dirname(__DIR__, 5) . '/modules_statuses.json';
        self::$modulesStatusesSnapshot = [$path => file_exists($path) ? file_get_contents($path) : null];

        register_shutdown_function(static function (): void {
            self::restoreModulesStatuses();
        });
    }

    public static function tearDownAfterClass(): void
    {
        self::restoreModulesStatuses();

        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    private static function restoreModulesStatuses(): void
    {
        foreach (self::$modulesStatusesSnapshot as $path => $original) {
            if ($original !== null) {
                file_put_contents($path, $original);
            } elseif (file_exists($path)) {
                @unlink($path);
            }
        }
        self::$modulesStatusesSnapshot = [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['ispa-cms.folder_name' => self::PROJECT]);

        $this->storageRoot = storage_path('framework/testing/widget-auto-approval-' . bin2hex(random_bytes(6)));

        $provider = new WidgetServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->app->singleton(
            FilesystemWidgetArtifactBlobStore::class,
            fn (): FilesystemWidgetArtifactBlobStore => new FilesystemWidgetArtifactBlobStore($this->storageRoot)
        );

        $this->app->make(WidgetSlotRegistry::class)->register(new WidgetSlotDeclaration(
            key: self::SLOT,
            labelKey: 'test.auto-approval.slot.label',
            cardinality: WidgetSlotDefinition::CARDINALITY_STACK,
        ));

        app(ActivatorInterface::class)->setActiveByName('Widget', true);

        $this->app->singleton(ModuleLifecycleBarrier::class, fn () => new class implements ModuleLifecycleBarrier {
            public function transition(string $moduleName, callable $onAcquired, bool $maintenanceConfirmed = false): ?string
            {
                return $onAcquired();
            }
        });

        $this->withoutMiddleware([
            ApiLocalization::class,
            ThrottleRequests::class,
            HttpsProtocol::class,
            Localization::class,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageRoot)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->storageRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->storageRoot);
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function createWidget(): Widget
    {
        return Widget::query()->create(['name' => 'Auto Widget', 'description' => '', 'js' => '']);
    }

    private function createTheme(Widget $widget): WidgetTheme
    {
        return WidgetTheme::query()->create([
            'widget_id' => $widget->id,
            'name' => 'Auto Theme',
            'content' => '<div class="card">{title}</div>',
            'css' => '.card { color: red; }',
            'project_name' => [self::PROJECT],
        ]);
    }

    private function placingUser(): User
    {
        $user = User::forceCreate([
            'name' => 'Auto Approval Tester',
            'email' => 'auto-approval-' . uniqid('', true) . '@test.local',
            'password' => bcrypt('secret'),
            'type' => UserType::ADMIN,
        ]);

        (new WidgetPermissionTableSeeder())->run();

        // Deliberately WITHOUT widget_template.approve: placing a widget must not need it.
        $role = Role::query()->create(['name' => 'Placer ' . uniqid('', true), 'custom' => ['level' => 50]]);
        foreach (['widget_instance.create', 'widget_layout.update', 'widget_layout.publish'] as $key) {
            [$group, $name] = explode('.', $key, 2);
            $role->permissions()->attach(Permission::query()->where('group', $group)->where('name', $name)->firstOrFail()->id);
        }
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function blockWidget(Widget $widget): void
    {
        $widget->forceFill(['blocked_at' => now(), 'blocked_reason' => 'test block'])->save();
    }

    private function manifestsFor(): \Illuminate\Support\Collection
    {
        return WidgetArtifactManifest::query()->where('project_name', self::PROJECT)->get();
    }

    private function placedInstance(Widget $widget, WidgetTheme $theme, User $user): WidgetInstance
    {
        $created = app(WidgetInstanceService::class)->create(self::PROJECT, (int) $user->id, 'auto-create-' . uniqid('', true), [
            'name' => 'Area widget',
            'widget_id' => $widget->id,
            'widget_theme_id' => $theme->id,
            'values' => ['title' => 'Hello'],
        ]);
        $this->assertNotNull($created->instance, 'Instance creation must not wait for a manual approval.');

        return $created->instance;
    }

    /**
     * @param list<WidgetInstance> $instances
     * @return array{layout: WidgetLayout, draft: WidgetLayoutVersion}
     */
    private function draftWithPlacements(array $instances): array
    {
        $layout = WidgetLayout::query()->create(['project_name' => self::PROJECT, 'key' => 'site']);
        $declaration = app(WidgetSlotRegistry::class)->declarationFor(self::SLOT);
        $contracts = ['slots' => [self::SLOT => $declaration->semanticFields()], 'template_contexts' => [], 'target_contexts' => [], 'providers' => []];
        $draft = WidgetLayoutVersion::query()->create([
            'layout_id' => $layout->id,
            'project_name' => self::PROJECT,
            'version' => 1,
            'state' => WidgetLayoutVersion::STATE_DRAFT,
            'lock_version' => 0,
            'registry_contracts' => $contracts,
            'registry_contract_hash' => hash('sha256', json_encode($contracts)),
        ]);
        $layout->current_draft_version_id = $draft->id;
        $layout->save();

        foreach ($instances as $position => $instance) {
            WidgetPlacement::query()->create([
                'project_name' => self::PROJECT,
                'layout_version_id' => $draft->id,
                'widget_instance_version_id' => $instance->currentVersion->id,
                'slot_key' => self::SLOT,
                'scope_type' => 'global',
                'scope_identity' => 'global',
                'position' => $position,
                'enabled' => true,
            ]);
        }

        return ['layout' => $layout->fresh(), 'draft' => $draft->fresh()];
    }

    // -----------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------

    public function test_placing_a_widget_never_needs_a_manual_approval(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->placingUser();

        $response = $this->actingAs($user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'auto-approve-create'])
            ->postJson('/api/v2/admin/widget/instances', [
                'name' => 'Placed without approval',
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
                'values' => ['title' => 'Hello'],
            ]);

        $response->assertCreated();

        $instance = WidgetInstance::query()->firstOrFail();
        $manifest = WidgetArtifactManifest::query()->findOrFail($instance->currentVersion->artifact_manifest_id);
        $this->assertSame(WidgetManifestApprovalService::STATE_APPROVED, $manifest->eligibility_state);
        $this->assertSame((int) $user->id, (int) $manifest->approved_by);
    }

    public function test_publish_after_a_catalog_change_re_approves_and_succeeds(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->placingUser();
        $instance = $this->placedInstance($widget, $theme, $user);
        ['layout' => $layout, 'draft' => $draft] = $this->draftWithPlacements([$instance]);

        // A deploy sync changes the hub widget: the after-commit listener materializes a NEW
        // pending manifest for the changed source.
        $theme->forceFill(['content' => '<section class="card">{title} v2</section>'])->save();
        $sync = app(WidgetLegacyManifestMaterializer::class)->materialize($widget->id, $theme->id, self::PROJECT);
        $newHash = (string) $sync->manifestRow['manifest_hash'];
        $this->assertSame(WidgetManifestApprovalService::STATE_PENDING, $sync->eligibilityState);

        $result = app(WidgetLayoutPublishService::class)->publish(self::PROJECT, $layout->uuid, (int) $user->id, 'auto-publish', $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutPublishResult::STATUS_PUBLISHED, $result->status);
        $this->assertSame(
            WidgetManifestApprovalService::STATE_APPROVED,
            WidgetArtifactManifest::query()->where('project_name', self::PROJECT)->where('manifest_hash', $newHash)->value('eligibility_state'),
            'Publish must re-approve the manifest of the changed source.',
        );
    }

    public function test_a_revoked_manifest_is_re_approved_and_audited_with_its_previous_state(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->placingUser();
        $approver = app(WidgetManifestAutoApprover::class);

        $hash = $approver->ensureApproved($widget->id, $theme->id, (int) $user->id);
        $version = (int) WidgetArtifactManifest::query()->where('manifest_hash', $hash)->value('attestation_version');
        app(WidgetManifestApprovalService::class)->revoke(self::PROJECT, $hash, $version, (int) $user->id, 'compat revoke');

        $this->assertSame($hash, $approver->ensureApproved($widget->id, $theme->id, (int) $user->id));

        $this->assertSame(
            WidgetManifestApprovalService::STATE_APPROVED,
            WidgetArtifactManifest::query()->where('manifest_hash', $hash)->value('eligibility_state'),
        );
        $latest = AuditLog::query()->where('action', WidgetAuditRecorder::ACTION_AUTO_APPROVED)->latest('id')->firstOrFail();
        $this->assertSame(WidgetManifestApprovalService::STATE_REVOKED, $latest->options['previous_state']);
        $this->assertSame((int) $user->id, (int) $latest->user_id);
    }

    public function test_publish_with_one_blocked_placement_succeeds_and_publishes_the_other_changes(): void
    {
        $user = $this->placingUser();
        $blockedWidget = $this->createWidget();
        $blockedTheme = $this->createTheme($blockedWidget);
        $blockedInstance = $this->placedInstance($blockedWidget, $blockedTheme, $user);

        $okWidget = Widget::query()->create(['name' => 'Ok Widget', 'description' => '', 'js' => '']);
        $okTheme = WidgetTheme::query()->create([
            'widget_id' => $okWidget->id,
            'name' => 'Ok Theme',
            'content' => '<p class="ok">{title}</p>',
            'css' => '.ok { color: green; }',
            'project_name' => [self::PROJECT],
        ]);
        $okInstance = $this->placedInstance($okWidget, $okTheme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->draftWithPlacements([$blockedInstance, $okInstance]);
        $this->blockWidget($blockedWidget);

        $result = app(WidgetLayoutPublishService::class)->publish(self::PROJECT, $layout->uuid, (int) $user->id, 'publish-with-blocked', $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutPublishResult::STATUS_PUBLISHED, $result->status);
        $this->assertSame(
            [$blockedInstance->currentVersion->id, $okInstance->currentVersion->id],
            $result->release->placements()->orderBy('position')->pluck('widget_instance_version_id')->map(fn ($id) => (int) $id)->all(),
            'The release carries every placement; the blocked one renders nothing through the Core block check.',
        );
    }

    public function test_blocked_widget_cannot_be_auto_approved(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->placingUser();
        $this->blockWidget($widget);

        try {
            app(WidgetManifestAutoApprover::class)->ensureApproved($widget->id, $theme->id, (int) $user->id);
            $this->fail('A blocked widget must never be auto-approved.');
        } catch (WidgetBlockedException) {
            // expected
        }

        $this->assertSame(0, $this->manifestsFor()->where('eligibility_state', WidgetManifestApprovalService::STATE_APPROVED)->count());

        $response = $this->actingAs($user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'blocked-create'])
            ->postJson('/api/v2/admin/widget/instances', [
                'name' => 'Blocked',
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'WIDGET_BLOCKED')
            ->assertJsonPath('error_code', 'WIDGET_BLOCKED')
            ->assertJsonPath('status', 409);
        $this->assertSame(0, WidgetInstance::query()->count());
        $this->assertSame(0, WidgetIdempotencyKey::query()->where('state', WidgetIdempotencyKey::STATE_PROCESSING)->count(), 'The idempotency claim must be released.');
    }

    public function test_auto_approval_writes_widget_auto_approved_audit_with_actor(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->placingUser();

        $approver = app(WidgetManifestAutoApprover::class);
        $hash = $approver->ensureApproved($widget->id, $theme->id, (int) $user->id);

        $rows = AuditLog::query()->where('action', WidgetAuditRecorder::ACTION_AUTO_APPROVED)->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame((int) $user->id, (int) $row->user_id);
        $this->assertSame('widget', $row->target_type);
        $this->assertSame((int) $widget->id, (int) $row->target_id);
        $this->assertSame(['manifest_hash' => $hash, 'theme_id' => (int) $theme->id, 'previous_state' => WidgetManifestApprovalService::STATE_PENDING], $row->options);

        // Already approved: a second call returns the same hash and writes no second audit row.
        $this->assertSame($hash, $approver->ensureApproved($widget->id, $theme->id, (int) $user->id));
        $this->assertSame(1, AuditLog::query()->where('action', WidgetAuditRecorder::ACTION_AUTO_APPROVED)->count());
    }
}
