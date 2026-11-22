<?php

namespace Tests\Unit;

use App\Clients\OpenAIClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class OpenAIClientTest extends TestCase
{
    /**
     * OpenAI 5xx responses are transient upstream failures, so the client
     * should retry before returning an empty response to callers.
     */
    public function test_create_completion_retries_transient_openai_502(): void
    {
        $mock = new MockHandler([
            new Response(502, [], 'error code: 502'),
            new Response(200, [], json_encode([
                'choices' => [
                    ['index' => 0, 'message' => ['content' => '{"segments":{"1":"Xin chao"}}']],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $client = new OpenAIClient(
            new Client([
                'base_uri' => 'https://api.openai.com/v1/',
                'handler' => HandlerStack::create($mock),
            ]),
            maxAttempts: 2,
            backoffMs: 0,
        );

        $response = $client->createCompletion([
            'model' => 'gpt-test',
            'messages' => [['role' => 'user', 'content' => 'Translate me']],
        ]);

        $this->assertSame('{"segments":{"1":"Xin chao"}}', $client->getContent($response));
        $this->assertSame(0, $mock->count());
    }

    /**
     * The API key must come from config, not getenv().
     *
     * `php artisan config:cache` skips the dotenv bootstrapper entirely, so
     * getenv('OPENAI_API_KEY') is false on every cached-config deploy. That sent
     * an empty bearer token upstream and turned every completion into a 401,
     * which callers then reported as an empty model response.
     */
    public function test_authorization_header_is_resolved_from_config_not_getenv(): void
    {
        putenv('OPENAI_API_KEY');
        config()->set('services.openai.key', 'sk-config-resolved');

        $headers = $this->defaultHeadersOf(new OpenAIClient());

        $this->assertSame('Bearer sk-config-resolved', $headers['Authorization']);
    }

    /**
     * Read the headers the client would send on its default Guzzle instance.
     *
     * @return array<string, string>
     */
    private function defaultHeadersOf(OpenAIClient $client): array
    {
        $guzzle = (new \ReflectionProperty(OpenAIClient::class, 'client'))->getValue($client);
        $config = (new \ReflectionProperty(Client::class, 'config'))->getValue($guzzle);

        return $config['headers'];
    }

    /**
     * Failed OpenAI calls must not dump the full prompt/messages into logs.
     */
    public function test_create_completion_logs_sanitized_context_after_retry_exhaustion(): void
    {
        $canary = 'OPENAI_RESPONSE_BODY_CANARY_MUST_NOT_BE_LOGGED';

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($canary): bool {
                return $message === 'Transient OpenAI request failure; retrying.'
                    && ($context['status_code'] ?? null) === 502
                    && isset($context['error_type'])
                    && !str_contains($message, $canary)
                    && !str_contains(json_encode($context, JSON_THROW_ON_ERROR), $canary)
                    && !array_key_exists('error', $context);
            });
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($canary): bool {
                return $message === 'OpenAI completion request failed.'
                    && ($context['status_code'] ?? null) === 502
                    && isset($context['error_type'])
                    && isset($context['request'])
                    && ($context['request']['model'] ?? null) === 'gpt-test'
                    && ($context['request']['message_count'] ?? null) === 1
                    && !str_contains($message, $canary)
                    && !str_contains(json_encode($context, JSON_THROW_ON_ERROR), $canary)
                    && !array_key_exists('error', $context)
                    && !array_key_exists('messages', $context)
                    && !array_key_exists('content', $context['request']);
            });

        $mock = new MockHandler([
            new Response(502, [], $canary),
            new Response(502, [], $canary),
        ]);

        $client = new OpenAIClient(
            new Client([
                'base_uri' => 'https://api.openai.com/v1/',
                'handler' => HandlerStack::create($mock),
            ]),
            maxAttempts: 2,
            backoffMs: 0,
        );

        $response = $client->createCompletion([
            'model' => 'gpt-test',
            'messages' => [['role' => 'user', 'content' => str_repeat('large prompt ', 100)]],
        ]);

        $this->assertSame([], $response);
    }

    public function test_create_completion_non_200_log_omits_provider_response_body(): void
    {
        $canary = 'OPENAI_NON_200_BODY_CANARY_MUST_NOT_BE_LOGGED';
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($canary): bool {
                return $message === 'Non-200 response from OpenAI'
                    && ($context['status_code'] ?? null) === 400
                    && isset($context['request'])
                    && !array_key_exists('response', $context)
                    && !str_contains($message, $canary)
                    && !str_contains(json_encode($context, JSON_THROW_ON_ERROR), $canary);
            });

        $mock = new MockHandler([new Response(400, [], $canary)]);
        $client = new OpenAIClient(
            new Client([
                'base_uri' => 'https://api.openai.com/v1/',
                'handler' => HandlerStack::create($mock),
                'http_errors' => false,
            ]),
            maxAttempts: 1,
            backoffMs: 0,
        );

        $this->assertSame([], $client->createCompletion([
            'model' => 'gpt-test',
            'messages' => [['role' => 'user', 'content' => 'Safe prompt']],
        ]));
    }

    /**
     * Public endpoints can pass request-level client controls without leaking
     * those internal keys into the OpenAI JSON payload.
     */
    public function test_create_completion_applies_request_timeout_controls_without_sending_them(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'choices' => [
                    ['index' => 0, 'message' => ['content' => '{"ok":true}']],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $client = new OpenAIClient(
            new Client([
                'base_uri' => 'https://api.openai.com/v1/',
                'handler' => $stack,
            ]),
            maxAttempts: 3,
            backoffMs: 0,
        );

        $response = $client->createCompletion([
            'model' => 'gpt-test',
            'messages' => [['role' => 'user', 'content' => 'Short prompt']],
            'request_timeout_seconds' => 12,
            'request_connect_timeout_seconds' => 3,
            'request_max_attempts' => 1,
        ]);

        $sentBody = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('{"ok":true}', $client->getContent($response));
        $this->assertSame(12.0, $history[0]['options']['timeout']);
        $this->assertSame(3.0, $history[0]['options']['connect_timeout']);
        $this->assertArrayNotHasKey('request_timeout_seconds', $sentBody);
        $this->assertArrayNotHasKey('request_connect_timeout_seconds', $sentBody);
        $this->assertArrayNotHasKey('request_max_attempts', $sentBody);
    }

    /**
     * Retry/error summaries must estimate payload size without serializing the
     * full file-bearing request or invoking caller-owned serializers.
     */
    public function test_completion_payload_summary_does_not_serialize_file_data(): void
    {
        $serializeProbe = new class implements \JsonSerializable {
            public int $calls = 0;

            public function jsonSerialize(): mixed
            {
                $this->calls++;

                return ['file_data' => 'SUMMARY_SERIALIZATION_CANARY'];
            }
        };
        $fileData = 'data:application/pdf;base64,' . str_repeat('A', 10000);
        $client = new OpenAIClient();
        $method = new \ReflectionMethod($client, 'summarizeCompletionPayload');
        $method->setAccessible(true);

        $summary = $method->invoke($client, [
            'model' => 'gpt-test',
            'messages' => [[
                'role' => 'user',
                'content' => [[
                    'type' => 'file',
                    'file' => [
                        'filename' => 'vehicle-spec.pdf',
                        'file_data' => $fileData,
                        'probe' => $serializeProbe,
                    ],
                ]],
            ]],
            'input_length' => 321,
        ]);

        $this->assertSame(0, $serializeProbe->calls);
        $this->assertSame('gpt-test', $summary['model']);
        $this->assertSame(1, $summary['message_count']);
        $this->assertSame(321, $summary['input_length']);
        $this->assertGreaterThanOrEqual(strlen($fileData), $summary['body_bytes']);
        $this->assertStringNotContainsString('SUMMARY_SERIALIZATION_CANARY', json_encode($summary, JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('messages', $summary);
        $this->assertArrayNotHasKey('content', $summary);
    }
}
