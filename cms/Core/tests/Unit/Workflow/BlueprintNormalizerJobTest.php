<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Workflows\Blueprint\BlueprintNormalizer;
use Cms\Core\Tests\TestCase;

class BlueprintNormalizerJobTest extends TestCase
{
    public function test_job_methods_filtered_by_whitelist(): void
    {
        config(['core.workflow_blueprint.allowed_job_methods' => ['handle', 'dispatch']]);

        $raw = [
            [
                'category' => 'job',
                'class' => 'DummyJob',
                'functions' => [
                    ['name' => 'handle'],
                    ['name' => 'assertSomething'],
                    ['name' => 'dispatch'],
                ],
            ],
        ];

        $normalizer = new BlueprintNormalizer();
        $result = $normalizer->normalize($raw);

        $this->assertCount(2, $result[0]['functions']);
        $this->assertEqualsCanonicalizing(['handle', 'dispatch'], array_column($result[0]['functions'], 'name'));
    }
}
