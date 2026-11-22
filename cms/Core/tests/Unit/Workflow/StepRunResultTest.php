<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Tests\TestCase;

class StepRunResultTest extends TestCase
{
    public function test_success_helper(): void
    {
        $res = StepRunResult::success(['ok' => true], [['level' => 'info']]);

        $this->assertSame('success', $res->status);
        $this->assertSame(['ok' => true], $res->resultData);
        $this->assertNotNull($res->startedAt);
        $this->assertNotNull($res->finishedAt);
    }

    public function test_failed_helper(): void
    {
        $res = StepRunResult::failed('Oops', [], ['code' => 123]);

        $this->assertSame('failed', $res->status);
        $this->assertSame('Oops', $res->error['message']);
        $this->assertEquals(123, $res->error['code']);
    }

    public function test_skipped_helper(): void
    {
        $res = StepRunResult::skipped('Not applicable');

        $this->assertSame('skipped', $res->status);
        $this->assertSame('Not applicable', $res->error['message']);
    }

    public function test_paused_helper(): void
    {
        $res = StepRunResult::paused('awaiting_approval', ['queued' => true]);

        $this->assertSame('awaiting_approval', $res->status);
        $this->assertTrue($res->paused);
        $this->assertSame(['queued' => true], $res->resultData);
        $this->assertNotNull($res->startedAt);
        $this->assertNotNull($res->finishedAt);
    }

    public function test_cancelled_helper(): void
    {
        $res = StepRunResult::cancelled('Stopped by operator', ['run_id' => 99]);

        $this->assertSame('cancelled', $res->status);
        $this->assertFalse($res->paused);
        $this->assertSame('Stopped by operator', $res->error['message']);
        $this->assertSame(['run_id' => 99], $res->resultData);
        $this->assertNotNull($res->startedAt);
        $this->assertNotNull($res->finishedAt);
    }
}
