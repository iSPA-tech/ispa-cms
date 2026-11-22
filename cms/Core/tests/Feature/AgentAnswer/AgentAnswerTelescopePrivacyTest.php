<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\EntryResult;
use Laravel\Telescope\Storage\EntryQueryOptions;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\Watchers\QueryWatcher;
use Laravel\Telescope\Watchers\RequestWatcher;

/*
 * Telescope keeps nothing from an agent answer request. The Request watcher would record the response (the
 * answer) and the Query watcher the interpolated search bindings (the question), so the whole batch is dropped.
 * Positive control: an ordinary route in the same setup does get its Request and Query entries stored.
 */

require_once __DIR__ . '/AgentAnswerFixture.php';

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

final class AgentAnswerTelescopeMemoryRepository implements EntriesRepository
{
    /** @var list<\Laravel\Telescope\IncomingEntry> */
    public array $stored = [];

    public function find($id): EntryResult
    {
        throw new RuntimeException('not used');
    }

    public function get($type, EntryQueryOptions $options)
    {
        return collect();
    }

    public function store(Collection $entries)
    {
        foreach ($entries as $entry) {
            $this->stored[] = $entry;
        }
    }

    public function update(Collection $updates)
    {
        return collect();
    }

    public function loadMonitoredTags()
    {
    }

    public function isMonitoring(array $tags)
    {
        return false;
    }

    public function monitoring()
    {
        return [];
    }

    public function monitor(array $tags)
    {
    }

    public function stopMonitoring(array $tags)
    {
    }
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-06T03:00:00Z');
    $this->provider = agentAnswerArrange();
    // The app filter keeps every entry in the local environment, as on a developer machine.
    $this->previousEnv = app()->environment();
    app()['env'] = 'local';
    // Telescope keeps filters in static arrays; drop those of earlier test applications and register this app's.
    Telescope::$filterUsing = [];
    Telescope::$filterBatchUsing = [];
    (new \App\Providers\TelescopeServiceProvider(app()))->register();

    $this->telescope = new AgentAnswerTelescopeMemoryRepository();
    app()->instance(EntriesRepository::class, $this->telescope);
    (new RequestWatcher(['size_limit' => 64]))->register(app());
    (new QueryWatcher(['slow' => 100]))->register(app());
    Telescope::flushEntries();
    Telescope::startRecording(false);

    Route::middleware('api')->get('/telescope-control-probe', static fn () => [
        'count' => DB::table('settings')->where('setting_key', 'telescope-control-canary')->count(),
    ]);
});

afterEach(function (): void {
    Telescope::stopRecording();
    Telescope::flushEntries();
    app()['env'] = $this->previousEnv;
    CarbonImmutable::setTestNow();
});

it('drops the whole Telescope batch of an agent answer request', function (): void {
    $question = 'Canaryq91 ABS là gì';
    agentAnswerRetitle('Canaryq91 hệ thống ABS là gì');
    Sanctum::actingAs(agentAnswerUser());

    $this->postJson(AGENT_ANSWER_URL, ['question' => $question])->assertOk()->assertJsonPath('data.status', 'answered');
    // Without the batch filter these queued entries would be stored: the question is in a query binding.
    $queued = json_encode(array_map(static fn ($entry) => $entry->content, agentAnswerTelescopeQueue()), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    Telescope::store($this->telescope);

    expect($queued)->toContain('Canaryq91')
        ->and($this->telescope->stored)->toBe([]);
});

it('drops the batch of a failed agent answer request too', function (): void {
    Sanctum::actingAs(agentAnswerUser());
    $this->provider->content = 'not-json';

    $this->postJson(AGENT_ANSWER_URL, ['question' => 'ABS là gì?'])->assertStatus(502);
    Telescope::store($this->telescope);

    expect($this->telescope->stored)->toBe([]);
});

it('still stores Request and Query entries of an ordinary route (positive control)', function (): void {
    $this->getJson('https://localhost/telescope-control-probe?probe=1')->assertOk();
    Telescope::store($this->telescope);

    $types = array_map(static fn ($entry): string => $entry->type, $this->telescope->stored);
    $queries = array_filter($this->telescope->stored, static fn ($entry): bool => $entry->type === 'query'
        && str_contains((string) ($entry->content['sql'] ?? ''), 'telescope-control-canary'));

    expect($types)->toContain('request')
        ->and($queries)->not->toBeEmpty();
});

/** @return array<int, mixed> */
function agentAnswerTelescopeQueue(): array
{
    return (new ReflectionProperty(Telescope::class, 'entriesQueue'))->getValue();
}
