<?php

namespace Cms\Core\Tests\Fixtures\Agents;

use App\Models\User;
use Cms\Core\Agents\Attributes\AgentTool;

class SampleToolService
{
    #[AgentTool(
        name: 'content.read',
        family: 'content',
        moduleScope: 'content',
        contractSteward: 'content',
        description: 'Read one content entity by id.'
    )]
    public function read_tool(int $id): array
    {
        return ['id' => $id];
    }

    public function search_tool(string $query): array
    {
        return ['query' => $query];
    }

    /**
     * Return the id of the user the tool runs for (the reserved `actor` argument).
     *
     * @param User|null $actor Delegating user supplied by the runner.
     * @return array{actor_id: int|null}
     */
    #[AgentTool(
        name: 'demo.actor_probe',
        family: 'demo',
        moduleScope: 'demo',
        contractSteward: 'demo',
        sideEffectClass: 'read_only',
        safetyLevel: 'low',
        capabilityClass: 'class_0_read_only',
        description: 'Report which user the tool runs for.'
    )]
    public function actor_probe_tool(?User $actor = null): array
    {
        return ['actor_id' => $actor?->id];
    }
}
