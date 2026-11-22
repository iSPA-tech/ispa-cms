<?php

use Cms\Core\Agents\Runtime\ToolDefinitionFormatter;
use Cms\Core\Agents\Support\AgentToolDefinitionData;

test('formats tool definition into OpenAI function-calling schema', function () {
    $formatter = new ToolDefinitionFormatter();

    $definition = new AgentToolDefinitionData(
        toolName: 'content.latest_posts',
        toolFamily: 'content',
        description: 'Read the latest published posts.',
        inputSchema: [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer', 'description' => 'Max posts to return'],
            ],
        ],
    );

    $result = $formatter->formatOne($definition);

    expect($result['type'])->toBe('function');
    expect($result['function']['name'])->toBe('content.latest_posts');
    expect($result['function']['description'])->toBe('Read the latest published posts.');
    expect($result['function']['parameters']['properties']['limit']['type'])->toBe('integer');
});

test('formats empty input schema as empty object', function () {
    $formatter = new ToolDefinitionFormatter();

    $definition = new AgentToolDefinitionData(
        toolName: 'core.health_overview',
    );

    $result = $formatter->formatOne($definition);

    expect($result['function']['parameters']['type'])->toBe('object');
    expect($result['function']['description'])->toBe('Execute core.health_overview');
});

test('format filters by allowed tool names', function () {
    $formatter = new ToolDefinitionFormatter();

    $definitions = [
        'content.read' => new AgentToolDefinitionData(toolName: 'content.read', description: 'Read posts'),
        'content.write' => new AgentToolDefinitionData(toolName: 'content.write', description: 'Write posts'),
        'product.read' => new AgentToolDefinitionData(toolName: 'product.read', description: 'Read products'),
    ];

    $result = $formatter->format($definitions, ['content.read', 'product.read']);

    expect($result)->toHaveCount(2);
    expect($result[0]['function']['name'])->toBe('content.read');
    expect($result[1]['function']['name'])->toBe('product.read');
});

test('format returns all tools when allowed list is empty', function () {
    $formatter = new ToolDefinitionFormatter();

    $definitions = [
        'a' => new AgentToolDefinitionData(toolName: 'a'),
        'b' => new AgentToolDefinitionData(toolName: 'b'),
    ];

    $result = $formatter->format($definitions, []);

    expect($result)->toHaveCount(2);
});
