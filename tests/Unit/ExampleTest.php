<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_json_decode(): void
    {
        $json = file_get_contents(__DIR__ . '/test/open-ai.response.json');

        $jsonArray = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($jsonArray);
        $this->assertArrayHasKey('choices', $jsonArray);
        $this->assertIsArray($jsonArray['choices']);
        $this->assertCount(1, $jsonArray['choices']);

        //  Get the first item with index = 0
        $firstItem = collect($jsonArray['choices'] ?? [])->where('index', 0)->first();

        $this->assertIsArray($firstItem);
        //  Get message.content from first item
        $content = $firstItem['message']['content'] ?? '{}';

        $this->assertIsString($content);

        //  Try to decode the content
        $contentArray = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($contentArray);
        $this->assertNotEmpty($contentArray);
    }
}
