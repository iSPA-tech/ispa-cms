<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\Pipelines\AttachImagesInsideContent;

class PipelineAttachTest extends TestCase
{
    public function test_handle_calls_next(): void
    {
        $pipeline = new AttachImagesInsideContent();
        $model = new class {
            public int $id = 1;
        };
        $result = $pipeline->handle($model, function ($m) {
            return 'passed';
        });
        $this->assertEquals('passed', $result);
    }
}
