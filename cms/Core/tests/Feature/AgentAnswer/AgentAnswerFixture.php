<?php

declare(strict_types=1);

/*
 * Shared arrangement for the agent answer API tests: one approved published post, the fixed profile with
 * its single grant, explicit provider task settings, a recording fake provider and a user holding
 * `agent_profile.answer`. Loaded with require_once; not a test file.
 */

use App\Enums\StatusEnum;
use App\Models\User;
use Cms\Content\Models\Post;
use Cms\Content\Services\Agentic\AgentReadablePostProjector;
use Cms\Content\Services\Agentic\PostAnswerToolService;
use Cms\Core\Agents\Contracts\AgenticAiProviderInterface;
use Cms\Core\Agents\Tools\AgentToolRegistry;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Setting;
use Cms\Core\Services\Agentic\AgentToolProvisioner;
use Cms\Core\Services\Ai\AiRuntimeConfigResolver;
use Cms\Core\Services\Ai\AiTaskRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Nwidart\Modules\Contracts\ActivatorInterface;

// Absolute https URL: the global HttpsProtocol middleware redirects plain-HTTP requests outside `local`.
const AGENT_ANSWER_URL = 'https://localhost/api/v2/public/agent/answers';
const AGENT_ANSWER_POST_UUID = '11111111-1111-4111-8111-111111111111';
const AGENT_ANSWER_QUOTE = 'ABS giúp bánh xe không bị bó cứng.';

final class AgentAnswerFakeProvider implements AgenticAiProviderInterface
{
    /** @var list<array<string, mixed>> */
    public array $payloads = [];

    public function __construct(public string $content = '', public bool $throws = false)
    {
    }

    public function createCompletion(array $payload = []): array
    {
        $this->payloads[] = $payload;
        if ($this->throws) {
            throw new RuntimeException('provider-secret');
        }

        return ['choices' => [['message' => ['content' => $this->content]]]];
    }

    public function getContent(array $response = []): string
    {
        return (string) data_get($response, 'choices.0.message.content', '');
    }
}

/** Arrange the whole pilot path and return the fake provider. */
function agentAnswerArrange(string $answer = 'ABS giúp tránh bó cứng bánh xe.', string $title = 'ABS là gì?'): AgentAnswerFakeProvider
{
    config()->set([
        'core.agent_answers.enabled' => true,
        'core.agent_answers.allowed_projects' => ['TestProject'],
        'core.agent_answers.allowed_providers' => ['openai', 'gemini', 'grok', 'deepseek'],
        'core.agent_answers.rate_limit_per_minute' => 10,
        'ispa-cms.folder_name' => 'TestProject',
        'ispa-cms.skip_languages_query' => true,
        'core.agentic.kill_switch' => false,
        'core.agentic.discovery.default_environment' => 'testing',
    ]);
    agentApiModuleActivator(contentEnabled: true);

    $now = '2026-09-05 01:00:00';
    // posts.language_id and posts.status are foreign keys: language 0 is the "current language" while the languages
    // table is skipped, and the published status lives in enumerates.
    DB::table('languages')->insertOrIgnore(['id' => 0, 'name' => 'Tiếng Việt', 'code' => 'vi', 'path' => 'vi']);
    DB::table('enumerates')->insertOrIgnore(['name' => 'Published', 'enum_key' => StatusEnum::PUBLISHED->value, 'type' => 'status']);
    $id = (static fn () => DB::table('posts')->insertGetId([
        'uuid' => AGENT_ANSWER_POST_UUID,
        'name' => $title,
        'description' => 'Giải thích ngắn.',
        'content' => json_encode([['type' => 'text', 'value' => AGENT_ANSWER_QUOTE]], JSON_UNESCAPED_UNICODE),
        'type' => 'post',
        'language_id' => 0,
        'status' => StatusEnum::PUBLISHED->value,
        'published_at' => '2026-09-04 08:00:00',
        'created_at' => $now,
        'updated_at' => $now,
    ]))();
    $post = Post::query()->withoutGlobalScope(Post::APPENDED_RELATIONS_SCOPE)->with(['categories.slugs', 'language'])->findOrFail($id);
    config()->set('content.agent_answers.approved_posts', [
        AGENT_ANSWER_POST_UUID => app(AgentReadablePostProjector::class)->project($post)['content_digest'],
    ]);

    foreach (['provider' => 'openai', 'model' => 'gpt-answer-test'] as $suffix => $value) {
        Setting::query()->create([
            'setting_key' => 'core.ai_runtime.tasks.content_agent_answer.' . $suffix,
            'type' => 'text',
            'group_name' => 'core.ai_runtime',
            'name' => 'content_agent_answer.' . $suffix,
            'input_setting' => ['title' => $suffix, 'isPublic' => true, 'isEnv' => false],
            'value' => $value,
        ]);
    }

    $provider = new AgentAnswerFakeProvider(json_encode([
        'status' => 'answered',
        'answer' => $answer,
        'conflict' => false,
        'references' => [[
            'document_id' => 'urn:ispa:content:' . AGENT_ANSWER_POST_UUID,
            'section_id' => 'body-1',
            'quote' => AGENT_ANSWER_QUOTE,
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $resolver = new AiRuntimeConfigResolver();
    app()->instance(AiRuntimeConfigResolver::class, $resolver);
    app()->instance(AiTaskRuntime::class, new AiTaskRuntime($resolver, null, $provider));

    app(AgentToolRegistry::class)->syncDiscoveredService(PostAnswerToolService::class, 'content', 'testing');
    $provisioner = app(AgentToolProvisioner::class);
    $provisioner->createProfile([
        'name' => 'Public Content Assistant',
        'slug' => 'public-content-assistant',
        'capability_class' => 'class_0_read_only',
        'status' => 'active',
    ]);
    $provisioner->grantToolsToProfile('public-content-assistant', ['content.answer_posts']);

    return $provider;
}

/** A plain user; with $canAnswer the user holds `agent_profile.answer` through one role. */
function agentAnswerUser(bool $canAnswer = true): User
{
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'Answer user',
        'username' => 'answer-' . Str::lower(Str::random(6)),
        'email' => 'answer-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => 0,
        'level' => 0,
        'status' => null,
    ]);

    if ($canAnswer) {
        $role = Role::query()->create(['name' => 'Answer role ' . Str::random(6), 'custom' => ['level' => 50]]);
        $permission = Permission::query()->firstOrCreate(['group' => 'agent_profile', 'name' => 'answer'], ['title' => 'answer']);
        $role->permissions()->attach($permission);
        $user->roles()->attach($role);
    }

    return $user;
}

/** Activator stub: Content follows $contentEnabled, every other module stays enabled. */
function agentApiModuleActivator(bool $contentEnabled): void
{
    $activator = Mockery::mock(ActivatorInterface::class);
    $activator->shouldReceive('hasStatus')
        ->zeroOrMoreTimes()
        ->andReturnUsing(static fn ($module, bool $status): bool => strcasecmp((string) $module, 'Content') === 0
            ? $contentEnabled === $status
            : $status);
    $activator->shouldIgnoreMissing();
    app()->instance(ActivatorInterface::class, $activator);
}

/** Every value of every row of every table, as one JSON string per table. @return array<string, string> */
function agentAnswerAllRows(): array
{
    $dump = [];
    foreach (\Illuminate\Support\Facades\Schema::getTableListing() as $table) {
        $name = (string) preg_replace('/^.*\./', '', (string) $table);
        $dump[$name] = json_encode(DB::table($name)->get(), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    return $dump;
}

/** Rename the approved post and approve its new digest. */
function agentAnswerRetitle(string $title): void
{
    DB::table('posts')->where('uuid', AGENT_ANSWER_POST_UUID)->update(['name' => $title]);
    $post = Post::query()->withoutGlobalScope(Post::APPENDED_RELATIONS_SCOPE)->with(['categories.slugs', 'language'])
        ->where('uuid', AGENT_ANSWER_POST_UUID)->firstOrFail();
    config()->set('content.agent_answers.approved_posts', [
        AGENT_ANSWER_POST_UUID => app(AgentReadablePostProjector::class)->project($post)['content_digest'],
    ]);
}

/**
 * Replace every post with the given published posts (single text block each) and approve exactly their digests.
 *
 * @param list<array{title: string, text: string}> $posts
 * @return list<array<string, mixed>> Projection of each post, in the given order (post i has uuid agentAnswerUuid(i)).
 */
function agentAnswerSeedPosts(array $posts): array
{
    DB::table('posts')->delete();
    $projections = [];
    $approved = [];
    foreach (array_values($posts) as $index => $post) {
        $id = DB::table('posts')->insertGetId([
            'uuid' => agentAnswerUuid($index),
            'name' => $post['title'],
            'description' => null,
            'content' => json_encode([['type' => 'text', 'value' => $post['text']]], JSON_UNESCAPED_UNICODE),
            'type' => 'post',
            'language_id' => 0,
            'status' => StatusEnum::PUBLISHED->value,
            'published_at' => '2026-09-0' . (1 + $index % 5) . ' 08:00:00',
            'created_at' => '2026-09-01 01:00:00',
            'updated_at' => '2026-09-05 01:00:00',
        ]);
        $model = Post::query()->withoutGlobalScope(Post::APPENDED_RELATIONS_SCOPE)->with(['categories.slugs', 'language'])->findOrFail($id);
        $projections[$index] = app(AgentReadablePostProjector::class)->project($model);
        $approved[agentAnswerUuid($index)] = $projections[$index]['content_digest'];
    }
    config()->set('content.agent_answers.approved_posts', $approved);

    return $projections;
}

/** Deterministic UUID of the i-th seeded evaluation post. */
function agentAnswerUuid(int $index): string
{
    return sprintf('%08d-0000-4000-8000-%012d', $index + 1, $index + 1);
}
