<?php

declare(strict_types=1);

use Cms\Core\Workflows\Nodes\NodeRegistry;
use Cms\Core\Workflows\Runtime\Runners\LogMessageRunner;
use Cms\Core\Workflows\Runtime\StepTypeCatalog;
use Tests\TestCase;

uses(TestCase::class);

it('reads runners and definitions from a node set file', function (): void {
    config(['core.workflow_nodes.sample_set' => [
        'step_runners' => ['sample_echo' => LogMessageRunner::class],
        'definitions' => ['sample_echo' => ['group' => 'utilities', 'safety' => ['read']]],
    ]]);

    expect(NodeRegistry::runnerMap())->toHaveKey('sample_echo', LogMessageRunner::class)
        ->and(NodeRegistry::definitionRows())->toHaveKey('sample_echo')
        ->and((new NodeRegistry())->get('sample_echo')?->safety)->toBe(['read'])
        ->and(StepTypeCatalog::isStorable('sample_echo'))->toBeTrue();
});

it('keeps the core runner when a node set names the same type', function (): void {
    $core = (array) config('core.workflow.step_runners', []);
    $type = (string) array_key_first($core);

    config(['core.workflow_nodes.sample_set' => ['step_runners' => [$type => 'App\\Nope']]]);

    expect(NodeRegistry::runnerMap()[$type])->toBe($core[$type]);
});

it('gives a newly dropped loop step a default item variable the runner accepts', function (): void {
    // LoopEachRunner refuses an itemVar outside `vars.`; the designer seeds new steps with default_config.
    $default = (new NodeRegistry())->get('loop_each')?->defaultConfig ?? [];

    expect($default['itemVar'] ?? null)->toStartWith('vars.');
});
