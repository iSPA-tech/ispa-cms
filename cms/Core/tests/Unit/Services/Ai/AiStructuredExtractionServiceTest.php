<?php

use Mockery as m;
use Cms\Core\Agents\Contracts\AgenticAiProviderInterface;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\AiExtractionRun;
use Cms\Core\Services\Ai\AiStructuredExtractionService;
use Cms\Core\Services\Ai\JsonSchemaShapeValidator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

uses(\Cms\Core\Tests\TestCase::class);

beforeEach(function (): void {
    if (Schema::hasTable(CoreTableConstant::AI_EXTRACTION_RUNS)) {
        Schema::drop(CoreTableConstant::AI_EXTRACTION_RUNS);
    }

    Schema::create(CoreTableConstant::AI_EXTRACTION_RUNS, function (Blueprint $table): void {
        $table->id();
        $table->uuid('uuid')->unique();
        $table->string('module', 120)->nullable();
        $table->string('task', 160)->nullable();
        $table->string('schema_name', 180);
        $table->string('schema_version', 60)->default('v1');
        $table->string('provider', 80)->default('agentic_ai');
        $table->string('model', 140);
        $table->char('input_hash', 64);
        $table->char('schema_hash', 64);
        $table->char('instruction_hash', 64);
        $table->char('request_hash', 64)->unique();
        $table->unsignedInteger('input_bytes')->default(0);
        $table->unsignedInteger('prompt_bytes')->default(0);
        $table->json('context_payload')->nullable();
        $table->json('schema_payload')->nullable();
        $table->json('request_payload')->nullable();
        $table->json('response_payload')->nullable();
        $table->json('parsed_payload')->nullable();
        $table->json('usage_payload')->nullable();
        $table->decimal('confidence', 5, 4)->nullable();
        $table->string('status', 40)->default('pending');
        $table->text('error_message')->nullable();
        $table->timestamps();
    });

    config()->set('core.ai_structured_extraction.enabled', true);
    config()->set('core.ai_structured_extraction.provider', 'agentic_ai');
    config()->set('core.ai_structured_extraction.model', 'gpt-4o-mini');
    config()->set('core.ai_structured_extraction.cache.enabled', true);
    config()->set('core.ai_structured_extraction.strict', true);
    config()->set('core.ai_structured_extraction.system_prompt', 'Extract structured data.');
});

afterEach(function (): void {
    m::close();
});

test('extracts structured data, validates schema, and stores a redacted run', function (): void {
    $schema = sampleExtractionSchema();
    $provider = m::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')
        ->once()
        ->withArgs(function (array $payload) use ($schema): bool {
            expect($payload['model'])->toBe('gpt-4o-mini')
                ->and($payload['response_format']['type'])->toBe('json_schema')
                ->and($payload['response_format']['json_schema']['strict'])->toBeTrue()
                ->and($payload['response_format']['json_schema']['schema'])->toBe($schema)
                ->and($payload['response_format']['json_schema']['name'])->toBe('sample_module_mass_schedule_v1')
                ->and($payload)->not->toHaveKey('store');

            return true;
        })
        ->andReturn([
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['content' => '{"items":[{"day":"sunday","time":"06:00","evidence":"Chúa Nhật 06:00"}],"confidence":0.91}'],
                ],
            ],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 30],
        ]);
    $provider->shouldReceive('getContent')
        ->once()
        ->andReturn('{"items":[{"day":"sunday","time":"06:00","evidence":"Chúa Nhật 06:00"}],"confidence":0.91}');

    $service = new AiStructuredExtractionService($provider, new JsonSchemaShapeValidator());

    $result = $service->extract(
        input: 'Giờ lễ: Chúa Nhật 06:00',
        schema: $schema,
        instructions: 'Extract Mass schedule rows.',
        context: ['module' => 'SampleModule', 'source_url' => 'https://example.test/gio-le'],
        options: ['schema_name' => 'sample_module.mass_schedule.v1', 'schema_version' => 'v1', 'task' => 'mass_schedule']
    );

    expect($result->ok)->toBeTrue()
        ->and($result->cached)->toBeFalse()
        ->and($result->data['items'][0]['time'])->toBe('06:00')
        ->and($result->confidence)->toBe(0.91);

    $run = AiExtractionRun::query()->firstOrFail();

    expect($run->status)->toBe('completed')
        ->and($run->module)->toBe('SampleModule')
        ->and($run->task)->toBe('mass_schedule')
        ->and($run->parsed_payload['items'][0]['day'])->toBe('sunday')
        ->and($run->response_payload['choices'][0]['message'])->not->toHaveKey('content')
        ->and($run->response_payload['choices'][0]['message']['content_bytes'])->toBeGreaterThan(0);
});

test('omits temperature when the caller explicitly sets it to null', function (): void {
    config()->set('core.ai_structured_extraction.temperature', 0.0);

    $schema = sampleExtractionSchema();
    $provider = m::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')
        ->once()
        ->withArgs(function (array $payload): bool {
            expect($payload)->not->toHaveKey('temperature');

            return true;
        })
        ->andReturn([
            'choices' => [[
                'index' => 0,
                'message' => ['content' => '{"items":[],"confidence":0.5}'],
            ]],
        ]);
    $provider->shouldReceive('getContent')->once()->andReturn('{"items":[],"confidence":0.5}');

    $service = new AiStructuredExtractionService($provider, new JsonSchemaShapeValidator());

    $result = $service->extract('Vehicle brochure.', $schema, 'Extract vehicle specifications.', [], [
        'schema_name' => 'acme_vehicle_spec_import',
        'temperature' => null,
    ]);

    expect($result->ok)->toBeTrue();
});

test('returns cached successful extraction for the same request hash', function (): void {
    $schema = sampleExtractionSchema();
    $provider = m::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')
        ->once()
        ->andReturn([
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['content' => '{"items":[],"confidence":0.5}'],
                ],
            ],
        ]);
    $provider->shouldReceive('getContent')->once()->andReturn('{"items":[],"confidence":0.5}');

    $service = new AiStructuredExtractionService($provider, new JsonSchemaShapeValidator());

    $first = $service->extract('No Mass schedule here.', $schema, 'Extract Mass schedule rows.', [], [
        'schema_name' => 'sample_module.mass_schedule.v1',
    ]);
    $second = $service->extract('No Mass schedule here.', $schema, 'Extract Mass schedule rows.', [], [
        'schema_name' => 'sample_module.mass_schedule.v1',
    ]);

    expect($first->ok)->toBeTrue()
        ->and($second->ok)->toBeTrue()
        ->and($second->cached)->toBeTrue()
        ->and(AiExtractionRun::query()->count())->toBe(1);
});

test('provider option is stored and isolates request cache hash', function (): void {
    $schema = sampleExtractionSchema();
    $provider = m::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')
        ->twice()
        ->andReturn([
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['content' => '{"items":[],"confidence":0.5}'],
                ],
            ],
        ]);
    $provider->shouldReceive('getContent')->twice()->andReturn('{"items":[],"confidence":0.5}');

    $service = new AiStructuredExtractionService($provider, new JsonSchemaShapeValidator());

    $service->extract('Same source.', $schema, 'Extract Mass schedule rows.', [], [
        'schema_name' => 'sample_module.mass_schedule.v1',
        'provider' => 'deepseek',
        'model' => 'deepseek-v4-flash',
    ]);
    $service->extract('Same source.', $schema, 'Extract Mass schedule rows.', [], [
        'schema_name' => 'sample_module.mass_schedule.v1',
        'provider' => 'gemini',
        'model' => 'deepseek-v4-flash',
    ]);

    expect(AiExtractionRun::query()->pluck('provider')->all())->toContain('deepseek', 'gemini')
        ->and(AiExtractionRun::query()->count())->toBe(2);
});

test('sends reasoning_effort only when it is requested', function (): void {
    $payloads = [];
    $provider = m::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')
        ->twice()
        ->andReturnUsing(function (array $payload) use (&$payloads): array {
            $payloads[] = $payload;

            return ['choices' => [['index' => 0, 'message' => ['content' => '{"items":[],"confidence":0.5}']]]];
        });
    $provider->shouldReceive('getContent')->twice()->andReturn('{"items":[],"confidence":0.5}');

    $service = new AiStructuredExtractionService($provider, new JsonSchemaShapeValidator());

    $service->extract('Same source.', sampleExtractionSchema(), 'Extract Mass schedule rows.', [], [
        'schema_name' => 'sample_module.mass_schedule.v1',
        'reasoning_effort' => 'low',
    ]);
    $service->extract('Another source.', sampleExtractionSchema(), 'Extract Mass schedule rows.', [], [
        'schema_name' => 'sample_module.mass_schedule.v1',
    ]);

    expect($payloads[0]['reasoning_effort'])->toBe('low')
        ->and($payloads[1])->not->toHaveKey('reasoning_effort');
});

test('falls back to the provider configured reasoning_effort and lets the call-site option win', function (): void {
    config()->set('core.ai_structured_extraction.providers.agentic_ai.reasoning_effort', 'minimal');

    $payloads = [];
    $provider = m::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')
        ->twice()
        ->andReturnUsing(function (array $payload) use (&$payloads): array {
            $payloads[] = $payload;

            return ['choices' => [['index' => 0, 'message' => ['content' => '{"items":[],"confidence":0.5}']]]];
        });
    $provider->shouldReceive('getContent')->twice()->andReturn('{"items":[],"confidence":0.5}');

    $service = new AiStructuredExtractionService($provider, new JsonSchemaShapeValidator());

    $service->extract('First source.', sampleExtractionSchema(), 'Extract Mass schedule rows.', [], [
        'schema_name' => 'sample_module.mass_schedule.v1',
    ]);
    $service->extract('Second source.', sampleExtractionSchema(), 'Extract Mass schedule rows.', [], [
        'schema_name' => 'sample_module.mass_schedule.v1',
        'reasoning_effort' => 'high',
    ]);

    expect($payloads[0]['reasoning_effort'])->toBe('minimal')
        ->and($payloads[1]['reasoning_effort'])->toBe('high');
});

test('reasoning_effort isolates the request cache hash per effort and leaves effort-less requests cacheable', function (): void {
    $provider = m::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')
        ->times(3)
        ->andReturn(['choices' => [['index' => 0, 'message' => ['content' => '{"items":[],"confidence":0.5}']]]]);
    $provider->shouldReceive('getContent')->times(3)->andReturn('{"items":[],"confidence":0.5}');

    $service = new AiStructuredExtractionService($provider, new JsonSchemaShapeValidator());
    $call = static fn (array $extra = []) => $service->extract('Same source.', sampleExtractionSchema(), 'Extract Mass schedule rows.', [], [
        'schema_name' => 'sample_module.mass_schedule.v1',
        ...$extra,
    ]);

    $plain = $call();
    $low = $call(['reasoning_effort' => 'low']);
    $high = $call(['reasoning_effort' => 'high']);
    $lowAgain = $call(['reasoning_effort' => 'low']);
    $plainAgain = $call();

    expect($plain->cached)->toBeFalse()
        ->and($low->cached)->toBeFalse()
        ->and($high->cached)->toBeFalse()
        ->and($lowAgain->cached)->toBeTrue()
        ->and($plainAgain->cached)->toBeTrue()
        ->and(AiExtractionRun::query()->count())->toBe(3)
        ->and(AiExtractionRun::query()->distinct()->pluck('request_hash'))->toHaveCount(3);
});

test('fails and records validation errors when AI output does not match schema', function (): void {
    $schema = sampleExtractionSchema();
    $provider = m::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')
        ->once()
        ->andReturn([
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['content' => '{"items":[{"day":"sunday","time":600,"evidence":"bad"}],"confidence":0.8}'],
                ],
            ],
        ]);
    $provider->shouldReceive('getContent')
        ->once()
        ->andReturn('{"items":[{"day":"sunday","time":600,"evidence":"bad"}],"confidence":0.8}');

    $service = new AiStructuredExtractionService($provider, new JsonSchemaShapeValidator());

    $result = $service->extract('Giờ lễ Chúa Nhật 06:00', $schema, 'Extract Mass schedule rows.', [], [
        'schema_name' => 'sample_module.mass_schedule.v1',
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe('failed')
        ->and($result->errors[0])->toContain('$.items[0].time')
        ->and(AiExtractionRun::query()->firstOrFail()->status)->toBe('failed');
});

test('marks extraction run failed when provider request throws', function (): void {
    $schema = sampleExtractionSchema();
    $provider = m::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')
        ->once()
        ->andThrow(new RuntimeException('provider unavailable'));
    $provider->shouldReceive('getContent')->never();

    $service = new AiStructuredExtractionService($provider, new JsonSchemaShapeValidator());

    $result = $service->extract('Giờ lễ Chúa Nhật 06:00', $schema, 'Extract Mass schedule rows.', [], [
        'schema_name' => 'sample_module.mass_schedule.v1',
    ]);

    $run = AiExtractionRun::query()->firstOrFail();

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe('failed')
        ->and($result->errors[0])->toContain('provider unavailable')
        ->and($run->status)->toBe('failed')
        ->and($run->error_message)->toContain('provider unavailable')
        ->and($run->response_payload['error']['type'])->toBe(RuntimeException::class);
});

test('extracts structured data from a file with exact multimodal request controls', function (): void {
    $schema = sampleExtractionSchema();
    $fileDataUri = 'data:application/pdf;base64,' . base64_encode('file request payload');
    $provider = m::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')
        ->once()
        ->withArgs(function (array $payload) use ($fileDataUri, $schema): bool {
            expect($payload['messages'])->toBe([
                [
                    'role' => 'system',
                    'content' => 'Extract structured data.',
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'file',
                            'file' => [
                                'filename' => 'vehicle-spec.pdf',
                                'file_data' => $fileDataUri,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => "TASK INSTRUCTIONS:\nExtract vehicle rows.\n\nSAFE CONTEXT JSON:\n{\"module\":\"Acme\",\"source_label\":\"Dealer brochure\"}\n\nATTACHED SOURCE:\nsha256:safe-pdf-fingerprint",
                        ],
                    ],
                ],
            ])->and($payload['response_format']['json_schema']['schema'])->toBe($schema)
                ->and($payload['request_timeout_seconds'])->toBe(120.0)
                ->and($payload['request_connect_timeout_seconds'])->toBe(10.0)
                ->and($payload['request_max_attempts'])->toBe(1)
                ->and($payload['store'])->toBeFalse()
                ->and($payload)->not->toHaveKey('temperature');

            return true;
        })
        ->andReturn([
            'choices' => [[
                'index' => 0,
                'finish_reason' => 'stop',
                'message' => ['content' => '{"items":[],"confidence":0.95}'],
            ]],
        ]);
    $provider->shouldReceive('getContent')->once()->andReturn('{"items":[],"confidence":0.95}');

    $service = new AiStructuredExtractionService($provider, new JsonSchemaShapeValidator());

    $result = $service->extractFile(
        fileDataUri: $fileDataUri,
        filename: 'vehicle-spec.pdf',
        sourceDescriptor: 'sha256:safe-pdf-fingerprint',
        schema: $schema,
        instructions: 'Extract vehicle rows.',
        context: ['module' => 'Acme', 'source_label' => 'Dealer brochure'],
        options: [
            'schema_name' => 'acme_vehicle_spec_import',
            'temperature' => null,
            'request_timeout_seconds' => 120,
            'request_connect_timeout_seconds' => 10,
            'request_max_attempts' => 1,
            'store' => true,
        ],
    );

    expect($result->ok)->toBeTrue()
        ->and($result->data)->toBe(['items' => [], 'confidence' => 0.95]);
});

test('stores only safe file audit metadata and caller byte overrides', function (): void {
    $schema = sampleExtractionSchema();
    $canary = 'FILE_CANARY_' . bin2hex(random_bytes(8));
    $alternateCanary = 'FILE_ALT_CANARY_' . bin2hex(random_bytes(8));
    $fileDataUri = 'data:application/pdf;base64,' . base64_encode($canary);
    $alternateFileDataUri = 'data:application/pdf;base64,' . base64_encode($alternateCanary);
    $provider = m::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')->twice()->andReturn([
        'choices' => [[
            'index' => 0,
            'message' => ['content' => '{"items":[],"confidence":0.5}'],
        ]],
    ]);
    $provider->shouldReceive('getContent')->twice()->andReturn('{"items":[],"confidence":0.5}');

    $service = new AiStructuredExtractionService($provider, new JsonSchemaShapeValidator());
    $result = $service->extractFile(
        $fileDataUri,
        'vehicle-spec.pdf',
        'sha256:safe-audit-fingerprint',
        $schema,
        'Extract private vehicle rows.',
        ['module' => 'Acme', 'source_label' => 'Safe brochure label'],
        [
            'schema_name' => 'acme_vehicle_spec_import',
            'input_bytes' => 12345,
            'prompt_bytes' => 678,
        ],
    );

    $cachedResult = $service->extractFile(
        $alternateFileDataUri,
        'vehicle-spec.pdf',
        'sha256:safe-audit-fingerprint',
        $schema,
        'Extract private vehicle rows.',
        ['module' => 'Acme', 'source_label' => 'Safe brochure label'],
        [
            'schema_name' => 'acme_vehicle_spec_import',
            'input_bytes' => 12345,
            'prompt_bytes' => 678,
        ],
    );
    $differentSourceResult = $service->extractFile(
        $fileDataUri,
        'vehicle-spec.pdf',
        'sha256:different-safe-audit-fingerprint',
        $schema,
        'Extract private vehicle rows.',
        ['module' => 'Acme', 'source_label' => 'Safe brochure label'],
        [
            'schema_name' => 'acme_vehicle_spec_import',
            'input_bytes' => 12345,
            'prompt_bytes' => 678,
        ],
    );

    $run = AiExtractionRun::query()->firstOrFail();
    $attributesJson = json_encode(
        AiExtractionRun::query()->get()->map->getAttributes()->all(),
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );

    expect($result->inputHash)->toBe(hash('sha256', 'sha256:safe-audit-fingerprint'))
        ->and($cachedResult->cached)->toBeTrue()
        ->and($cachedResult->requestHash)->toBe($result->requestHash)
        ->and($differentSourceResult->requestHash)->not->toBe($result->requestHash)
        ->and(AiExtractionRun::query()->count())->toBe(2)
        ->and($run->input_bytes)->toBe(12345)
        ->and($run->prompt_bytes)->toBe(678)
        ->and($attributesJson)->not->toContain($canary)
        ->and($attributesJson)->not->toContain($alternateCanary)
        ->and($attributesJson)->not->toContain($fileDataUri)
        ->and($attributesJson)->not->toContain($alternateFileDataUri)
        ->and($attributesJson)->not->toContain('data:application/pdf;base64,')
        ->and($attributesJson)->not->toContain('file_data')
        ->and($attributesJson)->not->toContain('Extract private vehicle rows.')
        ->and($attributesJson)->not->toContain('messages');
});

test('redacts file provider exception details from result and run', function (): void {
    $schema = sampleExtractionSchema();
    $canary = 'FILE_EXCEPTION_CANARY_' . bin2hex(random_bytes(8));
    $fileDataUri = 'data:application/pdf;base64,' . base64_encode($canary);
    $provider = m::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')
        ->once()
        ->andThrow(new RuntimeException("provider echoed {$canary} {$fileDataUri}"));
    $provider->shouldReceive('getContent')->never();

    $service = new AiStructuredExtractionService($provider, new JsonSchemaShapeValidator());
    $result = $service->extractFile(
        $fileDataUri,
        'vehicle-spec.pdf',
        'sha256:safe-error-fingerprint',
        $schema,
        'Extract vehicle rows.',
    );

    $run = AiExtractionRun::query()->firstOrFail();
    $attributesJson = json_encode($run->getAttributes(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    expect($result->ok)->toBeFalse()
        ->and($result->data)->toBeNull()
        ->and($result->errors)->toBe(['AI provider request failed.'])
        ->and($run->status)->toBe('failed')
        ->and($run->error_message)->toBe('AI provider request failed.')
        ->and($attributesJson)->not->toContain($canary)
        ->and($attributesJson)->not->toContain($fileDataUri)
        ->and($attributesJson)->not->toContain('provider echoed');
});

test('treats file refusal and truncation responses as terminal before content parsing', function (array $response, string $expectedError): void {
    $schema = sampleExtractionSchema();
    $canary = 'FILE_TERMINAL_CANARY';
    $response['choices'][0]['message']['content'] = '{"items":[{"day":"sunday","time":"06:00","evidence":"' . $canary . '"}]}';
    $provider = m::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')->once()->andReturn($response);
    $provider->shouldReceive('getContent')->never();

    $service = new AiStructuredExtractionService($provider, new JsonSchemaShapeValidator());
    $result = $service->extractFile(
        'data:application/pdf;base64,' . base64_encode($canary),
        'vehicle-spec.pdf',
        'sha256:safe-terminal-fingerprint-' . hash('sha256', $expectedError),
        $schema,
        'Extract vehicle rows.',
    );

    $run = AiExtractionRun::query()->firstOrFail();
    $attributesJson = json_encode($run->getAttributes(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    expect($result->ok)->toBeFalse()
        ->and($result->data)->toBeNull()
        ->and($result->errors)->toBe([$expectedError])
        ->and($run->status)->toBe('failed')
        ->and($run->parsed_payload)->toBeNull()
        ->and($attributesJson)->not->toContain($canary)
        ->and($run->response_payload['choices'][0]['message'])->not->toHaveKey('content')
        ->and($run->response_payload['choices'][0]['message']['content_bytes'])->toBeGreaterThan(0);
})->with([
    'refusal' => [
        [
            'choices' => [[
                'index' => 0,
                'finish_reason' => 'stop',
                'message' => ['refusal' => 'Cannot process FILE_TERMINAL_CANARY'],
            ]],
        ],
        'AI provider refused extraction.',
    ],
    'length' => [
        [
            'choices' => [[
                'index' => 0,
                'finish_reason' => 'length',
                'message' => [],
            ]],
        ],
        'AI provider response was truncated.',
    ],
]);

test('redacts array-valued provider content from persisted file runs', function (): void {
    $schema = sampleExtractionSchema();
    $canary = 'ARRAY_CONTENT_CANARY_' . bin2hex(random_bytes(8));
    $provider = m::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')->once()->andReturn([
        'choices' => [[
            'index' => 0,
            'finish_reason' => 'stop',
            'message' => [
                'content' => [[
                    'type' => 'output_text',
                    'text' => $canary,
                ]],
            ],
        ]],
    ]);
    $provider->shouldReceive('getContent')->once()->andReturn('{"items":[],"confidence":0.5}');

    $service = new AiStructuredExtractionService($provider, new JsonSchemaShapeValidator());
    $result = $service->extractFile(
        'data:application/pdf;base64,' . base64_encode('array-content-file'),
        'vehicle-spec.pdf',
        'sha256:safe-array-content-fingerprint',
        $schema,
        'Extract vehicle rows.',
    );

    $run = AiExtractionRun::query()->firstOrFail();
    $attributesJson = json_encode($run->getAttributes(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $messageAudit = $run->response_payload['choices'][0]['message'];

    expect($result->ok)->toBeTrue()
        ->and($attributesJson)->not->toContain($canary)
        ->and($messageAudit)->not->toHaveKey('content')
        ->and($messageAudit['content_present'])->toBeTrue()
        ->and($messageAudit['content_type'])->toBe('array')
        ->and($messageAudit['content_bytes'])->toBeGreaterThan(0);
});

function sampleExtractionSchema(): array
{
    return [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['items', 'confidence'],
        'properties' => [
            'items' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['day', 'time', 'evidence'],
                    'properties' => [
                        'day' => [
                            'type' => 'string',
                            'enum' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                        ],
                        'time' => ['type' => 'string'],
                        'evidence' => ['type' => 'string'],
                    ],
                ],
            ],
            'confidence' => ['type' => 'number'],
        ],
    ];
}

test('reports an empty provider response as a failed request, not as invalid JSON', function (): void {
    $provider = m::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')->once()->andReturn([]);
    $provider->shouldReceive('getContent')->never();

    $service = new AiStructuredExtractionService($provider, new JsonSchemaShapeValidator());
    $result = $service->extract('Giờ lễ: Chúa Nhật 06:00', sampleExtractionSchema(), 'Extract Mass schedule rows.', [], [
        'schema_name' => 'empty_response_check',
        'cache_enabled' => false,
    ]);

    $run = AiExtractionRun::query()->firstOrFail();

    expect($result->ok)->toBeFalse()
        ->and($result->errors)->toBe(['AI provider returned no response (request failed, see the provider log).'])
        ->and($run->status)->toBe('failed')
        ->and($run->error_message)->not->toContain('not valid JSON');
});
