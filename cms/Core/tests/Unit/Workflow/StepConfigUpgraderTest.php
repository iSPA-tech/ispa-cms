<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Workflows\Upgrade\StepConfigUpgrader;
use Cms\Core\Tests\TestCase;

class StepConfigUpgraderTest extends TestCase
{
    public function test_condition_equals_is_normalized(): void
    {
        $upgrader = new StepConfigUpgrader();
        $res = $upgrader->upgrade(['condition' => 'root.total = 1000'], 'condition');

        $this->assertSame('root.total == 1000', $res['config']['expression']);
        $this->assertNotEmpty($res['_upgrade_log']);
    }

    public function test_alias_replaced_from_order_to_root(): void
    {
        $upgrader = new StepConfigUpgrader();
        $res = $upgrader->upgrade(['condition' => '$order.id == 1'], 'decision');

        $this->assertSame('root.id == 1', $res['config']['expression']);
    }

    public function test_retrieve_where_normalization(): void
    {
        $upgrader = new StepConfigUpgrader();
        $res = $upgrader->upgrade(['where' => ['email' => 'root.email']], 'retrieve');

        $this->assertIsArray($res['config']['where']);
        $this->assertSame('email', $res['config']['where'][0]['field']);
        $this->assertSame('==', $res['config']['where'][0]['op']);
    }

    public function test_idempotent(): void
    {
        $upgrader = new StepConfigUpgrader();
        $first = $upgrader->upgrade(['condition' => 'root.total = 1000'], 'condition');
        $second = $upgrader->upgrade($first['config'], 'condition');

        $this->assertSame($first['config']['expression'], $second['config']['expression']);
    }
}
