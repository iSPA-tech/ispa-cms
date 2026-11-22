<?php

use Mockery as m;
use App\Clients\OpenAIClient;
use Cms\Core\Services\Agentic\OpenAiAgenticEmbeddingProvider;

test('openai agentic embedding provider proxies embedding calls', function () {
    $client = m::mock(OpenAIClient::class);
    $client->shouldReceive('createEmbedding')
        ->once()
        ->with('normalize product copy', 'text-embedding-3-small')
        ->andReturn([0.1, 0.2, 0.3]);

    $provider = new OpenAiAgenticEmbeddingProvider($client);

    expect($provider->createEmbedding('normalize product copy', 'text-embedding-3-small'))
        ->toBe([0.1, 0.2, 0.3]);
});

afterEach(function () {
    m::close();
});
