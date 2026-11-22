<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\Pipelines\DetachImagesInsideContent;

class PipelineDetachTest extends TestCase
{
    public function test_handle_calls_next(): void
    {
        $pipeline = new DetachImagesInsideContent();
        $model = new class {
            public int $id = 1;
        };
        $result = $pipeline->handle($model, function ($m) {
            return 'ok';
        });
        $this->assertEquals('ok', $result);
    }
}
