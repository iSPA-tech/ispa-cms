<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Workflows\Blueprint\BlueprintNormalizer;
use Cms\Core\Tests\TestCase;

class BlueprintNormalizerTest extends TestCase
{
    public function test_service_methods_filtered(): void
    {
        $raw = [
            [
                'category' => 'service',
                'class' => DummyService::class,
                'functions' => [
                    ['name' => 'ownMethod'],
                    ['name' => '__invoke'],
                    ['name' => 'inheritedMethod'],
                ],
            ],
        ];

        $normalizer = new BlueprintNormalizer();
        $result = $normalizer->normalize($raw);

        $this->assertCount(1, $result[0]['functions']);
        $this->assertSame('ownMethod', $result[0]['functions'][0]['name']);
        $this->assertArrayHasKey('ui', $result[0]['functions'][0]);
    }
}

class DummyBaseService
{
    public function inheritedMethod(): void
    {
    }
}

class DummyService extends DummyBaseService
{
    public function ownMethod(): void
    {
    }
}
