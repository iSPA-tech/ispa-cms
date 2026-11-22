<?php

declare(strict_types=1);

use Cms\Core\Agents\Runtime\AgentRunner;
use Cms\Core\Agents\Support\AgentState;
use Cms\Core\Agents\Tools\AgentToolRegistry;
use Cms\Core\Services\Agentic\AgentToolProvisioner;
use Cms\Core\Services\Agentic\EphemeralAgentToolException;
use Cms\Core\Services\Agentic\EphemeralAgentToolExecutor;
use Cms\Core\Tests\Fixtures\Agents\EphemeralToolFixtureService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Nwidart\Modules\Contracts\ActivatorInterface;

/*
 * (pilot Task 4): the ephemeral executor runs one granted, read-only, policy-authorized tool with an
 * in-memory AgentState (runId 0) and writes nothing to any Agentic or workflow table.
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

const EPHEMERAL_PROFILE = 'ephemeral-fixture-profile';

beforeEach(function (): void {
    config()->set('core.agentic.kill_switch', false);
    config()->set('core.agentic.discovery.allowlisted_namespaces', [
        'Cms\\Core\\Services\\',
        'Cms\\Core\\Tests\\Fixtures\\Agents\\',
    ]);
    config()->set('core.agentic.discovery.default_environment', 'testing');
    config()->set('core.agentic.bootstrap_services', [
        ['class' => EphemeralToolFixtureService::class, 'module_scope' => 'fixture'],
        ['class' => 'Cms\\Core\\Services\\Agentic\\DoesNotExistToolService', 'module_scope' => 'fixture'],
    ]);
    EphemeralToolFixtureService::$calls = 0;
    ephemeralModuleActivator(enabled: true);

    $registry = app(AgentToolRegistry::class);
    $registry->syncDiscoveredService(EphemeralToolFixtureService::class, 'fixture', 'testing');
    $provisioner = app(AgentToolProvisioner::class);
    $provisioner->createProfile([
        'name' => 'Ephemeral fixture',
        'slug' => EPHEMERAL_PROFILE,
        'capability_class' => 'class_2_staged_mutation',
        'status' => 'active',
    ]);
    $provisioner->grantToolsToProfile(EPHEMERAL_PROFILE, [
        'fixture.echo', 'fixture.scalar', 'fixture.write', 'fixture.approval', 'fixture.fail', 'fixture.limit',
    ]);
});

it('runs a granted read-only tool and writes nothing to any agentic or workflow table', function (): void {
    $before = ephemeralRuntimeTableCounts();
    $queries = [];
    DB::listen(static function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $output = app(EphemeralAgentToolExecutor::class)->execute(
        EPHEMERAL_PROFILE,
        'fixture.echo',
        ['text' => 'ab', 'times' => 2],
        'Content',
    );

    expect($output)->toBe(['text' => 'abab', 'call' => 1])
        ->and(ephemeralRuntimeTableCounts())->toBe($before)
        ->and($before)->toHaveKeys(['agent_runs', 'agent_run_steps', 'agent_tool_calls', 'agent_artifacts', 'workflow_instances'])
        ->and(array_filter($queries, static fn (string $sql): bool => ! str_starts_with(ltrim($sql), 'select')))->toBe([]);
});

it('wraps a non-array result', function (): void {
    expect(app(EphemeralAgentToolExecutor::class)->execute(EPHEMERAL_PROFILE, 'fixture.scalar', [], 'Content'))
        ->toBe(['result' => 'plain']);
});

it('runs twice in one process without growing the registry', function (): void {
    $executor = app(EphemeralAgentToolExecutor::class);
    $registry = app(AgentToolRegistry::class);

    $first = $executor->execute(EPHEMERAL_PROFILE, 'fixture.echo', ['text' => 'x'], 'Content');
    $bindings = count($registry->bindingsFor('fixture.echo'));
    $second = $executor->execute(EPHEMERAL_PROFILE, 'fixture.echo', ['text' => 'y'], 'Content');

    expect($first)->toBe(['text' => 'x', 'call' => 1])
        ->and($second)->toBe(['text' => 'y', 'call' => 2])
        ->and(count($registry->bindingsFor('fixture.echo')))->toBe($bindings)
        ->and($bindings)->toBe(1);
});

it('runs the tool under its own time limit and restores the previous one', function (): void {
    set_time_limit(0);

    $output = app(EphemeralAgentToolExecutor::class)->execute(EPHEMERAL_PROFILE, 'fixture.limit', [], 'Content');

    expect($output)->toBe(['limit' => 7])
        ->and((int) ini_get('max_execution_time'))->toBe(0);
});

it('refuses before running the tool and writes nothing', function (Closure $arrange, string $tool, array $input, string $reason): void {
    $arrange();
    $before = ephemeralRuntimeTableCounts();

    try {
        app(EphemeralAgentToolExecutor::class)->execute(EPHEMERAL_PROFILE, $tool, $input, 'Content');
        $this->fail('Expected the executor to refuse.');
    } catch (EphemeralAgentToolException $exception) {
        expect($exception->reason)->toBe($reason);
    }

    expect(EphemeralToolFixtureService::$calls)->toBe(0)
        ->and(ephemeralRuntimeTableCounts())->toBe($before);
})->with([
    'required module inactive' => [fn () => ephemeralModuleActivator(enabled: false), 'fixture.echo', ['text' => 'a'], 'module_unavailable'],
    'profile inactive' => [fn () => DB::table('agent_profiles')->where('slug', EPHEMERAL_PROFILE)->update(['status' => 'disabled']), 'fixture.echo', ['text' => 'a'], 'profile_unavailable'],
    'tool not granted' => [fn () => null, 'fixture.unknown', [], 'tool_not_granted'],
    'grant disabled' => [fn () => DB::table('agent_profile_tools')->update(['enabled' => false]), 'fixture.echo', ['text' => 'a'], 'tool_not_granted'],
    'kill switch' => [fn () => config()->set('core.agentic.kill_switch', true), 'fixture.echo', ['text' => 'a'], 'not_authorized'],
    'non read-only tool' => [fn () => null, 'fixture.write', ['text' => 'a'], 'tool_not_eligible'],
    'approval required' => [fn () => null, 'fixture.approval', [], 'not_authorized'],
    'binding missing' => [fn () => config()->set([
        'core.agentic.bootstrap_services' => [],
        'core.agentic.discovery.default_environment' => 'elsewhere',
    ]), 'fixture.echo', ['text' => 'a'], 'binding_unavailable'],
    'unknown argument' => [fn () => null, 'fixture.echo', ['text' => 'a', 'profile' => 'admin'], 'invalid_input'],
    'positional argument' => [fn () => null, 'fixture.echo', ['a'], 'invalid_input'],
    'nested deeper than six levels' => [fn () => null, 'fixture.echo', ['text' => [[[[[['deep']]]]]]], 'invalid_input'],
    'object value' => [fn () => null, 'fixture.echo', ['text' => new stdClass()], 'invalid_input'],
    'payload over 16 KiB' => [fn () => null, 'fixture.echo', ['text' => str_repeat('a', 16 * 1024)], 'invalid_input'],
]);

it('maps a tool failure to a generic error without the tool message', function (): void {
    $before = ephemeralRuntimeTableCounts();

    try {
        app(EphemeralAgentToolExecutor::class)->execute(EPHEMERAL_PROFILE, 'fixture.fail', [], 'Content');
        $this->fail('Expected the executor to fail.');
    } catch (EphemeralAgentToolException $exception) {
        expect($exception->reason)->toBe('execution_failed')
            ->and($exception->getMessage())->not->toContain('fixture-secret')
            ->and($exception->getPrevious())->toBeNull();
    }

    expect(ephemeralRuntimeTableCounts())->toBe($before);
});

it('keeps authorizeTool and resolveBinding pure for the runId 0 sentinel', function (): void {
    app(EphemeralAgentToolExecutor::class)->execute(EPHEMERAL_PROFILE, 'fixture.echo', ['text' => 'warm'], 'Content');
    $runner = app(AgentRunner::class);
    $state = static fn (int $runId): AgentState => new AgentState(
        runId: $runId,
        workflowInstanceId: null,
        parentWorkflowStepId: null,
        stepIndex: 1,
        environment: 'testing',
        tenantId: null,
        moduleScope: 'fixture',
        goal: null,
        availableToolDefinitions: ['fixture.echo'],
        profileCapabilityClass: 'class_0_read_only',
    );
    $queries = 0;
    $events = [];
    DB::listen(static function () use (&$queries): void {
        $queries++;
    });
    Event::listen('*', static function (string $name) use (&$events): void {
        $events[] = $name;
    });

    $zero = [$runner->authorizeTool($state(0), 'fixture.echo'), $runner->resolveBinding($state(0), 'fixture.echo')];
    $other = [$runner->authorizeTool($state(987654), 'fixture.echo'), $runner->resolveBinding($state(987654), 'fixture.echo')];

    expect($zero[0]->allowed)->toBeTrue()
        ->and($zero[1])->not->toBeNull()
        ->and($zero)->toEqual($other)
        ->and($queries)->toBe(0)
        ->and(array_values(array_filter($events, static fn (string $name): bool => ! str_starts_with($name, 'Illuminate\\Log'))))->toBe([]);
});

it('is bound as a scoped service', function (): void {
    $first = app(EphemeralAgentToolExecutor::class);

    expect(app(EphemeralAgentToolExecutor::class))->toBe($first);
    app()->forgetScopedInstances();
    expect(app(EphemeralAgentToolExecutor::class))->not->toBe($first);
});

/**
 * Row counts of every Agentic runtime and workflow table.
 *
 * @return array<string, int>
 */
function ephemeralRuntimeTableCounts(): array
{
    $counts = [];
    foreach (Schema::getTableListing() as $table) {
        $name = (string) preg_replace('/^.*\./', '', (string) $table);
        if (str_starts_with($name, 'agent_') || str_starts_with($name, 'workflow_')) {
            $counts[$name] = DB::table($name)->count();
        }
    }
    ksort($counts);

    return $counts;
}

/** Activator stub: the Content module follows $enabled, every other module stays enabled. */
function ephemeralModuleActivator(bool $enabled): void
{
    $activator = Mockery::mock(ActivatorInterface::class);
    $activator->shouldReceive('hasStatus')
        ->zeroOrMoreTimes()
        ->andReturnUsing(static fn ($module, bool $status): bool => strcasecmp((string) $module, 'Content') === 0
            ? $enabled === $status
            : $status);
    $activator->shouldIgnoreMissing();
    app()->instance(ActivatorInterface::class, $activator);
}
