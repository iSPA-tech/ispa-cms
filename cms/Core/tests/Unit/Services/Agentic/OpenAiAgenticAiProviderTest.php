<?php

use Mockery as m;
use App\Clients\OpenAIClient;
use Cms\Core\Services\Agentic\OpenAiAgenticAiProvider;

test('openai agentic ai provider proxies completion and content calls', function () {
    $client = m::mock(OpenAIClient::class);
    $client->shouldReceive('createCompletion')
        ->once()
        ->with(['model' => 'gpt-4o-mini'])
        ->andReturn(['choices' => [['index' => 0, 'message' => ['content' => '{"ok":true}']]]]);
    $client->shouldReceive('getContent')
        ->once()
        ->with(['choices' => [['index' => 0, 'message' => ['content' => '{"ok":true}']]]])
        ->andReturn('{"ok":true}');

    $provider = new OpenAiAgenticAiProvider($client);

    $response = $provider->createCompletion(['model' => 'gpt-4o-mini']);
    $content = $provider->getContent($response);

    expect($response)->toBe(['choices' => [['index' => 0, 'message' => ['content' => '{"ok":true}']]]])
        ->and($content)->toBe('{"ok":true}');
});

afterEach(function () {
    m::close();
});
