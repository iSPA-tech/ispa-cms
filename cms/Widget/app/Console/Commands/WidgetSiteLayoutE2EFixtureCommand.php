<?php

declare(strict_types=1);

namespace Cms\Widget\Console\Commands;

use App\Constants\UserType;
use App\Contracts\Widget\DTO\WidgetSlotDefinition;
use App\Models\User;
use App\Support\Widget\CanonicalWidgetSchema;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher;
use Cms\Widget\Database\Seeders\WidgetPermissionTableSeeder;
use Cms\Widget\Instances\WidgetInstanceService;
use Cms\Widget\Models\WidgetArtifactManifest as WidgetArtifactManifestModel;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetInstanceVersion;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Models\WidgetPlacement;
use Cms\Widget\Rendering\ApprovedLegacyWidgetRenderer;
use Cms\Widget\Rendering\Eligibility\LegacyWidgetExecutableSurfaceAnalyzer;
use Cms\Widget\Rendering\Eligibility\WidgetLegacyManifestMaterializationResult;
use Cms\Widget\Rendering\Eligibility\WidgetLegacyManifestMaterializer;
use Cms\Widget\Rendering\Eligibility\WidgetManifestApprovalService;
use Cms\Widget\Rendering\Eligibility\WidgetManifestRuntimeBlockService;
use Cms\Widget\SiteLayout\Drafts\WidgetLayoutBootstrapService;
use Cms\Widget\SiteLayout\Drafts\WidgetLayoutDraftSaveResult;
use Cms\Widget\SiteLayout\Drafts\WidgetLayoutDraftService;
use Cms\Widget\SiteLayout\Publishing\WidgetLayoutPublishResult;
use Cms\Widget\SiteLayout\Publishing\WidgetLayoutPublishService;
use Cms\Widget\SiteLayout\Slots\WidgetSlotDeclaration;
use Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * `cms:widget-site-layout-fixture` (plan Chunk 2 Task 24b, DoD 1305-1306).
 *
 * A LOCAL/TESTING-ONLY, production-refusing, deterministic, idempotent
 * fixture builder. Seeds one complete Site Layout lifecycle end to end by
 * calling the SAME real services the HTTP controllers call (never a
 * parallel/simplified re-implementation): permission inventory (Task 14),
 * an approved Manifest (Tasks 15a/15b), an Instance + Version (Task 16), a
 * bootstrapped Layout + Draft with one Placement (Task 17), and a published
 * Release (Task 20).
 *
 * DETERMINISM: every identity is a FIXED natural key (widget/theme/instance
 * name, Layout key `site` -- the only allowlisted bootstrap key -- and one
 * fixed Slot). Re-running WITHOUT `--reset` is a true no-op replay (every
 * step's own idempotent primitive -- materialize-by-content-hash,
 * bootstrap-by-unique-key, Instance create idempotency-key replay, a
 * placement-already-matches skip, and a Release-already-exists skip --
 * converges without mutating anything). `--reset` deletes only this
 * fixture's OWN lifecycle rows (Placement/LayoutVersion/Layout by key,
 * InstanceVersion/Instance by name, both scoped to the resolved project)
 * and restores the Manifest's mutable attestation/control state to clean
 * approved/unblocked -- it deliberately never deletes the Widget/Theme/
 * Manifest/Blob catalog rows themselves (content-addressed and safe to
 * reuse; Task 20's own closure notes record that this environment DOES
 * enforce the deferred FK from Instance Version to Manifest/Blob, so
 * deleting those out from under a live reference would throw, not help).
 *
 * NO EMBEDDED CREDENTIALS: the one system actor User this command creates
 * for audit-trail purposes (`approved_by`/`created_by` etc. columns) gets a
 * random per-install password (`Str::random(40)`), never a literal secret
 * string -- this actor is never authenticated against, only referenced by
 * id.
 */
final class WidgetSiteLayoutE2EFixtureCommand extends Command
{
    protected $signature = 'cms:widget-site-layout-fixture
        {--reset : Delete this fixture\'s own prior lifecycle rows and rebuild from scratch}
        {--format=text : Output format: text (human-readable) or json}';

    protected $description = 'Seed a deterministic, local/testing-only Widget Site Layout lifecycle (permissions, approved Manifest, Instance+Version, Layout+Draft, published Release). Refuses to run in production.';

    private const WIDGET_NAME = 'Widget Site Layout Fixture Widget';

    private const THEME_NAME = 'Widget Site Layout Fixture Theme';

    private const INSTANCE_NAME = 'Widget Site Layout Fixture Instance';

    private const LAYOUT_KEY = 'site';

    private const SLOT_KEY = 'site_header_before';

    private const SLOT_LABEL_KEY = 'widget_site_layout.slot.site_header_before';

    private const SYSTEM_ACTOR_EMAIL = 'widget-site-layout-fixture@local.test';

    private const INSTANCE_IDEMPOTENCY_KEY = 'widget-site-layout-fixture:instance-create';

    private const PUBLISH_IDEMPOTENCY_KEY = 'widget-site-layout-fixture:publish';

    /** Frozen permission inventory this fixture confirms is seeded (mirrors WidgetPermissionTableSeeder's own list). */
    private const EXPECTED_PERMISSION_KEYS = [
        'widget_instance.viewAny', 'widget_instance.view', 'widget_instance.create', 'widget_instance.update', 'widget_instance.delete',
        'widget_layout.viewAny', 'widget_layout.view', 'widget_layout.update', 'widget_layout.preview', 'widget_layout.publish', 'widget_layout.rollback',
        'widget_template.approve', 'widget_template.emergencyDisable',
    ];

    public function __construct(
        private readonly WidgetInstanceService $instances,
        private readonly WidgetLegacyManifestMaterializer $materializer,
        private readonly WidgetManifestApprovalService $approvals,
        private readonly WidgetManifestRuntimeBlockService $runtimeBlock,
        private readonly WidgetLayoutBootstrapService $bootstrapService,
        private readonly WidgetLayoutDraftService $drafts,
        private readonly WidgetLayoutPublishService $publisher,
        private readonly WidgetSlotRegistry $slots,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('cms:widget-site-layout-fixture refuses to run in production: this command seeds local/testing-only data and must never touch a production database.');

            return self::FAILURE;
        }

        $projectName = (string) config('ispa-cms.folder_name', 'Default');
        $reset = (bool) $this->option('reset');
        $format = (string) $this->option('format');

        try {
            if ($reset) {
                $this->deleteFixtureLifecycleRows($projectName);
            }

            $this->registerSlotIfAbsent();
            $rolesSeeded = $this->seedPermissions();
            $actorId = (int) $this->systemActor()->id;

            $widget = $this->findOrCreateWidget();
            $theme = $this->findOrCreateTheme($widget, $projectName);

            $manifest = $this->materializeAndApprove($widget, $theme, $projectName, $actorId);

            $instance = $this->findOrCreateInstance($projectName, $widget, $theme);

            $layout = $this->bootstrapService->bootstrap($projectName, self::LAYOUT_KEY)->layout->fresh();
            $draft = $this->ensureDraftPlacement($projectName, $layout, $instance);

            $layout = $layout->fresh();
            if ($layout->current_published_version_id === null) {
                $layout = $this->publishLayout($projectName, $layout, $draft, $actorId);
            }
        } catch (RuntimeException $exception) {
            $this->error('Widget Site Layout fixture failed: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $draft = WidgetLayoutVersion::query()->findOrFail($layout->current_draft_version_id);
        $release = WidgetLayoutVersion::query()->findOrFail($layout->current_published_version_id);
        $instance = $instance->fresh();

        $result = [
            'reset' => $reset,
            'project_name' => $projectName,
            'widget_id' => $widget->id,
            'widget_theme_id' => $theme->id,
            'manifest_hash' => $manifest->manifest_hash,
            'instance_uuid' => $instance->uuid,
            'instance_version' => $instance->currentVersion->version,
            'layout_uuid' => $layout->uuid,
            'layout_key' => $layout->key,
            'draft_version' => $draft->version,
            'draft_lock_version' => $draft->lock_version,
            'release_version' => $release->version,
            'roles_seeded' => $rolesSeeded,
        ];

        $this->emit($result, $format);

        return self::SUCCESS;
    }

    // -----------------------------------------------------------------
    // Reset: delete only this fixture's own lifecycle rows.
    // -----------------------------------------------------------------

    private function deleteFixtureLifecycleRows(string $projectName): void
    {
        $layout = WidgetLayout::query()->where('project_name', $projectName)->where('key', self::LAYOUT_KEY)->first();
        if ($layout !== null) {
            $versionIds = WidgetLayoutVersion::query()->where('layout_id', $layout->id)->pluck('id');
            WidgetPlacement::query()->whereIn('layout_version_id', $versionIds)->delete();
            $layout->forceFill(['current_draft_version_id' => null, 'current_published_version_id' => null])->save();
            // Publish/rollback chain WidgetLayoutVersion rows together via
            // the SELF-referencing based_on_version_id/rollback_of_version_id
            // FKs (e.g. the fresh post-publish Draft is based_on the
            // Release, which is based_on the pre-publish Draft) -- clear
            // both BEFORE the bulk delete below, exactly like the Layout's
            // own current_draft/current_published pointers just above,
            // otherwise deleting the referenced row before its referencing
            // sibling throws a real FK violation on this environment (the
            // deferred-FK migration is NOT inert here -- Task 20's own
            // closure notes already recorded the identical finding for the
            // Instance-Version-to-Manifest/Blob FK).
            WidgetLayoutVersion::query()->whereIn('id', $versionIds)->update([
                'based_on_version_id' => null,
                'rollback_of_version_id' => null,
            ]);
            WidgetLayoutVersion::query()->whereIn('id', $versionIds)->delete();
            $layout->delete();
        }

        $instance = WidgetInstance::query()->withTrashed()->where('project_name', $projectName)->where('name', self::INSTANCE_NAME)->first();
        if ($instance !== null) {
            $instance->forceFill(['current_version_id' => null])->save();
            WidgetInstanceVersion::query()->where('instance_id', $instance->id)->delete();
            $instance->forceDelete();
        }

        // The Instance-create idempotency claim is keyed by a FIXED literal
        // resource_scope ('instance', never a regenerated uuid -- see
        // WidgetInstanceService::create()'s own claim() call), so unlike the
        // publish claim (naturally orphaned by a fresh Layout uuid each
        // reset) it survives the Instance delete above and would otherwise
        // make the next create() call replay a COMMITTED claim pointing at
        // a uuid that no longer exists. Deleting it here keeps the claim
        // table in lockstep with the Instance row it was guarding.
        \Cms\Widget\Models\WidgetIdempotencyKey::query()
            ->where('project_name', $projectName)
            ->where('action', 'instance_create')
            ->where('resource_scope', 'instance')
            ->where('idempotency_key', self::INSTANCE_IDEMPOTENCY_KEY)
            ->delete();
    }

    // -----------------------------------------------------------------
    // Slot / permissions / system actor
    // -----------------------------------------------------------------

    private function registerSlotIfAbsent(): void
    {
        if (in_array(self::SLOT_KEY, $this->slots->keys(), true)) {
            return;
        }

        $this->slots->register(new WidgetSlotDeclaration(
            key: self::SLOT_KEY,
            labelKey: self::SLOT_LABEL_KEY,
            cardinality: WidgetSlotDefinition::CARDINALITY_STACK,
        ));
    }

    /**
     * @return list<string>
     */
    private function seedPermissions(): array
    {
        (new WidgetPermissionTableSeeder())->run();

        $seeded = [];
        foreach (self::EXPECTED_PERMISSION_KEYS as $key) {
            [$group, $name] = explode('.', $key, 2);
            if (\Cms\Core\Models\Permission::query()->where('group', $group)->where('name', $name)->exists()) {
                $seeded[] = $key;
            }
        }

        if ($seeded !== self::EXPECTED_PERMISSION_KEYS) {
            throw new RuntimeException('WidgetPermissionTableSeeder did not seed the full frozen permission inventory.');
        }

        return $seeded;
    }

    private function systemActor(): User
    {
        $existing = User::query()->where('email', self::SYSTEM_ACTOR_EMAIL)->first();
        if ($existing !== null) {
            return $existing;
        }

        return User::forceCreate([
            'name' => 'Widget Site Layout Fixture System',
            'email' => self::SYSTEM_ACTOR_EMAIL,
            'password' => bcrypt(Str::random(40)),
            'type' => UserType::ADMIN,
        ]);
    }

    // -----------------------------------------------------------------
    // Widget / Theme / Manifest
    // -----------------------------------------------------------------

    private function findOrCreateWidget(): Widget
    {
        return Widget::query()->firstOrCreate(
            ['name' => self::WIDGET_NAME],
            ['description' => 'Deterministic fixture Widget for the Chunk 2 Task 24b conformance gate.', 'js' => '']
        );
    }

    private function findOrCreateTheme(Widget $widget, string $projectName): WidgetTheme
    {
        $theme = WidgetTheme::query()->firstOrCreate(
            ['widget_id' => $widget->id, 'name' => self::THEME_NAME],
            [
                'content' => '<div class="widget-site-layout-fixture">{title}</div>',
                'css' => '.widget-site-layout-fixture { color: inherit; }',
                'project_name' => [$projectName],
            ]
        );

        $applicability = is_array($theme->project_name) ? $theme->project_name : [];
        if (!in_array($projectName, $applicability, true)) {
            $theme->project_name = [...$applicability, $projectName];
            $theme->save();
        }

        return $theme->fresh();
    }

    private function liveSourceFingerprint(Widget $widget, WidgetTheme $theme): string
    {
        $fingerprint = (new LegacyWidgetExecutableSurfaceAnalyzer())->analyze(
            widgetJs: (string) $widget->js,
            themeContent: (string) $theme->content,
            themeCss: (string) $theme->css,
            canonicalSchema: (new CanonicalWidgetSchema())->resolve($widget->id, $theme->id),
            policyVersion: ApprovedLegacyWidgetRenderer::CURRENT_POLICY_VERSION,
            trustLane: ApprovedLegacyWidgetRenderer::CURRENT_TRUST_LANE,
            applicability: is_array($theme->project_name) ? $theme->project_name : ['Default'],
            artifactBytes: (new LegacyWidgetArtifactSetPublisher())->renderThemeContent($theme),
        );

        return $fingerprint->manifestHash;
    }

    private function materializeAndApprove(Widget $widget, WidgetTheme $theme, string $projectName, int $actorId): WidgetArtifactManifestModel
    {
        $result = $this->materializer->materialize(
            widgetId: $widget->id,
            themeId: $theme->id,
            projectName: $projectName,
            expectedSourceFingerprint: $this->liveSourceFingerprint($widget, $theme),
        );

        if (!in_array($result->status, [WidgetLegacyManifestMaterializationResult::STATUS_CREATED, WidgetLegacyManifestMaterializationResult::STATUS_REUSED], true)) {
            throw new RuntimeException("Manifest materialization returned unexpected status '{$result->status}'.");
        }

        $manifestHash = (string) $result->manifestRow['manifest_hash'];
        $manifest = WidgetArtifactManifestModel::query()
            ->where('project_name', $projectName)
            ->where('manifest_hash', $manifestHash)
            ->firstOrFail();

        if ((bool) $manifest->blocked) {
            $clear = $this->runtimeBlock->clear($projectName, $manifestHash, (int) $manifest->control_version, $actorId, 'widget-site-layout-fixture: restore clean unblocked state');
            if ($clear['outcome'] !== WidgetManifestRuntimeBlockService::OUTCOME_SUCCESS) {
                throw new RuntimeException("Could not clear a stale emergency block on the fixture Manifest (outcome '{$clear['outcome']}').");
            }
            $manifest = $manifest->fresh();
        }

        if ($manifest->eligibility_state !== WidgetManifestApprovalService::STATE_APPROVED) {
            $approve = $this->approvals->approve($projectName, $manifestHash, (int) $manifest->attestation_version, $actorId, 'widget-site-layout-fixture: ensure approved');
            if ($approve['outcome'] !== WidgetManifestApprovalService::OUTCOME_SUCCESS) {
                throw new RuntimeException("Could not approve the fixture Manifest (outcome '{$approve['outcome']}').");
            }
            $manifest = $manifest->fresh();
        }

        return $manifest;
    }

    // -----------------------------------------------------------------
    // Instance / Layout / Draft / Publish
    // -----------------------------------------------------------------

    private function findOrCreateInstance(string $projectName, Widget $widget, WidgetTheme $theme): WidgetInstance
    {
        $result = $this->instances->create($projectName, null, self::INSTANCE_IDEMPOTENCY_KEY, [
            'name' => self::INSTANCE_NAME,
            'widget_id' => $widget->id,
            'widget_theme_id' => $theme->id,
            'values' => ['title' => 'Widget Site Layout Fixture'],
            'provider_contract' => [],
        ]);

        $createdOrReplayed = [\Cms\Widget\Instances\WidgetInstanceMutationResult::STATUS_CREATED, \Cms\Widget\Instances\WidgetInstanceMutationResult::STATUS_REPLAYED];
        if (!in_array($result->status, $createdOrReplayed, true) || $result->instance === null) {
            throw new RuntimeException("Instance create/replay returned unexpected status '{$result->status}'.");
        }

        return $result->instance;
    }

    private function ensureDraftPlacement(string $projectName, WidgetLayout $layout, WidgetInstance $instance): WidgetLayoutVersion
    {
        $draft = WidgetLayoutVersion::query()->findOrFail($layout->current_draft_version_id);

        $existing = WidgetPlacement::query()->where('layout_version_id', $draft->id)->get();
        $alreadyCorrect = $existing->count() === 1
            && $existing->first()->slot_key === self::SLOT_KEY
            && (int) $existing->first()->widget_instance_version_id === (int) $instance->current_version_id;

        if ($alreadyCorrect) {
            return $draft;
        }

        $result = $this->drafts->save(
            $projectName,
            $layout->uuid,
            $draft->version,
            $draft->lock_version,
            [[
                'slot_key' => self::SLOT_KEY,
                'scope_type' => WidgetPlacement::SCOPE_GLOBAL,
                'scope_key' => null,
                'target_type' => null,
                'target_id' => null,
                'widget_instance_uuid' => $instance->uuid,
                'widget_instance_version' => $instance->currentVersion->version,
                'position' => 0,
                'enabled' => true,
                'rules' => [],
            ]],
        );

        if ($result->status !== WidgetLayoutDraftSaveResult::STATUS_SAVED || $result->draft === null) {
            throw new RuntimeException("Draft Save returned unexpected status '{$result->status}': " . ($result->message ?? ''));
        }

        return $result->draft;
    }

    private function publishLayout(string $projectName, WidgetLayout $layout, WidgetLayoutVersion $draft, int $actorId): WidgetLayout
    {
        $result = $this->publisher->publish(
            $projectName,
            $layout->uuid,
            $actorId,
            self::PUBLISH_IDEMPOTENCY_KEY,
            $draft->version,
            $draft->lock_version,
        );

        if ($result->status !== WidgetLayoutPublishResult::STATUS_PUBLISHED) {
            throw new RuntimeException("Publish returned unexpected status '{$result->status}'.");
        }

        return $layout->fresh();
    }

    // -----------------------------------------------------------------
    // Output
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $result
     */
    private function emit(array $result, string $format): void
    {
        if ($format === 'json') {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->info('Widget Site Layout fixture ready.');
        foreach ($result as $key => $value) {
            if (is_array($value)) {
                $rendered = implode(', ', $value);
            } elseif (is_bool($value)) {
                $rendered = $value ? 'true' : 'false';
            } else {
                $rendered = (string) $value;
            }

            $this->line("  {$key}: {$rendered}");
        }
    }
}
