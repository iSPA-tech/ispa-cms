<?php

declare(strict_types=1);

namespace Cms\Core\Tests\Fixtures\Agents;

use Cms\Core\Agents\Attributes\AgentTool;
use RuntimeException;

/**
 * Tools for the ephemeral executor tests: a read-only echo, a write tool, an approval-gated read,
 * a failing read and a read that reports the execution time limit it ran under.
 */
class EphemeralToolFixtureService
{
    public static int $calls = 0;

    #[AgentTool(name: 'fixture.echo', family: 'fixture', moduleScope: 'fixture', contractSteward: 'fixture', timeoutSeconds: 30)]
    public function echo_tool(string $text, int $times = 1): array
    {
        self::$calls++;

        return ['text' => str_repeat($text, max(1, $times)), 'call' => self::$calls];
    }

    #[AgentTool(name: 'fixture.scalar', family: 'fixture', moduleScope: 'fixture', contractSteward: 'fixture')]
    public function scalar_tool(): string
    {
        return 'plain';
    }

    #[AgentTool(name: 'fixture.write', family: 'fixture', moduleScope: 'fixture', contractSteward: 'fixture', sideEffectClass: 'staged_write')]
    public function write_tool(string $text): array
    {
        self::$calls++;

        return ['written' => $text];
    }

    #[AgentTool(name: 'fixture.approval', family: 'fixture', moduleScope: 'fixture', contractSteward: 'fixture', approvalRequired: true)]
    public function approval_tool(): array
    {
        self::$calls++;

        return ['approved' => true];
    }

    #[AgentTool(name: 'fixture.fail', family: 'fixture', moduleScope: 'fixture', contractSteward: 'fixture')]
    public function fail_tool(): array
    {
        throw new RuntimeException('fixture-secret');
    }

    #[AgentTool(name: 'fixture.limit', family: 'fixture', moduleScope: 'fixture', contractSteward: 'fixture', timeoutSeconds: 7)]
    public function limit_tool(): array
    {
        return ['limit' => (int) ini_get('max_execution_time')];
    }
}
