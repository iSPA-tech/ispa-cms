<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Workflows\Blueprint\BlueprintNormalizer;
use Cms\Core\Tests\TestCase;

class BlueprintNormalizerEventCommandTest extends TestCase
{
    public function test_event_methods_filtered(): void
    {
        config(['core.workflow_blueprint.allowed_event_broadcast' => false]);

        $raw = [
            [
                'category' => 'event',
                'class' => 'DummyEvent',
                'functions' => [
                    ['name' => 'dispatch'],
                    ['name' => 'broadcast'],
                    ['name' => 'broadcastOn'],
                ],
            ],
        ];

        $normalizer = new BlueprintNormalizer();
        $result = $normalizer->normalize($raw);

        $this->assertCount(1, $result[0]['functions']);
        $this->assertSame('dispatch', $result[0]['functions'][0]['name']);
    }

    public function test_command_methods_filtered(): void
    {
        $raw = [
            [
                'category' => 'command',
                'class' => 'DummyCommand',
                'functions' => [
                    ['name' => 'handle'],
                    ['name' => 'configure'],
                ],
            ],
        ];

        $normalizer = new BlueprintNormalizer();
        $result = $normalizer->normalize($raw);

        $this->assertCount(1, $result[0]['functions']);
        $this->assertSame('handle', $result[0]['functions'][0]['name']);
    }
}
