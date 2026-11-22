<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Console;

use App\Contracts\Modules\ModuleDeploymentPreflight;
use App\Support\Modules\ModuleDeploymentPreflightRegistry;
use Cms\Widget\Constants\WidgetTableConstant;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\Rendering\DataProviders\CanonicalJson;
use Cms\Widget\Rendering\DataProviders\WidgetDataProviderRegistry;
use Cms\Widget\Rendering\DataProviders\WidgetProviderDeclaration;
use Cms\Widget\SiteLayout\Registry\WidgetRegistryCompatibilityChecker;
use Cms\Widget\SiteLayout\Registry\WidgetRegistryContractSnapshot;
use Cms\Widget\SiteLayout\Slots\WidgetSlotDeclaration;
use Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry;
use Cms\Widget\SiteLayout\Slots\WidgetTemplateContextRegistry;
use Cms\Widget\SiteLayout\Targets\WidgetTargetContextRegistry;
use Cms\Widget\Console\Commands\WidgetRegistryPreflightCommand;
use App\Contracts\Widget\DTO\WidgetTargetContextDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Chunk 2 Task 15: WidgetRegistryContractSnapshot + WidgetRegistryCompatibilityChecker
 * + WidgetRegistryPreflightCommand (plan lines 848-884, 871-874;
 * docs/architecture/adr-widget-provider-lifecycle.md decision 7 and 10).
 *
 * The plan's allowed_paths lists no separate test file for the snapshot or
 * checker classes, so all three production classes are proven together
 * here -- the plan's own design note frames the checker as "consumed by
 * both the preflight command and (later) publish validation", making the
 * command test the natural single vehicle for this task.
 *
 * Deployment-hook evidence (checklist: "prove the actual deployment
 * pipeline runs target-release preflight before switching traffic"): Task
 * 5a already proved `deployment/build-script/deployment/hooks/before-
 * activation.sh:439-444` runs `php artisan cms:deploy-preflight || exit 1`
 * pre-activation (docs/evidence/widget-site-layout-preflight.md:359-363;
 * freshly re-read and confirmed at those exact lines while implementing
 * this task). That sibling file lives OUTSIDE this repository (a template
 * only ever copied into per-project deploy repos -- "the ispa-cms source
 * repo itself has no .github/workflows"), so no test here reads it
 * directly (non-portable to a bare ispa-cms CI checkout; not an SSH
 * check). What THIS task adds and proves executably is the other half of
 * the chain: that `cms:deploy-preflight`, once invoked, now transitively
 * runs the Widget registry-compatibility check via
 * ModuleDeploymentPreflightRegistry -- see the "deployment pipeline
 * integration" section below.
 */
final class WidgetRegistryPreflightCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * See WidgetDatabaseLifecycleTest::beforeRefreshingDatabase() (Task
     * 13a) for the full explanation: the Widget module stays disabled by
     * design, so its migration path and the process-wide
     * RefreshDatabaseState::$migrated flag must be handled manually.
     */
    protected function beforeRefreshingDatabase(): void
    {
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;

        $this->app->afterResolving('migrator', static function ($migrator): void {
            $migrator->path(base_path('cms/Widget/database/migrations'));
        });
    }

    public static function tearDownAfterClass(): void
    {
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    private function bootWidgetServiceProvider(): void
    {
        $provider = new WidgetServiceProvider($this->app);
        $provider->register();
        $provider->boot();
    }

    // -----------------------------------------------------------------
    // Fixture helpers (mirrors WidgetDatabaseLifecycleTest's raw-DB style)
    // -----------------------------------------------------------------

    private function insertLayout(string $project = 'ExampleProject', string $key = 'site'): int
    {
        return DB::table(WidgetTableConstant::LAYOUT)->insertGetId([
            'uuid' => (string) Str::uuid(),
            'project_name' => $project,
            'key' => $key,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertLayoutVersion(int $layoutId, array $contracts, string $state = 'draft', string $project = 'ExampleProject'): int
    {
        return DB::table(WidgetTableConstant::LAYOUT_VERSION)->insertGetId([
            'layout_id' => $layoutId,
            'project_name' => $project,
            'version' => 1,
            'state' => $state,
            'lock_version' => 0,
            'registry_contracts' => json_encode($contracts),
            'registry_contract_hash' => CanonicalJson::hash($contracts),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ===================================================================
    // WidgetRegistryContractSnapshot
    // ===================================================================

    public function test_build_includes_a_registered_slots_semantic_fields_excluding_label_key(): void
    {
        $slots = new WidgetSlotRegistry();
        $slots->register(new WidgetSlotDeclaration(
            key: 'homepage.header',
            labelKey: 'widget_site_layout.slot.homepage_header',
            cardinality: 'stack',
            fallback: 'Fallback',
            trustLane: 'legacy',
            availability: ['site_shell'],
        ));

        $snapshot = new WidgetRegistryContractSnapshot($slots, new WidgetTemplateContextRegistry(), $this->emptyTargetContexts(), new WidgetDataProviderRegistry());
        $result = $snapshot->build(slotKeys: ['homepage.header']);

        $this->assertArrayHasKey('homepage.header', $result['contracts']['slots']);
        $entry = $result['contracts']['slots']['homepage.header'];
        $this->assertArrayNotHasKey('label_key', $entry);
        $this->assertSame('stack', $entry['cardinality']);
        $this->assertSame('Fallback', $entry['fallback']);
        $this->assertSame('legacy', $entry['trust_lane']);
        $this->assertSame(['site_shell'], $entry['availability']);
    }

    public function test_build_omits_a_requested_slot_key_that_is_not_currently_registered(): void
    {
        $snapshot = new WidgetRegistryContractSnapshot(new WidgetSlotRegistry(), new WidgetTemplateContextRegistry(), $this->emptyTargetContexts(), new WidgetDataProviderRegistry());

        $result = $snapshot->build(slotKeys: ['never.registered']);

        $this->assertArrayNotHasKey('never.registered', $result['contracts']['slots']);
    }

    public function test_build_hash_is_deterministic_regardless_of_reference_set_array_order(): void
    {
        $slots = new WidgetSlotRegistry();
        $slots->register(new WidgetSlotDeclaration(key: 'a', labelKey: 'k.a', cardinality: 'stack'));
        $slots->register(new WidgetSlotDeclaration(key: 'b', labelKey: 'k.b', cardinality: 'stack'));

        $snapshot = new WidgetRegistryContractSnapshot($slots, new WidgetTemplateContextRegistry(), $this->emptyTargetContexts(), new WidgetDataProviderRegistry());

        $forward = $snapshot->build(slotKeys: ['a', 'b']);
        $reversed = $snapshot->build(slotKeys: ['b', 'a']);

        $this->assertSame($forward['hash'], $reversed['hash']);
    }

    public function test_build_hash_is_unchanged_when_only_label_key_differs_across_two_registries(): void
    {
        $slotsA = new WidgetSlotRegistry();
        $slotsA->register(new WidgetSlotDeclaration(key: 'homepage.header', labelKey: 'label.a', cardinality: 'stack', fallback: 'F'));
        $snapshotA = new WidgetRegistryContractSnapshot($slotsA, new WidgetTemplateContextRegistry(), $this->emptyTargetContexts(), new WidgetDataProviderRegistry());

        $slotsB = new WidgetSlotRegistry();
        $slotsB->register(new WidgetSlotDeclaration(key: 'homepage.header', labelKey: 'label.b', cardinality: 'stack', fallback: 'F'));
        $snapshotB = new WidgetRegistryContractSnapshot($slotsB, new WidgetTemplateContextRegistry(), $this->emptyTargetContexts(), new WidgetDataProviderRegistry());

        $this->assertSame(
            $snapshotA->build(slotKeys: ['homepage.header'])['hash'],
            $snapshotB->build(slotKeys: ['homepage.header'])['hash'],
            'label_key-only differences must never change the registry_contract_hash.'
        );
    }

    public function test_build_hash_changes_when_a_semantic_field_differs_across_two_registries(): void
    {
        $slotsA = new WidgetSlotRegistry();
        $slotsA->register(new WidgetSlotDeclaration(key: 'homepage.header', labelKey: 'label.a', cardinality: 'stack'));
        $snapshotA = new WidgetRegistryContractSnapshot($slotsA, new WidgetTemplateContextRegistry(), $this->emptyTargetContexts(), new WidgetDataProviderRegistry());

        $slotsB = new WidgetSlotRegistry();
        $slotsB->register(new WidgetSlotDeclaration(key: 'homepage.header', labelKey: 'label.a', cardinality: 'single'));
        $snapshotB = new WidgetRegistryContractSnapshot($slotsB, new WidgetTemplateContextRegistry(), $this->emptyTargetContexts(), new WidgetDataProviderRegistry());

        $this->assertNotSame(
            $snapshotA->build(slotKeys: ['homepage.header'])['hash'],
            $snapshotB->build(slotKeys: ['homepage.header'])['hash']
        );
    }

    public function test_build_includes_a_registered_template_contexts_sorted_slot_key_set(): void
    {
        $templateContexts = new WidgetTemplateContextRegistry();
        $templateContexts->registerTemplateContext('site_shell', ['b', 'a']);

        $snapshot = new WidgetRegistryContractSnapshot(new WidgetSlotRegistry(), $templateContexts, $this->emptyTargetContexts(), new WidgetDataProviderRegistry());
        $result = $snapshot->build(templateContextKeys: ['site_shell']);

        $this->assertSame(['a', 'b'], $result['contracts']['template_contexts']['site_shell']);
    }

    public function test_build_includes_a_registered_target_contexts_routing_excluding_label_key(): void
    {
        $templateContexts = new WidgetTemplateContextRegistry();
        $templateContexts->registerTemplateContext('page_static', []);

        $targetContexts = new WidgetTargetContextRegistry($templateContexts, static fn (string $fqcn) => throw new \RuntimeException('not resolved in this test'));
        $targetContexts->registerTargetContext(new WidgetTargetContextDefinition(
            targetType: 'page.static',
            labelKey: 'widget_site_layout.template_context.page_static',
            templateContextKey: 'page_static',
            resolverService: 'App\\Fake\\PageResolver',
        ));

        $snapshot = new WidgetRegistryContractSnapshot(new WidgetSlotRegistry(), $templateContexts, $targetContexts, new WidgetDataProviderRegistry());
        $result = $snapshot->build(targetContextTypes: ['page.static']);

        $entry = $result['contracts']['target_contexts']['page.static'];
        $this->assertArrayNotHasKey('label_key', $entry);
        $this->assertSame('page_static', $entry['template_context_key']);
        $this->assertSame('App\\Fake\\PageResolver', $entry['resolver_service']);
    }

    public function test_build_includes_a_registered_providers_contract_hash(): void
    {
        $providers = new WidgetDataProviderRegistry();
        $providers->register(
            new class implements \App\Contracts\Widget\WidgetDataProvider {
                public function key(): string { return 'product.latest'; }
                public function version(): int { return 1; }
                public function fetch(array $arguments, \App\Contracts\Widget\DTO\WidgetRenderContext $context): \App\Contracts\Widget\DTO\WidgetProviderResult
                {
                    return new \App\Contracts\Widget\DTO\WidgetProviderResult(items: [], ok: true);
                }
            },
            new WidgetProviderDeclaration(key: 'product.latest', version: 1),
        );

        $snapshot = new WidgetRegistryContractSnapshot(new WidgetSlotRegistry(), new WidgetTemplateContextRegistry(), $this->emptyTargetContexts(), $providers);
        $result = $snapshot->build(providerRefs: [['key' => 'product.latest', 'version' => 1]]);

        $this->assertSame(
            $providers->contractHashFor('product.latest', 1),
            $result['contracts']['providers']['product.latest@1']
        );
    }

    private function emptyTargetContexts(): WidgetTargetContextRegistry
    {
        return new WidgetTargetContextRegistry(new WidgetTemplateContextRegistry(), static fn (string $fqcn) => throw new \RuntimeException('not resolved in this test'));
    }

    // ===================================================================
    // WidgetRegistryCompatibilityChecker
    // ===================================================================

    public function test_compare_returns_no_issues_when_stored_and_current_match_exactly(): void
    {
        $slots = new WidgetSlotRegistry();
        $slots->register(new WidgetSlotDeclaration(key: 'homepage.header', labelKey: 'k', cardinality: 'stack'));
        $snapshot = new WidgetRegistryContractSnapshot($slots, new WidgetTemplateContextRegistry(), $this->emptyTargetContexts(), new WidgetDataProviderRegistry());
        $stored = $snapshot->build(slotKeys: ['homepage.header'])['contracts'];

        $checker = new WidgetRegistryCompatibilityChecker($slots, new WidgetTemplateContextRegistry(), $this->emptyTargetContexts(), new WidgetDataProviderRegistry());

        $this->assertSame([], $checker->compare($stored));
    }

    public function test_compare_returns_no_issues_when_only_label_key_differs(): void
    {
        $slotsAtSnapshotTime = new WidgetSlotRegistry();
        $slotsAtSnapshotTime->register(new WidgetSlotDeclaration(key: 'homepage.header', labelKey: 'label.old', cardinality: 'stack'));
        $snapshot = new WidgetRegistryContractSnapshot($slotsAtSnapshotTime, new WidgetTemplateContextRegistry(), $this->emptyTargetContexts(), new WidgetDataProviderRegistry());
        $stored = $snapshot->build(slotKeys: ['homepage.header'])['contracts'];

        $currentSlots = new WidgetSlotRegistry();
        $currentSlots->register(new WidgetSlotDeclaration(key: 'homepage.header', labelKey: 'label.new', cardinality: 'stack'));
        $checker = new WidgetRegistryCompatibilityChecker($currentSlots, new WidgetTemplateContextRegistry(), $this->emptyTargetContexts(), new WidgetDataProviderRegistry());

        $this->assertSame([], $checker->compare($stored), 'A label_key-only change must be reported as compatible.');
    }

    public function test_compare_reports_a_missing_slot(): void
    {
        $slots = new WidgetSlotRegistry();
        $slots->register(new WidgetSlotDeclaration(key: 'homepage.header', labelKey: 'k', cardinality: 'stack'));
        $snapshot = new WidgetRegistryContractSnapshot($slots, new WidgetTemplateContextRegistry(), $this->emptyTargetContexts(), new WidgetDataProviderRegistry());
        $stored = $snapshot->build(slotKeys: ['homepage.header'])['contracts'];

        // Current registry never registers it -- source removed.
        $checker = new WidgetRegistryCompatibilityChecker(new WidgetSlotRegistry(), new WidgetTemplateContextRegistry(), $this->emptyTargetContexts(), new WidgetDataProviderRegistry());

        $issues = $checker->compare($stored);
        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('homepage.header', $issues[0]);
    }

    public function test_compare_reports_a_slot_cardinality_change_naming_the_field_and_both_values(): void
    {
        $stored = $this->slotSnapshotContracts('stack');
        $currentSlots = new WidgetSlotRegistry();
        $currentSlots->register(new WidgetSlotDeclaration(key: 'homepage.header', labelKey: 'k', cardinality: 'single'));
        $checker = new WidgetRegistryCompatibilityChecker($currentSlots, new WidgetTemplateContextRegistry(), $this->emptyTargetContexts(), new WidgetDataProviderRegistry());

        $issues = $checker->compare($stored);

        $this->assertNotEmpty($issues);
        // The breach line must name WHICH semantic field moved and show
        // stored vs current values, not just say "something changed".
        $this->assertStringContainsString('cardinality', $issues[0]);
        $this->assertStringContainsString('"stack"', $issues[0]);
        $this->assertStringContainsString('"single"', $issues[0]);
    }

    private function slotSnapshotContracts(string $cardinality): array
    {
        $slots = new WidgetSlotRegistry();
        $slots->register(new WidgetSlotDeclaration(key: 'homepage.header', labelKey: 'k', cardinality: $cardinality));
        $snapshot = new WidgetRegistryContractSnapshot($slots, new WidgetTemplateContextRegistry(), $this->emptyTargetContexts(), new WidgetDataProviderRegistry());

        return $snapshot->build(slotKeys: ['homepage.header'])['contracts'];
    }

    public function test_compare_reports_a_template_context_slot_set_change(): void
    {
        $templateContextsAtSnapshotTime = new WidgetTemplateContextRegistry();
        $templateContextsAtSnapshotTime->registerTemplateContext('site_shell', ['a', 'b']);
        $snapshot = new WidgetRegistryContractSnapshot(new WidgetSlotRegistry(), $templateContextsAtSnapshotTime, $this->emptyTargetContexts(), new WidgetDataProviderRegistry());
        $stored = $snapshot->build(templateContextKeys: ['site_shell'])['contracts'];

        $currentTemplateContexts = new WidgetTemplateContextRegistry();
        $currentTemplateContexts->registerTemplateContext('site_shell', ['a']); // 'b' removed
        $checker = new WidgetRegistryCompatibilityChecker(new WidgetSlotRegistry(), $currentTemplateContexts, $this->emptyTargetContexts(), new WidgetDataProviderRegistry());

        $issues = $checker->compare($stored);
        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('site_shell', $issues[0]);
        // The breach line must name the exact removed/added slot keys.
        $this->assertStringContainsString('removed: b', $issues[0]);
    }

    public function test_compare_reports_a_target_context_routing_change(): void
    {
        $templateContexts = new WidgetTemplateContextRegistry();
        $templateContexts->registerTemplateContext('page_static', []);

        $targetContextsAtSnapshotTime = new WidgetTargetContextRegistry($templateContexts, static fn (string $fqcn) => throw new \RuntimeException('n/a'));
        $targetContextsAtSnapshotTime->registerTargetContext(new WidgetTargetContextDefinition('page.static', 'k', 'page_static', 'App\\Old\\Resolver'));
        $snapshot = new WidgetRegistryContractSnapshot(new WidgetSlotRegistry(), $templateContexts, $targetContextsAtSnapshotTime, new WidgetDataProviderRegistry());
        $stored = $snapshot->build(targetContextTypes: ['page.static'])['contracts'];

        $currentTargetContexts = new WidgetTargetContextRegistry($templateContexts, static fn (string $fqcn) => throw new \RuntimeException('n/a'));
        $currentTargetContexts->registerTargetContext(new WidgetTargetContextDefinition('page.static', 'k', 'page_static', 'App\\New\\Resolver'));
        $checker = new WidgetRegistryCompatibilityChecker(new WidgetSlotRegistry(), $templateContexts, $currentTargetContexts, new WidgetDataProviderRegistry());

        $issues = $checker->compare($stored);
        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('page.static', $issues[0]);
        // The breach line must name WHICH routing half changed and show
        // stored vs current values (values render json-encoded, so build
        // the expected fragments the same way the checker does).
        $this->assertStringContainsString('resolver_service', $issues[0]);
        $this->assertStringContainsString(json_encode('App\\Old\\Resolver', JSON_UNESCAPED_SLASHES), $issues[0]);
        $this->assertStringContainsString(json_encode('App\\New\\Resolver', JSON_UNESCAPED_SLASHES), $issues[0]);
        $this->assertStringNotContainsString('template_context_key', $issues[0], 'The unchanged routing half must not be reported.');
    }

    public function test_compare_reports_a_malformed_provider_identity_instead_of_crashing(): void
    {
        // A retained row whose stored provider identity lacks the
        // "key@version" separator is corrupt data; the checker must emit a
        // named breach line for it, never throw an undefined-array-key
        // error inside a deployment gate.
        $checker = new WidgetRegistryCompatibilityChecker(new WidgetSlotRegistry(), new WidgetTemplateContextRegistry(), $this->emptyTargetContexts(), new WidgetDataProviderRegistry());

        $issues = $checker->compare(['providers' => ['corrupt-no-at-sign' => str_repeat('a', 64)]]);

        $this->assertNotEmpty($issues);
        $this->assertStringContainsString("malformed provider identity 'corrupt-no-at-sign'", $issues[0]);
    }

    public function test_compare_reports_a_provider_contract_hash_drift(): void
    {
        $providersAtSnapshotTime = new WidgetDataProviderRegistry();
        $providersAtSnapshotTime->register($this->fakeProvider('product.latest', 1), new WidgetProviderDeclaration(
            key: 'product.latest',
            version: 1,
            argumentSchema: ['limit' => ['type' => 'int']],
        ));
        $snapshot = new WidgetRegistryContractSnapshot(new WidgetSlotRegistry(), new WidgetTemplateContextRegistry(), $this->emptyTargetContexts(), $providersAtSnapshotTime);
        $stored = $snapshot->build(providerRefs: [['key' => 'product.latest', 'version' => 1]])['contracts'];

        // Same key/version, silently changed argument schema -- exactly the
        // undeclared-semantic-change-without-a-version-bump scenario
        // WidgetDataProviderRegistryTest already proves changes the hash.
        $currentProviders = new WidgetDataProviderRegistry();
        $currentProviders->register($this->fakeProvider('product.latest', 1), new WidgetProviderDeclaration(
            key: 'product.latest',
            version: 1,
            argumentSchema: ['limit' => ['type' => 'int'], 'category_id' => ['type' => 'int', 'required' => true]],
        ));
        $checker = new WidgetRegistryCompatibilityChecker(new WidgetSlotRegistry(), new WidgetTemplateContextRegistry(), $this->emptyTargetContexts(), $currentProviders);

        $issues = $checker->compare($stored);
        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('product.latest', $issues[0]);
    }

    public function test_compare_reports_a_provider_no_longer_registered(): void
    {
        $providersAtSnapshotTime = new WidgetDataProviderRegistry();
        $providersAtSnapshotTime->register($this->fakeProvider('product.latest', 1), new WidgetProviderDeclaration(key: 'product.latest', version: 1));
        $snapshot = new WidgetRegistryContractSnapshot(new WidgetSlotRegistry(), new WidgetTemplateContextRegistry(), $this->emptyTargetContexts(), $providersAtSnapshotTime);
        $stored = $snapshot->build(providerRefs: [['key' => 'product.latest', 'version' => 1]])['contracts'];

        $checker = new WidgetRegistryCompatibilityChecker(new WidgetSlotRegistry(), new WidgetTemplateContextRegistry(), $this->emptyTargetContexts(), new WidgetDataProviderRegistry());

        $this->assertNotEmpty($checker->compare($stored));
    }

    private function fakeProvider(string $key, int $version): \App\Contracts\Widget\WidgetDataProvider
    {
        return new class($key, $version) implements \App\Contracts\Widget\WidgetDataProvider {
            public function __construct(private readonly string $k, private readonly int $v) {}
            public function key(): string { return $this->k; }
            public function version(): int { return $this->v; }
            public function fetch(array $arguments, \App\Contracts\Widget\DTO\WidgetRenderContext $context): \App\Contracts\Widget\DTO\WidgetProviderResult
            {
                return new \App\Contracts\Widget\DTO\WidgetProviderResult(items: [], ok: true);
            }
        };
    }

    // ===================================================================
    // D-047: cumulative registerCommands() discovery. Deliberately placed
    // (and run, per PHPUnit source order) BEFORE every command-behavior
    // section below -- the plan's own wording: "Its test first asserts
    // discovery through Artisan::all()/artisan list --raw".
    // ===================================================================

    public function test_registry_preflight_command_is_discoverable_through_artisan_all(): void
    {
        $this->bootWidgetServiceProvider();

        $this->assertArrayHasKey('cms:widget-registry-preflight', Artisan::all());
    }

    // ===================================================================
    // WidgetRegistryPreflightCommand -- direct invocation
    // ===================================================================

    public function test_command_is_a_module_deployment_preflight(): void
    {
        $this->bootWidgetServiceProvider();

        $command = app(WidgetRegistryPreflightCommand::class);

        $this->assertInstanceOf(ModuleDeploymentPreflight::class, $command);
        $this->assertNotSame('', $command->name());
    }

    public function test_handle_passes_vacuously_with_an_explicit_message_when_no_rows_are_retained(): void
    {
        $this->bootWidgetServiceProvider();

        $exitCode = Artisan::call('cms:widget-registry-preflight');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('vacuous', strtolower($output));
    }

    public function test_handle_passes_when_every_retained_row_is_compatible(): void
    {
        $this->bootWidgetServiceProvider();
        app(WidgetSlotRegistry::class)->register(new WidgetSlotDeclaration(key: 'homepage.header', labelKey: 'k', cardinality: 'stack'));

        $layoutId = $this->insertLayout();
        $contracts = app(WidgetRegistryContractSnapshot::class)->build(slotKeys: ['homepage.header'])['contracts'];
        $this->insertLayoutVersion($layoutId, $contracts, 'draft');

        $exitCode = Artisan::call('cms:widget-registry-preflight');

        $this->assertSame(0, $exitCode);
    }

    public function test_handle_fails_when_a_retained_row_has_a_breaking_drift(): void
    {
        $this->bootWidgetServiceProvider();
        app(WidgetSlotRegistry::class)->register(new WidgetSlotDeclaration(key: 'homepage.header', labelKey: 'k', cardinality: 'stack'));

        $layoutId = $this->insertLayout();
        $contracts = app(WidgetRegistryContractSnapshot::class)->build(slotKeys: ['homepage.header'])['contracts'];
        $versionId = $this->insertLayoutVersion($layoutId, $contracts, 'release');

        // Simulate "source removed after the Release was cut": overwrite the
        // STORED snapshot with a contracts payload referencing a slot key
        // that is not registered in this process. The registry deliberately
        // exposes no deregistration API, so drift cannot be created by
        // mutating the live registry after registration -- rewriting the
        // stored row is the realistic equivalent anyway: in production the
        // retained row is immutable while CODE registrations change across
        // deploys, which is exactly the divergence preflight must catch.
        DB::table(WidgetTableConstant::LAYOUT_VERSION)->where('id', $versionId)->update([
            'registry_contracts' => json_encode(['slots' => ['gone.slot' => ['cardinality' => 'stack', 'supported_scope_types' => [], 'fallback' => null, 'trust_lane' => 'legacy', 'availability' => []]], 'template_contexts' => [], 'target_contexts' => [], 'providers' => []]),
        ]);

        $exitCode = Artisan::call('cms:widget-registry-preflight');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString((string) $versionId, $output);
        $this->assertStringContainsString('gone.slot', $output);
    }

    public function test_superseded_rows_are_never_queried(): void
    {
        $this->bootWidgetServiceProvider();

        $layoutId = $this->insertLayout();
        $this->insertLayoutVersion($layoutId, [
            'slots' => ['gone.slot' => ['cardinality' => 'stack', 'supported_scope_types' => [], 'fallback' => null, 'trust_lane' => 'legacy', 'availability' => []]],
            'template_contexts' => [], 'target_contexts' => [], 'providers' => [],
        ], 'superseded');

        $exitCode = Artisan::call('cms:widget-registry-preflight');

        $this->assertSame(0, $exitCode, 'A superseded row must never be part of the retained Draft/Release preflight scan.');
    }

    // ===================================================================
    // ModuleDeploymentPreflightRegistry integration (Task 7 root registry)
    // ===================================================================

    public function test_boot_registers_the_check_into_the_root_module_deployment_preflight_registry(): void
    {
        $this->bootWidgetServiceProvider();
        app(WidgetSlotRegistry::class)->register(new WidgetSlotDeclaration(key: 'homepage.header', labelKey: 'k', cardinality: 'stack'));

        $layoutId = $this->insertLayout();
        $versionId = $this->insertLayoutVersion($layoutId, [
            'slots' => ['gone.slot' => ['cardinality' => 'stack', 'supported_scope_types' => [], 'fallback' => null, 'trust_lane' => 'legacy', 'availability' => []]],
            'template_contexts' => [], 'target_contexts' => [], 'providers' => [],
        ], 'release');

        $failures = app(ModuleDeploymentPreflightRegistry::class)->run();

        $command = app(WidgetRegistryPreflightCommand::class);
        $this->assertArrayHasKey($command->name(), $failures);
        $this->assertStringContainsString((string) $versionId, implode(' ', $failures[$command->name()]));
    }

    public function test_boot_registers_a_check_that_reports_no_failures_when_compatible(): void
    {
        $this->bootWidgetServiceProvider();

        $failures = app(ModuleDeploymentPreflightRegistry::class)->run();

        $command = app(WidgetRegistryPreflightCommand::class);
        $this->assertArrayNotHasKey($command->name(), $failures);
    }

    // ===================================================================
    // Deployment pipeline integration: cms:deploy-preflight transitively
    // runs the Widget registry check (the "other half" of the chain --
    // see class docblock).
    // ===================================================================

    public function test_deploy_preflight_command_surfaces_a_widget_registry_breaking_drift(): void
    {
        $this->bootWidgetServiceProvider();

        $layoutId = $this->insertLayout();
        $versionId = $this->insertLayoutVersion($layoutId, [
            'slots' => ['gone.slot' => ['cardinality' => 'stack', 'supported_scope_types' => [], 'fallback' => null, 'trust_lane' => 'legacy', 'availability' => []]],
            'template_contexts' => [], 'target_contexts' => [], 'providers' => [],
        ], 'release');

        Artisan::call('cms:deploy-preflight');
        $output = Artisan::output();

        $this->assertStringContainsString('widget-registry', $output);
        $this->assertStringContainsString((string) $versionId, $output);
    }

    public function test_deploy_preflight_command_stays_silent_on_widget_registry_when_nothing_is_retained(): void
    {
        $this->bootWidgetServiceProvider();

        Artisan::call('cms:deploy-preflight');
        $output = Artisan::output();

        $this->assertStringNotContainsString('widget-registry', $output);
    }
}
