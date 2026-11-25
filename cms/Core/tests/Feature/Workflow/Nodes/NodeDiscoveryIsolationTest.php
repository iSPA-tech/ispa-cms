<?php

declare(strict_types=1);

namespace Cms\Core\Tests\Feature\Workflow\Nodes;

use Cms\Core\Workflows\Nodes\Attributes\WorkflowNode;
use Cms\Core\Workflows\Nodes\NodeRegistry;
use Cms\Core\Workflows\Runtime\StepTypeCatalog;
use Illuminate\Support\Facades\Artisan;

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * One broken #[WorkflowNode] never takes its sibling nodes down with it: the registry drops only that node (or the later
 * copy of a duplicate type), records why, and `cms:workflow-integrity-check` reports it and fails. Before 2026-11-25 a
 * single unknown safety class dropped every node of the module service with only a warning in the log.
 */

final class IsolationFixtureNodes
{
    #[WorkflowNode(type: 'isofix.good', safety: ['read'], result: [])]
    public function good(array $config): array
    {
        return [];
    }

    #[WorkflowNode(type: 'isofix.bad', safety: ['write'], result: [])]
    public function bad(array $config): array
    {
        return [];
    }

    #[WorkflowNode(type: 'Isofix-Bad-Name', safety: ['read'], result: [])]
    public function badName(array $config): array
    {
        return [];
    }
}

final class IsolationDuplicateNodes
{
    #[WorkflowNode(type: 'isofix.good', safety: ['read'], result: [])]
    public function copy(array $config): array
    {
        return [];
    }

    #[WorkflowNode(type: 'isofix.other', safety: ['read'], result: [])]
    public function other(array $config): array
    {
        return [];
    }
}

beforeEach(function (): void {
    config(['core.workflow_nodes.allowlisted_namespaces' => ['Cms\\Core\\Tests\\Feature\\Workflow\\Nodes\\']]);
});

it('keeps the valid nodes of a service when one of its nodes is invalid and reports the dropped ones', function (): void {
    NodeRegistry::registerModuleService(IsolationFixtureNodes::class);
    $registry = NodeRegistry::current();

    expect($registry->has('isofix.good'))->toBeTrue()
        ->and($registry->has('isofix.bad'))->toBeFalse()
        ->and(StepTypeCatalog::isStorable('isofix.bad'))->toBeFalse();

    // Only this fixture's entries (module services of the test site are refused here: their namespaces are not allowlisted).
    $rejected = collect($registry->rejected())->where('class', IsolationFixtureNodes::class)->keyBy('method');
    expect($rejected->keys()->sort()->values()->all())->toBe(['bad', 'badName'])
        ->and($rejected['bad']['type'])->toBe('isofix.bad')
        ->and($rejected['bad']['class'])->toBe(IsolationFixtureNodes::class)
        ->and($rejected['bad']['reason'])->toContain('safety')
        ->and($rejected['badName']['reason'])->toContain('<module>.<name>');
});

it('keeps the first registration of a duplicate type and reports the later copy', function (): void {
    NodeRegistry::registerModuleService(IsolationFixtureNodes::class);
    NodeRegistry::registerModuleService(IsolationDuplicateNodes::class);
    $registry = NodeRegistry::current();

    expect($registry->get('isofix.good')->service)->toBe(IsolationFixtureNodes::class)
        ->and($registry->has('isofix.other'))->toBeTrue();

    $duplicate = collect($registry->rejected())->firstWhere('class', IsolationDuplicateNodes::class);
    expect($duplicate['type'])->toBe('isofix.good')
        ->and($duplicate['method'])->toBe('copy')
        ->and($duplicate['reason'])->toContain('already registered');
});

it('makes the integrity check fail and list the dropped nodes', function (): void {
    NodeRegistry::registerModuleService(IsolationFixtureNodes::class);

    $exit = Artisan::call('cms:workflow-integrity-check');
    $output = Artisan::output();

    expect($exit)->not->toBe(0)
        ->and($output)->toContain('isofix.bad')
        ->and($output)->toContain(IsolationFixtureNodes::class . '::bad');
});

it('passes the integrity check when every module node registers', function (): void {
    config(['core.workflow_nodes.module_services' => []]);

    expect(Artisan::call('cms:workflow-integrity-check'))->toBe(0);
});
