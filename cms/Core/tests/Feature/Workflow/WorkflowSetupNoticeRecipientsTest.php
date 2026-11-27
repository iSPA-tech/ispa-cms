<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Console\Commands\InstallContactWorkflowCommand;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\Workflow\Setup\WorkflowSetupService;
use Cms\Core\Services\Workflow\Triggers\TriggerChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * Setup "who is told" of a notice step. The answer
 * names people and/or everyone who may see a kind of record; the server suggests that kind from what the workflow
 * starts from or reads, and previews who would be told with the notify runner's own rule.
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

function nrUser(string $name, array $permissions = []): User
{
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => $name,
        'username' => $name . '-' . Str::lower(Str::random(6)),
        'email' => $name . '-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => 1,
        'level' => 0,
        'status' => null,
    ]);

    if ($permissions !== []) {
        $role = Role::query()->create(['name' => 'Role ' . Str::random(6), 'custom' => ['level' => 50]]);
        foreach ($permissions as $key) {
            $dot = strrpos($key, '.');
            $role->permissions()->attach(Permission::query()->firstOrCreate(['group' => substr($key, 0, $dot), 'name' => substr($key, $dot + 1)], ['title' => $key]));
        }
        $user->roles()->attach($role);
    }

    return $user->fresh();
}

function nrQuestion(array $setup): array
{
    return collect($setup['questions'])->firstWhere('key', 'notify_admins.permission');
}

beforeEach(function (): void {
    $this->withoutMiddleware();
    if (DB::getDriverName() === 'sqlite') {
        DB::statement('PRAGMA defer_foreign_keys = ON');
    }
    TriggerChain::reset();
    nrUser('placeholder'); // user id 1 is always a webmaster
    $this->operator = nrUser('operator', ['workflow_project.viewAny', 'workflow_project.view', 'workflow_project.update', 'workflow_project.publish', 'email.viewAny']);
    $this->artisan('cms:workflow-install-contact')->assertSuccessful();
    $this->project = WorkflowProject::query()->where('slug', InstallContactWorkflowCommand::PROJECT)->sole();
});

test('the contact template suggests the people who may read contact messages', function (): void {
    $question = nrQuestion(app(WorkflowSetupService::class)->describe($this->project));

    expect($question)->toMatchArray(['kind' => 'notify_channel', 'answered' => false])
        ->and($question['current'])->toBe(['permission' => null, 'users' => [], 'suggested' => 'email.viewAny']);
});

test('a notice is answered with named people, a permission or both; unknown people and an empty answer are refused', function (): void {
    $alice = nrUser('alice');
    $setup = app(WorkflowSetupService::class);
    $step = fn () => WorkflowStep::query()->where('project_id', $this->project->id)->where('step_code', 'notify_admins')->sole()->step_config;

    expect($setup->apply($this->project, ['notify_admins.permission' => ['permission' => '', 'users' => [$alice->uuid]]], $this->operator))->toBe([])
        ->and($step())->toMatchArray(['permission' => null, 'users' => [$alice->uuid]])
        ->and(nrQuestion($setup->describe($this->project->fresh())))->toMatchArray(['answered' => true]);

    expect($setup->apply($this->project, ['notify_admins.permission' => ['permission' => 'email.viewAny', 'users' => []]], $this->operator))->toBe([])
        ->and($step()['permission'])->toBe('email.viewAny')
        ->and($step())->not->toHaveKey('users');

    // A bare string is still a permission (answers saved before this change).
    expect($setup->apply($this->project, ['notify_admins.permission' => 'email.viewAny'], $this->operator))->toBe([]);

    $refused = fn (mixed $answer) => $setup->apply($this->project, ['notify_admins.permission' => $answer], $this->operator);
    expect($refused(['permission' => '', 'users' => [(string) Str::uuid()]]))->not->toBe([])
        ->and($refused(['permission' => '', 'users' => []]))->not->toBe([])
        ->and($refused(['permission' => 'no.such', 'users' => []]))->not->toBe([]);
});

test('the preview names who would be told, with the notify rule, and needs update on the workflow', function (): void {
    $reader = nrUser('reader', ['email.viewAny']);
    $alice = nrUser('alice');
    $url = "/api/v2/admin/workflows/{$this->project->uuid}/setup/recipients";

    $data = $this->actingAs($this->operator, 'sanctum')
        ->getJson($url . '?permission=email.viewAny&users[]=' . $alice->uuid)
        ->assertOk()->json('data');

    expect($data['count'])->toBe(3)
        ->and(collect($data['people'])->pluck('name')->sort()->values()->all())->toBe(['alice', 'operator', 'reader'])
        ->and($data['more'])->toBe(0);

    $this->actingAs(nrUser('viewer', ['workflow_project.viewAny', 'workflow_project.view']), 'sanctum')
        ->getJson($url . '?permission=email.viewAny')->assertForbidden();
});
