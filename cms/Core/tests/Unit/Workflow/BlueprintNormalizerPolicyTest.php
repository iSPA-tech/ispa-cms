<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Workflows\Blueprint\BlueprintNormalizer;
use Cms\Core\Tests\TestCase;

class BlueprintNormalizerPolicyTest extends TestCase
{
    public function test_request_parameter_marks_hidden(): void
    {
        config([
            'core.workflow_blueprint.denied_method_prefixes' => ['__'],
            'core.workflow_blueprint.denied_parameter_types' => ['Illuminate\\Http\\Request'],
        ]);

        $raw = [
            [
                'category' => 'service',
                'class' => DummyPolicyService::class,
                'functions' => [
                    [
                        'name' => 'doWork',
                        'parameters' => [
                            ['name' => 'request', 'type' => 'Illuminate\\Http\\Request'],
                        ],
                    ],
                ],
            ],
        ];

        $normalizer = new BlueprintNormalizer();
        $result = $normalizer->normalize($raw);

        $fn = $result[0]['functions'][0];
        $this->assertTrue($fn['ui']['hidden']);
        $this->assertEquals('Requires Illuminate\\Http\\Request', $fn['ui']['disallowed_reason']);
    }
}

class DummyPolicyService
{
    public function doWork($request)
    {
    }
}
