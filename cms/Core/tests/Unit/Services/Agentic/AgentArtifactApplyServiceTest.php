<?php

use Cms\Core\Agents\Contracts\ArtifactApplierInterface;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Agents\AgentArtifact;
use Cms\Core\Services\Agentic\AgentArtifactApplyService;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;

test('apply throws when no applier configured for artifact type', function () {
    $container = Mockery::mock(Container::class);
    $config = new ConfigRepository(['core.agentic.artifact_appliers' => []]);

    $service = new AgentArtifactApplyService($container, $config);

    $artifact = Mockery::mock(AgentArtifact::class)->makePartial();
    $artifact->artifact_type = 'unknown_type';
    $approval = Mockery::mock(AgentApproval::class)->makePartial();

    expect(fn () => $service->apply($artifact, $approval))
        ->toThrow(\InvalidArgumentException::class, 'No artifact applier configured for [unknown_type]');
});

test('apply throws when applier does not implement ArtifactApplierInterface', function () {
    $fakeApplier = new class {
        public function apply($a, $b): array { return []; }
    };

    $container = Mockery::mock(Container::class);
    $container->shouldReceive('make')
        ->with(get_class($fakeApplier))
        ->andReturn($fakeApplier);

    $config = new ConfigRepository([
        'core.agentic.artifact_appliers' => [
            'test_type' => get_class($fakeApplier),
        ],
    ]);

    $service = new AgentArtifactApplyService($container, $config);

    $artifact = Mockery::mock(AgentArtifact::class)->makePartial();
    $artifact->artifact_type = 'test_type';
    $approval = Mockery::mock(AgentApproval::class)->makePartial();

    expect(fn () => $service->apply($artifact, $approval))
        ->toThrow(\InvalidArgumentException::class, 'must implement ArtifactApplierInterface');
});

test('apply delegates to correct applier when interface implemented', function () {
    $applier = Mockery::mock(ArtifactApplierInterface::class);
    $applier->shouldReceive('apply')
        ->once()
        ->andReturn(['success' => true, 'post_id' => 42]);

    $container = Mockery::mock(Container::class);
    $container->shouldReceive('make')
        ->with('FakeApplierClass')
        ->andReturn($applier);

    $config = new ConfigRepository([
        'core.agentic.artifact_appliers' => [
            'content_post_description_patch' => 'FakeApplierClass',
        ],
    ]);

    $service = new AgentArtifactApplyService($container, $config);

    $artifact = Mockery::mock(AgentArtifact::class)->makePartial();
    $artifact->artifact_type = 'content_post_description_patch';
    $approval = Mockery::mock(AgentApproval::class)->makePartial();

    $result = $service->apply($artifact, $approval);

    expect($result)->toBe(['success' => true, 'post_id' => 42]);
});
