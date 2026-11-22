<?php

namespace Cms\Core\Services\Agentic;

use GuzzleHttp\Client;

final class DeepSeekAgenticAiProvider extends OpenAiCompatibleHttpAgenticAiProvider
{
    public function __construct(?Client $client = null)
    {
        parent::__construct($client ?? new Client([
            'base_uri' => rtrim((string) config('core.ai_structured_extraction.providers.deepseek.base_uri'), '/') . '/',
            'headers' => [
                'Authorization' => 'Bearer ' . (string) config('core.ai_structured_extraction.providers.deepseek.api_key'),
                'Content-Type' => 'application/json',
            ],
        ]), 'deepseek');
    }

    protected function normalizePayload(array $payload): array
    {
        $schemaName = (string) data_get($payload, 'response_format.json_schema.name', 'json_response');
        $schema = data_get($payload, 'response_format.json_schema.schema');
        $payload['response_format'] = ['type' => 'json_object'];
        $payload['thinking'] = ['type' => $this->thinkingEnabled() ? 'enabled' : 'disabled'];
        $payload['max_tokens'] = (int) ($payload['max_tokens'] ?? config('core.ai_structured_extraction.providers.deepseek.max_tokens', 8192));

        if (is_array($schema)) {
            $instruction = implode("\n\n", [
                'Return one valid JSON object only. Do not output markdown, comments, explanations, or whitespace before or after the JSON object.',
                'The output JSON must match JSON schema [' . $schemaName . '].',
                'EXAMPLE JSON OUTPUT:',
                $this->stableJson($this->exampleForSchema($schema)),
                'JSON SCHEMA:',
                $this->stableJson($schema),
            ]);
            $payload['messages'] = $this->prependSystemInstruction((array) ($payload['messages'] ?? []), $instruction);
        }

        return $payload;
    }

    private function thinkingEnabled(): bool
    {
        return filter_var(config('core.ai_structured_extraction.providers.deepseek.thinking_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param array<string, mixed> $schema
     * @return mixed
     */
    private function exampleForSchema(array $schema, int $depth = 0): mixed
    {
        if ($depth > 6) {
            return null;
        }

        if (array_key_exists('const', $schema)) {
            return $schema['const'];
        }

        if (is_array($schema['enum'] ?? null) && $schema['enum'] !== []) {
            return $schema['enum'][0];
        }

        if (is_array($schema['oneOf'] ?? null)) {
            return $this->exampleForSchema((array) $schema['oneOf'][0], $depth + 1);
        }

        if (is_array($schema['anyOf'] ?? null)) {
            return $this->exampleForSchema((array) $schema['anyOf'][0], $depth + 1);
        }

        $type = $this->schemaType($schema);

        return match ($type) {
            'object' => $this->objectExample($schema, $depth),
            'array' => [$this->exampleForSchema((array) ($schema['items'] ?? ['type' => 'string']), $depth + 1)],
            'integer' => 0,
            'number' => 0.0,
            'boolean' => true,
            'null' => null,
            default => 'string',
        };
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function objectExample(array $schema, int $depth): array
    {
        $properties = (array) ($schema['properties'] ?? []);
        $required = array_flip(array_map('strval', (array) ($schema['required'] ?? array_keys($properties))));
        $example = [];

        foreach ($properties as $property => $propertySchema) {
            if (! isset($required[(string) $property]) && count($example) >= 8) {
                continue;
            }

            $example[(string) $property] = $this->exampleForSchema((array) $propertySchema, $depth + 1);
        }

        return $example;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function schemaType(array $schema): string
    {
        $type = $schema['type'] ?? 'string';

        if (is_array($type)) {
            $type = array_values(array_filter($type, static fn (mixed $value): bool => $value !== 'null'))[0] ?? 'null';
        }

        return is_string($type) ? $type : 'string';
    }

    /**
     * @param mixed $value
     */
    private function stableJson(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}';
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private function prependSystemInstruction(array $messages, string $instruction): array
    {
        if (($messages[0]['role'] ?? null) === 'system') {
            $messages[0]['content'] = trim($instruction . "\n\n" . (string) ($messages[0]['content'] ?? ''));

            return $messages;
        }

        array_unshift($messages, [
            'role' => 'system',
            'content' => $instruction,
        ]);

        return $messages;
    }
}
