<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Jobs\ApplyStarterPackJob;
use Cms\Core\Models\StarterPackApplication;
use Cms\Core\Services\StarterPacks\StarterPackApplier;
use Cms\Core\Services\StarterPacks\StarterPackLoader;
use Cms\Core\Services\StarterPacks\StarterPackManifest;
use Cms\Core\Services\StarterPacks\StarterPackPlan;
use Cms\Core\Services\StarterPacks\StarterPackPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/** Planner that reads nothing and reports the blockers the test sets. */
class SpApiPlanner extends StarterPackPlanner
{
    /** @var list<string> */
    public static array $blockers = [];

    public static ?int $userId = null;

    /** @var list<string> Irreversible changes of a module the plan would install (none: the module is enabled). */
    public static array $irreversible = [];

    /** @var array<string, mixed>|null Third-party details (publisher, impact) of a module the plan would install. */
    public static ?array $thirdParty = null;

    public function __construct()
    {
    }

    public function plan(StarterPackManifest $pack, ?int $actingUserId = null): StarterPackPlan
    {
        self::$userId = $actingUserId;
        $modules = [['name' => 'Core', 'constraint' => '*', 'version' => '1.0.0', 'state' => StarterPackPlan::STATE_ENABLED, 'irreversible' => []]];
        if (self::$irreversible !== []) {
            $modules[] = ['name' => 'Helpdesk', 'constraint' => '*', 'version' => '1.0.0', 'state' => StarterPackPlan::STATE_TO_INSTALL, 'irreversible' => self::$irreversible];
        }

        if (self::$thirdParty !== null) {
            $modules[] = ['name' => 'Gallery', 'constraint' => '*', 'version' => '1.0.0', 'state' => StarterPackPlan::STATE_TO_INSTALL, 'irreversible' => [], 'third_party' => self::$thirdParty];
        }

        return new StarterPackPlan($pack->key, $pack->version, $modules, null, null, null, [], self::$blockers, []);
    }
}

beforeEach(function (): void {
    SpApiPlanner::$blockers = [];
    SpApiPlanner::$userId = null;
    SpApiPlanner::$irreversible = [];
    SpApiPlanner::$thirdParty = null;
    app()->bind(StarterPackLoader::class, static fn (): StarterPackLoader => new StarterPackLoader(__DIR__ . '/../../../../../Fixtures/starter-packs'));
    app()->bind(StarterPackPlanner::class, static fn (): StarterPackPlanner => new SpApiPlanner());
});

/**
 * Sign in a non-webmaster admin holding exactly the given permission keys (no Webmaster bypass).
 *
 * @param array<int, string> $permissions
 */
function spApiSignIn(array $permissions, bool $webmaster = false): User
{
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'name' => 'Pack Admin', 'username' => 'sp' . Str::random(6),
        'email' => 'sp' . Str::random(8) . '@example.test', 'email_verified_at' => now(),
        'password' => Hash::make('secret123'), 'type' => 0, 'level' => 0, 'status' => null,
    ]);
    $actor = new class($permissions, $webmaster) extends User
    {
        /** @param array<int, string> $keys */
        public function __construct(private array $keys = [], private bool $webmaster = false)
        {
            parent::__construct();
        }

        public function permissionKeys(): array
        {
            return $this->keys;
        }

        public function loadMissing($relations)
        {
            return $this;
        }

        public function isWebmaster(): bool
        {
            return $this->webmaster;
        }
    };
    $actor->setRawAttributes($user->getAttributes(), true);
    $actor->exists = true;
    Sanctum::actingAs($actor);

    return $actor;
}

/** Base url of the starter pack API. */
function spApiUrl(string $suffix = ''): string
{
    return 'https://localhost/api/v2/admin/starter-packs' . $suffix;
}

it('rejects requests without login', function () {
    $this->getJson(spApiUrl())->assertStatus(401);
    $this->getJson(spApiUrl('/minimal'))->assertStatus(401);
    $this->getJson(spApiUrl('/minimal/history'))->assertStatus(401);
});

it('answers 403 to a user holding neither permission', function () {
    spApiSignIn(['custom_field.view', 'post.view']);

    $this->getJson(spApiUrl())->assertStatus(403);
    $this->getJson(spApiUrl('/minimal'))->assertStatus(403);
    $this->getJson(spApiUrl('/minimal/history'))->assertStatus(403);
});

it('lets a viewer without apply list and preview', function () {
    spApiSignIn(['starter_pack.view']);

    $list = $this->getJson(spApiUrl())->assertOk();
    $item = collect($list->json('data'))->firstWhere('key', 'minimal');
    expect($item)->not->toBeNull()
        ->and($item['version'])->toBe('1.0.0')
        ->and($item['modules'])->toBe(['Core'])
        ->and($item['applied'])->toBeFalse()
        ->and($item['applied_version'])->toBeNull();

    $show = $this->getJson(spApiUrl('/minimal'))->assertOk();
    expect($show->json('data.can_apply'))->toBeFalse()
        ->and($show->json('data.plan.pack_key'))->toBe('minimal');
    $this->getJson(spApiUrl('/minimal/history'))->assertOk()->assertJson(['data' => []]);
});

it('lets an applier read too and reports can_apply', function () {
    spApiSignIn(['starter_pack.apply']);

    $this->getJson(spApiUrl())->assertOk();
    $this->getJson(spApiUrl('/minimal'))->assertOk()->assertJsonPath('data.can_apply', true);
});

it('answers 404 to an unknown or malformed key', function () {
    spApiSignIn(['starter_pack.view']);

    $this->getJson(spApiUrl('/nope'))->assertStatus(404);
    $this->getJson(spApiUrl('/nope/history'))->assertStatus(404);
    $this->getJson(spApiUrl('/Bad_Key'))->assertStatus(404);
});

it('lists the blockers of the plan', function () {
    $actor = spApiSignIn(['starter_pack.view']);
    SpApiPlanner::$blockers = ['Production site already holds content.'];

    $plan = $this->getJson(spApiUrl('/minimal'))->assertOk()->json('data.plan');

    expect($plan['can_apply'])->toBeFalse()
        ->and($plan['blockers'])->toBe(['Production site already holds content.'])
        ->and(SpApiPlanner::$userId)->toBe((int) $actor->getAuthIdentifier());
});

it('returns pack text in the request locale', function () {
    spApiSignIn(['starter_pack.view']);

    $vi = $this->getJson(spApiUrl(), ['X-Locale' => 'vi'])->assertOk()->json('data.0');
    $en = $this->getJson(spApiUrl(), ['X-Locale' => 'en'])->assertOk()->json('data.0');
    $showVi = $this->getJson(spApiUrl('/minimal'), ['X-Locale' => 'vi'])->assertOk()->json('data');

    expect($vi['name'])->toBe('Gói tối thiểu')
        ->and($vi['description'])->toBe('Gói mẫu dùng cho kiểm thử.')
        ->and($en['name'])->toBe('Minimal pack')
        ->and($showVi['name'])->toBe('Gói tối thiểu');
});

it('shows the applied state and the history from the ledger', function () {
    spApiSignIn(['starter_pack.view']);
    StarterPackApplication::query()->create([
        'pack_key' => 'minimal', 'pack_version' => '0.9.0', 'status' => StarterPackApplication::STATUS_FAILED,
        'steps' => [], 'started_at' => now()->subDay(),
    ]);
    $ok = StarterPackApplication::query()->create([
        'pack_key' => 'minimal', 'pack_version' => '1.0.0', 'status' => StarterPackApplication::STATUS_SUCCEEDED,
        'steps' => [['name' => 'modules', 'status' => 'succeeded']], 'started_at' => now()->subHour(), 'finished_at' => now(),
    ]);
    StarterPackApplication::query()->create([
        'pack_key' => 'other', 'pack_version' => '1.0.0', 'status' => StarterPackApplication::STATUS_SUCCEEDED,
        'steps' => [], 'started_at' => now(),
    ]);

    $item = collect($this->getJson(spApiUrl())->assertOk()->json('data'))->firstWhere('key', 'minimal');
    expect($item['applied'])->toBeTrue()->and($item['applied_version'])->toBe('1.0.0');

    $rows = $this->getJson(spApiUrl('/minimal/history'))->assertOk()->json('data');
    expect($rows)->toHaveCount(2)
        ->and($rows[0]['uuid'])->toBe($ok->uuid)
        ->and($rows[0]['status'])->toBe('succeeded')
        ->and($rows[1]['status'])->toBe('failed')
        ->and($rows[0])->not->toHaveKey('id')->not->toHaveKey('applied_by');
});

it('seeds the two permissions with bilingual titles', function () {
    (new \Cms\Core\Database\Seeders\PermissionTableSeed())->run();

    $rows = \Cms\Core\Models\Permission::query()->where('group', 'starter_pack')->pluck('title', 'name')->all();
    expect($rows)->toHaveKeys(['view', 'apply'])
        ->and($rows['view'])->toBe('View starter packs | Xem gói khởi tạo')
        ->and($rows['apply'])->toBe('Apply starter packs | Áp dụng gói khởi tạo');
});

// ------------------------------------------------------------------ apply

/** Body of an apply request for the minimal fixture pack. */
function spApplyBody(array $overrides = []): array
{
    return $overrides + ['version' => '1.0.0'];
}

it('refuses an apply without login', function () {
    Queue::fake();

    $this->postJson(spApiUrl('/minimal/apply'), spApplyBody())->assertStatus(401);

    Queue::assertNothingPushed();
});

it('refuses an apply without starter_pack.apply, even to a viewer', function () {
    Queue::fake();
    spApiSignIn(['starter_pack.view', 'post.view']);

    $this->postJson(spApiUrl('/minimal/apply'), spApplyBody())->assertStatus(403);

    Queue::assertNothingPushed();
    expect(StarterPackApplication::query()->count())->toBe(0);
});

it('refuses an apply to a Webmaster who does not hold starter_pack.apply', function () {
    Queue::fake();
    spApiSignIn(['starter_pack.view'], webmaster: true);

    $this->postJson(spApiUrl('/minimal/apply'), spApplyBody())
        ->assertStatus(403)
        ->assertJsonPath('message', 'Forbidden');

    Queue::assertNothingPushed();
    expect(StarterPackApplication::query()->count())->toBe(0);
});

it('refuses a pack with a configuration to an applier who is not a Webmaster', function () {
    Queue::fake();
    spApiSignIn(['starter_pack.apply']);

    $this->postJson(spApiUrl('/minimal/apply'), spApplyBody())
        ->assertStatus(403)
        ->assertJsonPath('message', 'Only a Webmaster can apply the configuration of this pack.');

    Queue::assertNothingPushed();
    expect(StarterPackApplication::query()->count())->toBe(0);
});

it('answers 404 to an apply of an unknown pack', function () {
    Queue::fake();
    spApiSignIn(['starter_pack.apply'], webmaster: true);

    $this->postJson(spApiUrl('/nope/apply'), spApplyBody())->assertStatus(404);

    Queue::assertNothingPushed();
});

it('validates the apply body', function () {
    Queue::fake();
    spApiSignIn(['starter_pack.apply'], webmaster: true);

    $this->postJson(spApiUrl('/minimal/apply'), [])->assertStatus(422)->assertJsonValidationErrors(['version']);
    $this->postJson(spApiUrl('/minimal/apply'), spApplyBody(['content_status' => 'live']))
        ->assertStatus(422)->assertJsonValidationErrors(['content_status']);

    Queue::assertNothingPushed();
});

it('validates the irreversible confirmation flag', function () {
    Queue::fake();
    spApiSignIn(['starter_pack.apply'], webmaster: true);

    $this->postJson(spApiUrl('/minimal/apply'), spApplyBody(['confirm_irreversible' => 'maybe']))
        ->assertStatus(422)->assertJsonValidationErrors(['confirm_irreversible']);

    Queue::assertNothingPushed();
});

it('answers 409 when the reviewed version is not the pack version any more', function () {
    Queue::fake();
    spApiSignIn(['starter_pack.apply'], webmaster: true);

    $this->postJson(spApiUrl('/minimal/apply'), spApplyBody(['version' => '0.9.0']))
        ->assertStatus(409)
        ->assertJsonPath('pack_version', '1.0.0');

    Queue::assertNothingPushed();
    expect(StarterPackApplication::query()->count())->toBe(0);
});

it('answers 422 with the blockers of a blocked plan and dispatches nothing', function () {
    Queue::fake();
    spApiSignIn(['starter_pack.apply'], webmaster: true);
    SpApiPlanner::$blockers = ['Production site already holds content.'];

    $this->postJson(spApiUrl('/minimal/apply'), spApplyBody())
        ->assertStatus(422)
        ->assertJsonPath('blockers', ['Production site already holds content.']);

    Queue::assertNothingPushed();
    expect(StarterPackApplication::query()->count())->toBe(0);
});

it('needs a confirmation for modules whose database changes cannot be undone', function () {
    Queue::fake();
    spApiSignIn(['starter_pack.apply'], webmaster: true);
    SpApiPlanner::$irreversible = ['drops column posts.legacy'];

    $this->postJson(spApiUrl('/minimal/apply'), spApplyBody())
        ->assertStatus(422)
        ->assertJsonPath('irreversible', [['module' => 'Helpdesk', 'changes' => ['drops column posts.legacy']]]);
    Queue::assertNothingPushed();

    $this->postJson(spApiUrl('/minimal/apply'), spApplyBody(['confirm_irreversible' => true]))->assertStatus(202);

    Queue::assertPushed(ApplyStarterPackJob::class, static fn (ApplyStarterPackJob $job): bool => ($job->options[StarterPackApplier::OPTION_CONFIRM_IRREVERSIBLE] ?? false) === true);
});

it('needs a confirmation for a module published by a partner and passes it on', function () {
    Queue::fake();
    spApiSignIn(['starter_pack.apply'], webmaster: true);
    SpApiPlanner::$thirdParty = ['publisher' => ['key' => 'acme', 'type' => 'partner', 'name' => 'Acme Studio'], 'impact' => null];

    $this->getJson(spApiUrl('/minimal'))->assertOk()
        ->assertJsonPath('data.plan.modules.1.third_party.publisher.name', 'Acme Studio');

    $this->postJson(spApiUrl('/minimal/apply'), spApplyBody())
        ->assertStatus(422)
        ->assertJsonPath('third_party', [['module' => 'Gallery', 'publisher' => ['key' => 'acme', 'type' => 'partner', 'name' => 'Acme Studio'], 'impact' => null]]);
    Queue::assertNothingPushed();

    $this->postJson(spApiUrl('/minimal/apply'), spApplyBody(['confirm_third_party' => true]))->assertStatus(202);
    Queue::assertPushed(ApplyStarterPackJob::class, static fn (ApplyStarterPackJob $job): bool => ($job->options[StarterPackApplier::OPTION_CONFIRM_THIRD_PARTY] ?? false) === true
        && ! isset($job->options[StarterPackApplier::OPTION_CONFIRM_IRREVERSIBLE]));
});

it('validates the partner confirmation flag', function () {
    Queue::fake();
    spApiSignIn(['starter_pack.apply'], webmaster: true);

    $this->postJson(spApiUrl('/minimal/apply'), spApplyBody(['confirm_third_party' => 'maybe']))
        ->assertStatus(422)->assertJsonValidationErrors(['confirm_third_party']);
    Queue::assertNothingPushed();
});

it('asks nothing about partners for a pack of iSPA modules', function () {
    Queue::fake();
    spApiSignIn(['starter_pack.apply'], webmaster: true);

    $this->postJson(spApiUrl('/minimal/apply'), spApplyBody())->assertStatus(202);
    Queue::assertPushed(ApplyStarterPackJob::class, static fn (ApplyStarterPackJob $job): bool => ! isset($job->options[StarterPackApplier::OPTION_CONFIRM_THIRD_PARTY]));
});

it('answers 409 while another starter pack is being applied', function () {
    Queue::fake();
    spApiSignIn(['starter_pack.apply'], webmaster: true);
    StarterPackApplication::query()->create([
        'pack_key' => 'other', 'pack_version' => '1.0.0', 'status' => StarterPackApplication::STATUS_STARTED,
        'steps' => [], 'started_at' => now(),
    ]);

    $this->postJson(spApiUrl('/minimal/apply'), spApplyBody())->assertStatus(409);

    Queue::assertNothingPushed();
    expect(StarterPackApplication::query()->where('pack_key', 'minimal')->count())->toBe(0);
});

it('queues the apply, records the ledger row and the audit entry, and returns the task uuid', function () {
    Queue::fake();
    Event::fake([AuditLogEvent::class]);
    $actor = spApiSignIn(['starter_pack.apply'], webmaster: true);

    $response = $this->postJson(spApiUrl('/minimal/apply'), spApplyBody(['content_status' => 'published']))->assertStatus(202);

    $row = StarterPackApplication::query()->where('pack_key', 'minimal')->sole();
    expect($row->status)->toBe(StarterPackApplication::STATUS_STARTED)
        ->and($row->pack_version)->toBe('1.0.0')
        ->and($row->applied_by)->toBe((int) $actor->getAuthIdentifier())
        ->and($row->background_task_uuid)->not->toBeNull()
        ->and($response->json('data.task_uuid'))->toBe($row->background_task_uuid)
        ->and($response->json('data.application_uuid'))->toBe($row->uuid)
        ->and($response->json('data'))->not->toHaveKey('id');

    Queue::assertPushed(ApplyStarterPackJob::class, 1);
    Queue::assertPushed(ApplyStarterPackJob::class, static fn (ApplyStarterPackJob $job): bool => $job->applicationUuid === $row->uuid
        && ($job->options[StarterPackApplier::OPTION_CONTENT_STATUS] ?? null) === 'published'
        && ! isset($job->options[StarterPackApplier::OPTION_CONFIRM_IRREVERSIBLE]));

    Event::assertDispatched(AuditLogEvent::class, static fn (AuditLogEvent $e): bool => $e->action === 'starter_pack.apply_requested'
        && $e->changed['pack_key'] === 'minimal'
        && $e->changed['pack_version'] === '1.0.0'
        && $e->changed['application_uuid'] === $row->uuid
        && $e->user_id === (int) $actor->getAuthIdentifier());
});

it('throttles the apply route to two requests per ten minutes', function () {
    $route = Route::getRoutes()->getByName('api.v2.starter-packs.apply');

    expect($route)->not->toBeNull()
        ->and($route->methods())->toContain('POST')
        ->and($route->gatherMiddleware())->toContain('throttle.route:2,10');

    Queue::fake();
    spApiSignIn(['starter_pack.apply'], webmaster: true);
    $this->postJson(spApiUrl('/minimal/apply'), [])->assertStatus(422);
    $this->postJson(spApiUrl('/minimal/apply'), [])->assertStatus(422);
    $this->postJson(spApiUrl('/minimal/apply'), [])->assertStatus(429);
});
