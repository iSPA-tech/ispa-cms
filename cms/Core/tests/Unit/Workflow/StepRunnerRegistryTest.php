<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\StepRunnerRegistry;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Tests\TestCase;

class StepRunnerRegistryTest extends TestCase
{
    public function test_returns_fallback_for_unknown_type(): void
    {
        $registry = new StepRunnerRegistry(new DummyRunner('fallback'));
        $runner = $registry->get('unknown');

        $this->assertInstanceOf(DummyRunner::class, $runner);
        $this->assertSame('fallback', $runner->label);
    }

    public function test_register_and_get_runner(): void
    {
        $registry = new StepRunnerRegistry(new DummyRunner('fallback'));
        $runner = new DummyRunner('custom');
        $registry->register('custom_type', $runner);

        $this->assertTrue($registry->has('custom_type'));
        $this->assertSame($runner, $registry->get('custom_type'));
    }

    public function test_alias_resolution(): void
    {
        $registry = new StepRunnerRegistry(new DummyRunner('fallback'), ['old' => 'new']);
        $runner = new DummyRunner('new');
        $registry->register('new', $runner);

        $this->assertSame($runner, $registry->get('old'));
    }
}

class DummyRunner implements StepRunner
{
    public function __construct(public string $label)
    {
    }

    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        return StepRunResult::success($this->label);
    }
}
